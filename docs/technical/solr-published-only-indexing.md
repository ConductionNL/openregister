# Solr Published-Only Indexing

## Overview

OpenRegister implements a **published-only indexing strategy** for Apache Solr search functionality. This design decision ensures that only objects with a `published` date are indexed to Solr, keeping search results relevant and secure by only showing publicly available content.

## Architecture Decision

### Why Published-Only?

1. **User Experience**: Search results only contain content that users should see
2. **Performance**: Smaller index size improves search speed and reduces resource usage
3. **Security**: Prevents accidental exposure of draft or unpublished content
4. **Relevance**: Maintains high-quality search results by excluding work-in-progress items

### Implementation Strategy

The published-only filtering is implemented at the indexing level rather than the search level:

- **Indexing Time**: Objects are filtered before being sent to Solr
- **Search Time**: No additional filtering needed since only published objects exist in the index
- **Performance**: Optimal search performance with smaller index size

## Technical Implementation

### Code Locations

The published-only logic is implemented in `lib/Service/GuzzleSolrService.php`:

```php
// Single object indexing
public function indexObject(ObjectEntity $object, bool $commit = false): bool
{
    // Check if object is published
    if (!$object->getPublished()) {
        return true; // Skip unpublished objects
    }
    // ... continue with indexing
}

// Bulk indexing
foreach ($objects as $object) {
    if ($objectEntity && $objectEntity->getPublished()) {
        $documents[] = $this->createSolrDocument($objectEntity, $solrFieldTypes);
    } else {
        $skippedUnpublished++;
    }
}
```

### Affected Methods

1. **`indexObject()`** - Single object indexing from object subscribers
2. **`bulkIndexFromDatabase()`** - Serial bulk indexing for warmup operations
3. **`bulkIndexFromDatabaseOptimized()`** - Optimized bulk indexing for large datasets

### Monitoring and Statistics

The system provides comprehensive monitoring through the Solr Configuration dashboard:

#### Database vs Solr Metrics

- **Published Count**: Objects in database with `published IS NOT NULL`
- **Indexed Count**: Documents actually stored in Solr index
- **Comparison**: Dashboard shows both counts to identify indexing gaps

#### Dashboard Display

```vue
<div class="stat-card">
    <h5>Indexed Documents</h5>
    <p>{{ formatNumber(solrStats.document_count || 0) }}</p>
    <small v-if="solrStats.published_count" class="published-info">
        {{ formatNumber(solrStats.published_count) }} published objects available
    </small>
</div>
```

## Operational Considerations

### Indexing Workflow

1. **Object Creation**: New objects are not indexed until published
2. **Publishing**: When an object gets a `published` date, it becomes eligible for indexing
3. **Updates**: Published objects are re-indexed when modified
4. **Unpublishing**: Objects lose their `published` date but remain in Solr (manual cleanup needed)

### Maintenance Tasks

#### Regular Monitoring

- Check published vs indexed count alignment
- Monitor skipped object logs for bulk operations
- Verify search results contain expected published content

#### Cleanup Operations

Since unpublishing doesn't automatically remove objects from Solr:

1. **Clear and Re-index**: Periodically clear the entire index and rebuild from published objects
2. **Selective Removal**: Implement cleanup jobs to remove unpublished objects from Solr
3. **Monitoring**: Track objects that were unpublished but remain indexed

## Logging and Debugging

### Log Levels

- **DEBUG**: Individual unpublished objects skipped
- **INFO**: Batch statistics showing skipped counts
- **WARNING**: Indexing errors or configuration issues

### Example Log Entries

```
DEBUG: Skipping indexing of unpublished object [object_id: 123, uuid: abc-def]
INFO: Skipped unpublished objects in batch [batch: 5, skipped: 15, published: 85]
```

### Troubleshooting

#### Common Issues

1. **Count Mismatch**: Published count > Indexed count
   - **Cause**: Some published objects failed to index
   - **Solution**: Run Solr warmup to re-index all published objects

2. **Missing Search Results**: Expected objects don't appear
   - **Cause**: Objects may not have `published` date set
   - **Solution**: Verify object publication status in database

3. **Performance Degradation**: Search becomes slow
   - **Cause**: Index size growing unexpectedly
   - **Solution**: Check for unpublished objects remaining in index

## Future Roadmap

### TODO: Comprehensive Indexing

The codebase contains TODO comments indicating future plans for comprehensive indexing:

```php
// TODO: In the future, we want to index all objects to Solr for comprehensive search.
// Currently, we only index published objects to keep the search results relevant.
```

### Migration Considerations

When moving to full indexing:

1. **Access Control**: Implement query-time filtering based on user permissions
2. **Search Filters**: Add published/unpublished toggles to search interfaces  
3. **Performance**: Handle larger index sizes and more complex queries
4. **Security**: Ensure proper access control prevents unauthorized content access

### Implementation Steps

1. Remove published-only filtering from indexing methods
2. Add access control middleware to search endpoints
3. Implement published status filters in search queries
4. Update monitoring to track all objects vs accessible objects
5. Migrate existing indexes with full object set

## Configuration

### Default Behavior

Published-only indexing is enabled by default with no configuration required. The system automatically:

- Filters objects during indexing based on `published` field
- Tracks statistics for monitoring
- Logs skipped objects for debugging

### Customization

Currently, there are no configuration options to disable published-only indexing. This is by design to maintain consistency and security across all OpenRegister installations.

## Best Practices

### Content Management

1. **Publishing Workflow**: Establish clear processes for when content should be published
2. **Review Process**: Implement content review before setting `published` dates
3. **Bulk Publishing**: Use bulk operations for large content releases

### System Administration

1. **Regular Monitoring**: Check dashboard statistics weekly
2. **Index Maintenance**: Schedule periodic full re-indexing
3. **Log Review**: Monitor for unusual patterns in skipped objects

### Development

1. **Testing**: Always test with both published and unpublished objects
2. **Documentation**: Update this documentation when modifying indexing logic
3. **Backwards Compatibility**: Consider impact on existing search functionality
