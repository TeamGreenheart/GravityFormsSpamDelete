# Delete Gravity Forms Spam

A dead simple WordPress plugin for bulk deleting spam entries from Gravity Forms based on customizable field value filters. Please ALWAYS BACKUP YOUR DATABASE BEFORE MESSING WITH ANY PLUGINS LIKE THIS ONE.

## Features

- **Flexible Filtering**: Create multiple field match rules to identify spam entries
- **AND/OR Logic**: Choose whether ALL criteria must match or just ANY criteria
- **Blank Field Detection**: Use the special "blank" keyword to match empty/null fields
- **Safe Preview**: Preview matching entries before deletion
- **Batch Processing**: Efficiently handles large datasets with batched operations
- **CSV Import**: Import test data from Gravity Forms CSV exports for local testing

## Installation

1. Upload the plugin file to your WordPress `/wp-content/plugins/` directory
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Navigate to **Tools > GF Spam Cleaner** in your WordPress admin

## Usage

### Basic Spam Deletion

1. **Set Form ID**: Enter the Gravity Forms form ID you want to clean
2. **Choose Logic**: 
   - **AND**: All criteria must match (safer, more precise)
   - **OR**: Any criteria can match (more aggressive)
3. **Add Match Rules**: 
   - Enter the field ID and the value you want to match
   - Use "blank" as the match value to detect empty fields
4. **Preview**: Click "Preview Matches" to see which entries will be deleted
5. **Delete**: Click "Delete Matching Entries" to remove spam

### Common Use Cases

#### Delete entries with blank first AND last names:
- Field ID: `1`, Match Value: `blank` (first name)
- Field ID: `2`, Match Value: `blank` (last name)
- Logic: `AND`

#### Delete entries with specific spam text:
- Field ID: `3`, Match Value: `spam text here`
- Field ID: `4`, Match Value: `another spam phrase`
- Logic: `OR`

#### Delete entries with blank email OR invalid domain:
- Field ID: `5`, Match Value: `blank`
- Field ID: `5`, Match Value: `@spam-domain.com`
- Logic: `OR`

### Special Features

#### Blank Field Detection
Use `blank` as the match value to identify entries with empty fields. This matches:
- Completely empty fields
- Fields with only whitespace (spaces, tabs, etc.)
- Null/undefined fields

#### Batch Processing
The plugin automatically processes entries in batches to prevent timeouts:
- Processes up to 1,000 deletions per run
- Uses smart batching to handle large datasets efficiently
- Provides debug information for troubleshooting

### CSV Import (Testing)

For local development and testing:

1. **Export** your Gravity Forms entries as CSV
2. **Upload** the CSV file using the import tool
3. **Map** CSV columns to your form field IDs
4. **Import** entries for testing spam deletion locally

## Finding Field IDs

To find your Gravity Forms field IDs:

1. Go to **Forms > [Your Form] > Entries**
2. Click on any entry to view details
3. The field IDs are shown in the URL or field labels
4. Common field IDs: 1, 2, 3, etc. (sequential numbering)
5. Complex fields like Address have values like 4.2, 4.3 etc.

Alternatively, inspect the form HTML or check the form editor in Gravity Forms.

## Troubleshooting

### No entries found
- Verify the form ID is correct
- Check that field IDs match your form structure
- Ensure match values are exact (case-sensitive)

### Timeout errors
- The plugin uses batch processing to prevent timeouts
- Large operations are automatically split into smaller chunks
- Check debug information for processing details

### CSV import issues
- Ensure CSV file format matches Gravity Forms export format
- Verify field mapping matches your form structure
- Check for special characters or encoding issues

## Technical Details

### Requirements
- WordPress 6.0+
- Gravity Forms plugin installed and active
- PHP 8

### Performance
- Batch size: 10,000 entries per batch
- Max deletions per run: 1,000 entries
- Memory efficient processing for large datasets

### Security
- Nonce verification for all form submissions
- Capability checks (`manage_options`)
- Input sanitization and validation
- SQL injection protection via GFAPI

## Changelog

### Version 1.1.0
- Added blank field detection with "blank" keyword
- Improved batch processing efficiency
- Enhanced preview functionality
- Added CSV import for testing
- Better error handling and debug information

### Version 1.0.0
- Initial release
- Basic field matching and deletion
- AND/OR logic support
- Preview functionality

## Support

This plugin is provided as-is. For issues or feature requests, please review the code and modify as needed for your specific use case.

## License

GPL v2 or later

## Credits

Created by Ben Toth
