# Search Trails

Search trails provide comprehensive logging and analytics for search operations within the OpenRegister application. This feature allows administrators to track, analyze, and optimize search performance across all registers and schemas.

## Overview

Search trails automatically capture detailed information about every search operation performed in the system, including:

- Search terms and parameters
- Execution time and performance metrics
- Result counts and success rates
- User information and session details
- Register and schema context
- Request metadata and user agent information

## Features

### Automatic Search Logging

Every search operation is automatically logged with:

- **Search Context**: Register, schema, and search terms
- **Performance Metrics**: Execution time, result count, and success status
- **User Information**: User ID, session ID, and IP address
- **Request Details**: User agent, request method, and timestamp
- **Parameters**: Complete search parameters excluding sensitive information

### Analytics Dashboard

The search trails interface provides:

- **Comprehensive Statistics**: Total searches, results, success rates, and performance metrics
- **Popular Search Terms**: Most frequently used search terms with usage counts
- **Register/Schema Usage**: Search activity breakdown by register and schema
- **User Agent Statistics**: Browser and client distribution
- **Activity Patterns**: Search activity over time (hourly, daily, weekly, monthly)

### Advanced Filtering

Filter search trails by:

- **Time Range**: From and to dates for specific periods
- **Success Status**: Successful or failed searches
- **User**: Specific users or groups
- **Register/Schema**: Specific registers or schemas
- **Search Terms**: Specific search terms or patterns
- **Performance**: Execution time and result count ranges

### Query Complexity Analysis

Search trails categorize queries by complexity:

- **Simple**: Basic term searches with minimal parameters
- **Medium**: Multi-parameter searches with basic filters
- **Complex**: Advanced searches with multiple filters and facets

## API Endpoints

### GET /api/search-trails

Retrieve paginated search trail entries.

**Parameters:**
- `limit` (integer): Number of results per page (default: 50)
- `offset` (integer): Number of results to skip
- `page` (integer): Page number
- `search` (string): Search term filter
- `register` (string): Filter by register ID
- `schema` (string): Filter by schema ID
- `user` (string): Filter by user
- `success` (boolean): Filter by success status
- `dateFrom` (datetime): Start date filter
- `dateTo` (datetime): End date filter
- `searchTerm` (string): Filter by search term
- `executionTimeFrom` (integer): Minimum execution time (ms)
- `executionTimeTo` (integer): Maximum execution time (ms)
- `resultCountFrom` (integer): Minimum result count
- `resultCountTo` (integer): Maximum result count

**Example Request:**
```bash
GET /api/search-trails?limit=20&register=users&success=true
```

**Example Response:**
```json
{
  'results': [
    {
      'id': 1,
      'searchTerm': 'user search',
      'register': 'users',
      'schema': 'person',
      'parameters': {
        'limit': 20,
        'filters': {
          'status': 'active'
        }
      },
      'resultCount': 15,
      'executionTime': 150,
      'success': true,
      'user': 'admin',
      'userName': 'Administrator',
      'userAgent': 'Mozilla/5.0...',
      'ipAddress': '192.168.1.100',
      'sessionId': 'sess_abc123',
      'created': '2024-01-15T10:30:00Z'
    }
  ],
  'total': 1,
  'page': 1,
  'pages': 1,
  'limit': 20,
  'offset': 0
}
```

### GET /api/search-trails/statistics

Retrieve comprehensive search statistics.

**Example Response:**
```json
{
  'total': 1000,
  'totalResults': 15000,
  'averageResultsPerSearch': 15,
  'averageExecutionTime': 180,
  'successRate': 0.95,
  'uniqueSearchTerms': 250,
  'uniqueUsers': 50,
  'uniqueOrganizations': 10,
  'queryComplexity': {
    'simple': 600,
    'medium': 300,
    'complex': 100
  }
}
```

### GET /api/search-trails/popular-terms

Retrieve popular search terms.

**Parameters:**
- `limit` (integer): Number of terms to return (default: 10)

**Example Response:**
```json
[
  {
    'term': 'user',
    'count': 150,
    'percentage': 15.0
  },
  {
    'term': 'active',
    'count': 120,
    'percentage': 12.0
  }
]
```

### GET /api/search-trails/activity

Retrieve search activity data.

**Parameters:**
- `period` (string): Period type (hourly, daily, weekly, monthly)
- `limit` (integer): Number of periods to return

**Example Response:**
```json
[
  {
    'period': '2024-01-15',
    'searches': 50,
    'results': 750,
    'averageExecutionTime': 175,
    'successRate': 0.96
  }
]
```

### GET /api/search-trails/register-schema-stats

Retrieve register and schema usage statistics.

**Example Response:**
```json
[
  {
    'register': 'users',
    'registerName': 'User Registry',
    'schema': 'person',
    'schemaName': 'Person Schema',
    'searchCount': 200,
    'averageExecutionTime': 150,
    'successRate': 0.95
  }
]
```

### GET /api/search-trails/user-agent-stats

Retrieve user agent statistics.

**Example Response:**
```json
[
  {
    'userAgent': 'Mozilla/5.0...',
    'browser': 'Chrome',
    'version': '120.0',
    'platform': 'Windows',
    'count': 500,
    'percentage': 50.0
  }
]
```

### POST /api/search-trails/cleanup

Clean up old search trail entries.

**Request Body:**
```json
{
  'days': 30
}
```

**Example Response:**
```json
{
  'success': true,
  'message': 'Cleanup completed successfully',
  'deletedCount': 100
}
```

## Frontend Interface

### Search Trail Management

The search trails interface provides:

- **Table View**: Paginated list of search trail entries
- **Detailed Information**: Complete search context and parameters
- **Action Buttons**: View details, copy data, and delete entries
- **Bulk Operations**: Select multiple entries for bulk actions

### Advanced Filtering

Filter search trails using:

- **Register/Schema Selection**: Dropdown filters for specific registers and schemas
- **Success Status**: Filter by successful or failed searches
- **User Selection**: Multi-select user filter
- **Date Range**: From and to date pickers
- **Search Terms**: Text input for search term filtering
- **Performance Ranges**: Min/max inputs for execution time and result counts

### Analytics Dashboard

The analytics dashboard includes:

- **Key Metrics**: Total searches, results, success rates, and performance
- **Popular Terms**: Most frequently searched terms with usage counts
- **Activity Charts**: Search activity over time with selectable periods
- **Register/Schema Usage**: Usage statistics by register and schema
- **User Agent Distribution**: Browser and client statistics
- **Query Complexity**: Distribution of simple, medium, and complex queries

## Configuration

### Retention Policy

Configure search trail retention:

```php
// In app configuration
'search_trail_retention_days' => 90,
'search_trail_cleanup_enabled' => true,
'search_trail_cleanup_schedule' => 'daily',
```

### Privacy Settings

Control what information is logged:

```php
// Privacy configuration
'search_trail_log_ip_addresses' => true,
'search_trail_log_user_agents' => true,
'search_trail_log_session_ids' => true,
'search_trail_anonymize_users' => false,
```

### Performance Settings

Configure performance thresholds:

```php
// Performance monitoring
'search_trail_slow_query_threshold' => 1000, // milliseconds
'search_trail_complexity_thresholds' => [
    'simple' => 3,   // parameters
    'medium' => 10,  // parameters
    'complex' => 20, // parameters
],
```

## Best Practices

### Performance Optimization

1. **Regular Cleanup**: Implement automated cleanup of old search trails
2. **Index Management**: Ensure proper database indexes for search performance
3. **Batch Processing**: Use batch operations for large data sets
4. **Monitoring**: Monitor search trail table size and performance

### Privacy and Security

1. **Data Retention**: Implement appropriate retention policies
2. **Access Control**: Restrict access to search trail data
3. **Anonymization**: Consider anonymizing personal information
4. **Audit Logging**: Log access to search trail data

### Analytics and Reporting

1. **Regular Reviews**: Regularly review search patterns and performance
2. **Trend Analysis**: Identify trends in search behavior
3. **Performance Monitoring**: Monitor search performance over time
4. **User Behavior**: Analyze user search patterns for optimization

## Integration Examples

### Custom Analytics

```php
// Custom analytics query
$searchTrailService = new SearchTrailService();
$stats = $searchTrailService->getSearchStatistics([
    'register' => 'users',
    'dateFrom' => '2024-01-01',
    'dateTo' => '2024-01-31'
]);

// Process statistics
$popularTerms = $searchTrailService->getPopularSearchTerms(10);
$activityData = $searchTrailService->getSearchActivityByTime('daily');
```

### Custom Reporting

```php
// Generate custom report
$report = [
    'period' => '2024-01',
    'totalSearches' => $stats['total'],
    'successRate' => $stats['successRate'],
    'popularTerms' => $popularTerms,
    'activityData' => $activityData
];

// Export or process report
$this->exportReport($report);
```

### Performance Monitoring

```php
// Monitor slow queries
$slowQueries = $searchTrailService->getSlowQueries(1000); // > 1000ms

// Alert on performance issues
if (count($slowQueries) > 10) {
    $this->sendPerformanceAlert($slowQueries);
}
```

## Troubleshooting

### Common Issues

1. **High Storage Usage**: Implement regular cleanup and archiving
2. **Slow Queries**: Add database indexes and optimize queries
3. **Missing Data**: Check search trail logging configuration
4. **Performance Issues**: Monitor database performance and optimize

### Debug Mode

Enable debug mode for detailed logging:

```php
// Enable debug logging
'search_trail_debug' => true,
'search_trail_log_level' => 'debug',
```

### Error Handling

Search trail logging is designed to be non-intrusive:

- Failed logging does not affect search operations
- Errors are logged separately for monitoring
- Fallback mechanisms ensure data integrity

## Future Enhancements

### Planned Features

1. **Machine Learning**: Predictive search suggestions based on trails
2. **Advanced Visualization**: Interactive charts and dashboards
3. **Export Capabilities**: PDF and Excel export functionality
4. **API Rate Limiting**: Based on search trail analysis
5. **Personalization**: User-specific search optimization

### Integration Possibilities

1. **External Analytics**: Integration with Google Analytics or similar
2. **Business Intelligence**: Export to BI tools for advanced analysis
3. **Alerting Systems**: Integration with monitoring and alerting platforms
4. **Search Optimization**: Automatic index optimization based on patterns

## Summary

Search trails provide powerful insights into search behavior and performance within the OpenRegister application. The feature enables administrators to:

- Track and analyze all search operations
- Monitor performance and identify bottlenecks
- Understand user behavior and search patterns
- Optimize search functionality based on real usage data
- Maintain comprehensive audit trails for compliance

The combination of detailed logging, advanced analytics, and intuitive interfaces makes search trails an essential tool for managing and optimizing search operations in large-scale registry systems. 