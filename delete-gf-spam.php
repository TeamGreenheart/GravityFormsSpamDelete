<?php
/**
 * Plugin Name: Delete Gravity Forms Spam
 * Description: Delete Gravity Forms spam entries based on field value filters.
 * Version: 1.1.0
 * Author: Ben Toth
 */

$SHOW_TEST_IMPORT_LOGIC = false; // Set to false in production

add_action('admin_menu', function () {
    add_submenu_page(
        'tools.php',
        'GF Spam Cleaner',
        'GF Spam Cleaner',
        'manage_options',
        'gf-spam-cleaner',
        'gf_spam_cleaner_page'
    );
});

function gf_spam_cleaner_get_config() {
    return get_option('gf_spam_cleaner_config', [
        'form_id' => '',
        'criteria' => [],
        'logic' => 'AND', // AND or OR
        'field_mapping' => [], // For CSV import mapping
    ]);
}

function gf_spam_cleaner_save_config($data) {
    update_option('gf_spam_cleaner_config', $data);
}

function gf_spam_cleaner_preview_matches($config, $limit = 50) {
    if (empty($config['criteria']) || empty($config['form_id'])) {
        return [];
    }

    $matches = [];
    $page = 1;
    $page_size = 500; // Process in smaller chunks
    
    do {
        $entries = GFAPI::get_entries(
            $config['form_id'], 
            ['status' => 'active'], 
            null, 
            ['page_size' => $page_size, 'offset' => ($page - 1) * $page_size]
        );
        
        foreach ($entries as $entry) {
            if (gf_spam_cleaner_entry_matches($entry, $config['criteria'], $config['logic'])) {
                $matches[] = $entry;
                if (count($matches) >= $limit) {
                    return $matches;
                }
            }
        }
        
        $page++;
    } while (count($entries) == $page_size); // Continue if we got a full page
    
    return $matches;
}

function gf_spam_cleaner_entry_matches($entry, $criteria, $logic = 'AND') {
    if (empty($criteria)) return false;
    
    $matches = [];
    
    foreach ($criteria as $rule) {
        $field_value = isset($entry[$rule['field_id']]) ? $entry[$rule['field_id']] : '';
        
        // Special handling for 'blank' keyword
        if (strtolower(trim($rule['value'])) === 'blank') {
            // Match if field is empty, null, or only whitespace
            $matches[] = (empty(trim($field_value)));
        } else {
            // Regular exact match
            $matches[] = ($field_value == $rule['value']);
        }
    }
    
    if ($logic === 'AND') {
        return !in_array(false, $matches); // All must be true
    } else {
        return in_array(true, $matches); // At least one must be true
    }
}

function gf_spam_cleaner_delete_matching_entries($config) {
    if (empty($config['criteria']) || empty($config['form_id'])) {
        return ['count' => 0, 'debug' => 'No criteria or form ID'];
    }

    $deleted_count = 0;
    $debug_info = [];
    $batch_size = 1000; // I had to do huge batches to get all my spam because of partial entries
    $max_deletions_per_run = 1000;
    $batches_processed = 0;
    $max_batches = 50; // Don't process forever
    
    while ($deleted_count < $max_deletions_per_run && $batches_processed < $max_batches) {
        $entries = GFAPI::get_entries(
            $config['form_id'], 
            ['status' => 'active'], 
            null, 
            ['page_size' => $batch_size, 'offset' => $batches_processed] 
        );
        
        if (empty($entries)) {
            $debug_info[] = "No more entries found after {$batches_processed} batches";
            break;
        }
        
        $batch_deletions = 0;
        $debug_info[] = "Batch {$batches_processed}: Processing " . count($entries) . " entries";
        
        $Flagged = [];
        foreach ($entries as $entry) {
            if (gf_spam_cleaner_entry_matches($entry, $config['criteria'], $config['logic'])) {
                $Flagged[] = $entry;
            }
        }
        foreach ($Flagged as $entry) {
            $result = GFAPI::delete_entry($entry['id']);
            if (!is_wp_error($result)) {
                $deleted_count++;
                $batch_deletions++;
                $debug_info[] = "✓ Deleted entry {$entry['id']}";
            } else {
                $debug_info[] = "✗ Failed to delete entry {$entry['id']}: " . $result->get_error_message();
            }
            
            // Break after a few deletions to get fresh batch
            if ($batch_deletions >= 10) {
                break;
            }
            
        }
        
        $debug_info[] = "Batch {$batches_processed}: Deleted {$batch_deletions} entries";
        $batches_processed++;

    }
    
    return ['count' => $deleted_count, 'debug' => $debug_info];
}

function gf_spam_cleaner_parse_csv($file_path) {
    if (!file_exists($file_path)) {
        return ['error' => 'File not found'];
    }
    
    $data = [];
    $headers = [];
    
    if (($handle = fopen($file_path, "r")) !== FALSE) {
        $row_count = 0;
        while (($row = fgetcsv($handle, 0, ",")) !== FALSE) {
            if ($row_count === 0) {
                $headers = $row;
            } else {
                if (count($row) === count($headers)) {
                    $data[] = array_combine($headers, $row);
                }
            }
            $row_count++;
        }
        fclose($handle);
    }
    
    return ['headers' => $headers, 'data' => $data];
}

function gf_spam_cleaner_import_csv_entries($form_id, $csv_data, $field_mapping) {
    $import_count = 0;
    $errors = [];
    
    foreach ($csv_data as $row) {
        $entry = ['form_id' => $form_id];
        
        // Map CSV columns to form fields
        foreach ($field_mapping as $csv_column => $field_id) {
            if (!empty($field_id) && isset($row[$csv_column])) {
                $entry[$field_id] = $row[$csv_column];
            }
        }
        
        // Add the entry
        $result = GFAPI::add_entry($entry);
        
        if (is_wp_error($result)) {
            $errors[] = "Row " . ($import_count + 1) . ": " . $result->get_error_message();
        } else {
            $import_count++;
        }
        
        // Limit to prevent timeouts
        if ($import_count > 1000) {
            $errors[] = "Import limited to 1000 entries to prevent timeout";
            break;
        }
    }
    
    return ['imported' => $import_count, 'errors' => $errors];
}

function show_test_import_logic(){
    ?>
        <hr>
            
        <h2>CSV Import (For Testing)</h2>
        <p><em>Upload a Gravity Forms export CSV to import entries for testing spam deletion locally.</em></p>
        
        <form method="post" enctype="multipart/form-data">
            <h3>Step 1: Upload CSV</h3>
            <p>
                <input type="file" name="csv_file" accept=".csv" required>
                <button type="submit" name="gf_spam_upload" class="button">Upload & Preview CSV</button>
            </p>
        </form>

        <?php if ($csv_data): ?>
            <form method="post" enctype="multipart/form-data">
                <h3>Step 2: Map Fields</h3>
                <p>Map CSV columns to form field IDs:</p>
                <table class="widefat">
                    <thead>
                        <tr><th>CSV Column</th><th>Sample Data</th><th>Map to Field ID</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($csv_data['headers'] as $i => $header): ?>
                            <tr>
                                <td><strong><?= esc_html($header) ?></strong></td>
                                <td><?= esc_html(isset($csv_data['data'][0][$header]) ? substr($csv_data['data'][0][$header], 0, 50) : '') ?></td>
                                <td>
                                    <input type="text" name="field_mapping[<?= esc_attr($header) ?>]" placeholder="Field ID (e.g., 1, 2, 3)" style="width: 100px;">
                                    <input type="hidden" name="csv_headers[]" value="<?= esc_attr($header) ?>">
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <p><em>Common mappings: Entry ID = id, First Name = field ID from your form, etc.</em></p>
                <p>
                    <input type="file" name="csv_file_import" accept=".csv" required>
                    <em>(Re-upload the same CSV file)</em>
                </p>
                <p><button type="submit" name="gf_spam_import" class="button-primary">Import Entries</button></p>
            </form>
        <?php endif; ?>

        <?php if ($import_results): ?>
            <div class="notice notice-info">
                <p><strong>Import Results:</strong></p>
                <p>Imported: <?= esc_html($import_results['imported']) ?> entries</p>
                <?php if (!empty($import_results['errors'])): ?>
                    <p>Errors:</p>
                    <ul>
                        <?php foreach (array_slice($import_results['errors'], 0, 10) as $error): ?>
                            <li><?= esc_html($error) ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
<?php
}

function gf_spam_cleaner_page() {
    if (!current_user_can('manage_options')) {
        wp_die('Unauthorized');
    }

    $config = gf_spam_cleaner_get_config();
    $message = '';
    $preview_entries = [];
    $csv_data = null;
    $import_results = null;

    // Save settings
    if (isset($_POST['gf_spam_save'])) {
        $form_id = intval($_POST['form_id']);
        $logic = sanitize_text_field($_POST['logic']);
        $criteria = [];

        if (!empty($_POST['field_id']) && !empty($_POST['match_value'])) {
            foreach ($_POST['field_id'] as $i => $field_id) {
                if (trim($field_id) !== '' && trim($_POST['match_value'][$i]) !== '') {
                    $criteria[] = [
                        'field_id' => sanitize_text_field($field_id),
                        'value'    => sanitize_text_field($_POST['match_value'][$i]),
                    ];
                }
            }
        }

        $config = ['form_id' => $form_id, 'criteria' => $criteria, 'logic' => $logic];
        gf_spam_cleaner_save_config($config);
        $message = 'Settings saved.';
    }

    // Handle CSV upload and preview
    if (isset($_POST['gf_spam_upload'])) {
        if (isset($_FILES['csv_file']) && $_FILES['csv_file']['error'] === UPLOAD_ERR_OK) {
            $csv_result = gf_spam_cleaner_parse_csv($_FILES['csv_file']['tmp_name']);
            if (isset($csv_result['error'])) {
                $message = 'Error: ' . $csv_result['error'];
            } else {
                $csv_data = $csv_result;
                $message = 'CSV uploaded successfully. Found ' . count($csv_data['data']) . ' rows.';
            }
        } else {
            $message = 'Error uploading file.';
        }
    }

    // Handle CSV import
    if (isset($_POST['gf_spam_import'])) {
        if (!empty($_POST['csv_headers']) && !empty($_POST['field_mapping'])) {
            // Reconstruct CSV data from session or re-upload
            if (isset($_FILES['csv_file_import']) && $_FILES['csv_file_import']['error'] === UPLOAD_ERR_OK) {
                $csv_result = gf_spam_cleaner_parse_csv($_FILES['csv_file_import']['tmp_name']);
                if (!isset($csv_result['error'])) {
                    $field_mapping = array_filter($_POST['field_mapping']);
                    $import_results = gf_spam_cleaner_import_csv_entries($config['form_id'], $csv_result['data'], $field_mapping);
                    $message = "Import completed. Imported: {$import_results['imported']} entries.";
                    if (!empty($import_results['errors'])) {
                        $message .= " Errors: " . implode(', ', array_slice($import_results['errors'], 0, 5));
                    }
                }
            } else {
                $message = 'Please re-upload the CSV file for import.';
            }
        }
    }

    // Preview matches
    if (isset($_POST['gf_spam_preview'])) {
        $preview_entries = gf_spam_cleaner_preview_matches($config, 50);
        $message = 'Found ' . count($preview_entries) . ' matching entries (showing first 50).';
    }

    // Delete matching entries
    if (isset($_POST['gf_spam_delete'])) {
        $result = gf_spam_cleaner_delete_matching_entries($config);
        $match_count = $result['count'];
        $message = "Deleted $match_count matching entries.";
        
        // Show debug info
        if (isset($result['debug']) && is_array($result['debug'])) {
            $message .= "<br><br><strong>Debug Info:</strong><br>" . implode('<br>', array_slice($result['debug'], 0, 20));
        }
    }

    // UI
    ?>
    <div class="wrap">
        <h1>GF Spam Cleaner</h1>
        <?php if ($message): ?>
            <div class="notice notice-success"><p><?= esc_html($message) ?></p></div>
        <?php endif; ?>

        <form method="post">
            <h2>Spam Filter Settings</h2>
            <table class="form-table">
                <tr>
                    <th scope="row"><label for="form_id">Gravity Form ID</label></th>
                    <td><input type="number" name="form_id" id="form_id" value="<?= esc_attr($config['form_id']) ?>" required></td>
                </tr>
                <tr>
                    <th scope="row"><label for="logic">Match Logic</label></th>
                    <td>
                        <select name="logic" id="logic">
                            <option value="AND" <?= selected($config['logic'], 'AND', false) ?>>ALL criteria must match (AND)</option>
                            <option value="OR" <?= selected($config['logic'], 'OR', false) ?>>ANY criteria can match (OR)</option>
                        </select>
                        <p class="description">AND = safer, OR = more aggressive</p>
                    </td>
                </tr>
            </table>

            <h3>Field Match Rules</h3>
            <p class="description">Use "blank" as the match value to detect empty/null fields.</p>
            <table class="widefat" id="criteria-table">
                <thead>
                    <tr><th>Field ID</th><th>Match Value</th><th>Remove</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($config['criteria'] as $row): ?>
                        <tr>
                            <td><input name="field_id[]" value="<?= esc_attr($row['field_id']) ?>" required></td>
                            <td><input name="match_value[]" value="<?= esc_attr($row['value']) ?>" placeholder="exact value or 'blank'" required></td>
                            <td><button type="button" class="remove-row button">×</button></td>
                        </tr>
                    <?php endforeach; ?>
                    <tr>
                        <td><input name="field_id[]" placeholder="e.g. 1" /></td>
                        <td><input name="match_value[]" placeholder="exact value or 'blank'" /></td>
                        <td><button type="button" class="remove-row button">×</button></td>
                    </tr>
                </tbody>
            </table>
            <p><button type="button" class="button" id="add-criteria">Add Row</button></p>
            <p>
                <button type="submit" name="gf_spam_save" class="button-primary">Save Settings</button>
                <button type="submit" name="gf_spam_preview" class="button">Preview Matches</button>
            </p>
        </form>

        <?php if (!empty($preview_entries)): ?>
            <h3>Preview of Matching Entries</h3>
            <table class="widefat">
                <thead>
                    <tr><th>ID</th><th>Date</th><th>Criteria Fields</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($preview_entries as $entry): ?>
                        <tr>
                            <td><?= esc_html($entry['id']) ?></td>
                            <td><?= esc_html($entry['date_created']) ?></td>
                            <td>
                                <?php 
                                foreach ($config['criteria'] as $rule) {
                                    $field_id = $rule['field_id']; 
                                    $field_value = isset($entry[$field_id]) ? $entry[$field_id] : '';
                                    $display_value = strlen($field_value) > 50 ? substr($field_value, 0, 50) . '...' : $field_value;
                                    echo '<strong>Field ' . esc_html($field_id) . ':</strong> ' . esc_html($display_value) . '<br>';
                                }
                                ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <form method="post" onsubmit="return confirm('Are you sure you want to delete matching entries? This cannot be undone!');">
            <p><button type="submit" name="gf_spam_delete" class="button button-danger" style="background: #dc3232; color: white;">Delete Matching Entries</button></p>
        </form>

   
        <?php
        if ($SHOW_TEST_IMPORT_LOGIC) {
            show_test_import_logic();
        }
        ?>
        <script>
            document.getElementById('add-criteria').addEventListener('click', function () {
                const table = document.querySelector('#criteria-table tbody');
                const row = document.createElement('tr');
                row.innerHTML = `
                    <td><input name="field_id[]" /></td>
                    <td><input name="match_value[]" /></td>
                    <td><button type="button" class="remove-row button">×</button></td>
                `;
                table.appendChild(row);
                
                // Add event listener to new remove button
                row.querySelector('.remove-row').addEventListener('click', function () {
                    this.closest('tr').remove();
                });
            });

            document.addEventListener('click', function(e) {
                if (e.target.classList.contains('remove-row')) {
                    e.target.closest('tr').remove();
                }
            });
        </script>
    </div>
    <?php
}