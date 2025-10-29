# Solr Published-Only Indexing

## Overview

OpenRegister implements a **published-only indexing strategy** for Apache Solr search functionality. This means that only objects with a `published` date are indexed to Solr, ensuring that search results only contain publicly available content.

## Implementation Details

### Current Behavior

- **Single Object Indexing**: The `indexObject()` method checks if an object has a `published` date before indexing
- **Bulk Indexing**: Both `bulkIndexFromDatabase()` and `bulkIndexFromDatabaseOptimized()` methods filter out unpublished objects
- **Search Results**: Only published objects appear in search results since unpublished objects are not indexed

### Code Locations

The published-only logic is implemented in:

- `lib/Service/GuzzleSolrService.php::indexObject()` - Single object indexing
- `lib/Service/GuzzleSolrService.php::bulkIndexFromDatabase()` - Bulk indexing (serial mode)
- `lib/Service/GuzzleSolrService.php::bulkIndexFromDatabaseOptimized()` - Bulk indexing (optimized mode)

### Database vs Solr Counts

The system tracks two different counts:

1. **Published Count**: Number of objects in the database with a `published` date (from `oc_openregister_objects` table)
2. **Indexed Count**: Number of documents actually indexed in Solr (should match published count)

These counts are displayed in the Solr Configuration dashboard to help administrators monitor indexing status.

## Benefits

1. **Relevant Search Results**: Users only see content that is meant to be public
2. **Performance**: Smaller Solr index size improves search performance
3. **Security**: Unpublished/draft content is not accidentally exposed through search
4. **Resource Efficiency**: Reduced storage and memory usage in Solr

## Monitoring

### Dashboard Statistics

The Solr Configuration dashboard shows:
- **Indexed Documents**: Number of documents in Solr
- **Published Objects Available**: Total number of published objects in the database

If these numbers don't match, it indicates that some published objects haven't been indexed yet.

### Logging

The system logs when unpublished objects are skipped:
- Single objects: `DEBUG` level - 'Skipping indexing of unpublished object'
- Bulk operations: `INFO` level - 'Skipped unpublished objects in batch'

## Future Considerations

### TODO: Full Object Indexing

There are TODO comments in the code indicating that in the future, we may want to index all objects to Solr for comprehensive search capabilities. This would require:

1. **Access Control**: Implementing proper access control in search queries
2. **Filtering**: Adding published/unpublished filters to search results
3. **Performance**: Handling larger index sizes
4. **Security**: Ensuring unpublished content is properly protected

### Migration Path

When moving to full indexing:

1. Update indexing methods to remove published-only filtering
2. Implement access control in search queries
3. Add published status filters to search endpoints
4. Update documentation and monitoring

## Configuration

No additional configuration is required. The published-only indexing is enabled by default and works automatically based on the `published` field in object entities.

## Troubleshooting

### Common Issues

1. **Mismatched Counts**: If indexed count < published count
   - Run a Solr warmup to re-index all published objects
   - Check Solr logs for indexing errors

2. **Objects Not Appearing in Search**: 
   - Verify the object has a `published` date set
   - Check if the object was indexed after being published
   - Run a manual re-index if needed

3. **Performance Issues**:
   - Monitor the published vs indexed ratio
   - Consider batch size adjustments for bulk operations

### Debugging

Enable debug logging to see which objects are being skipped:

```php
// In your Nextcloud config
'loglevel' => 0, // Debug level
```

Look for log entries containing:
- 'Skipping indexing of unpublished object'
- 'Skipped unpublished objects in batch'
