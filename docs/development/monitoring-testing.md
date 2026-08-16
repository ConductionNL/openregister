---
title: Monitoring & Testing Implementation
sidebar_position: 55
---

# Monitoring & Testing Implementation Guide

This document outlines the monitoring infrastructure and testing framework implementation for OpenRegister's AI-powered features.

## Monitoring Implementation

### Database Schema

**Migration**: `lib/Migration/Version002005000Date20251013000000.php`

**Table**: `oc_openregister_metrics`

**Columns**:
- `id`: Primary key
- `metric_type`: Type of metric (file_processed, embedding_generated, search_*, etc.)
- `entity_type`: Entity being tracked (file, object, search)
- `entity_id`: ID of entity
- `user_id`: User who triggered action
- `status`: success/failure
- `duration_ms`: Operation duration
- `metadata`: JSON metadata
- `error_message`: Error details (if failed)
- `created_at`: Timestamp

**Indexes**:
- metric_type
- entity_type
- status
- created_at
- Composite indexes for common queries

### MetricsService

**Location**: `lib/Service/MetricsService.php`

**Key Methods**:

```php
// Record a metric
recordMetric(
    string $metricType,
    ?string $entityType = null,
    ?string $entityId = null,
    string $status = 'success',
    ?int $durationMs = null,
    ?array $metadata = null,
    ?string $errorMessage = null,
    ?string $userId = null
): void

// Get files processed per day
getFilesProcessedPerDay(int $days = 30): array

// Get embedding generation stats
getEmbeddingStats(int $days = 30): array
// Returns: total, successful, failed, success_rate, estimated_cost_usd

// Get search latency statistics
getSearchLatencyStats(int $days = 7): array
// Returns stats for keyword, semantic, hybrid searches

// Get storage growth data
getStorageGrowth(int $days = 30): array
// Returns: daily_vectors_added, current_storage_mb, avg_vectors_per_day

// Get all dashboard metrics
getDashboardMetrics(): array

// Clean old metrics (retention)
cleanOldMetrics(int $retentionDays = 90): int
```

### Integration Points

#### Register MetricsService

**File**: `lib/AppInfo/Application.php`

```php
use OCA\OpenRegister\Service\MetricsService;

// In register() method:
$context->registerService(
    MetricsService::class,
    function ($container) {
        return new MetricsService(
            $container->get('OCP\IDBConnection'),
            $container->get('Psr\Log\LoggerInterface')
        );
    }
);
```

#### Integrate into Services

The MetricsService should be integrated into:
- `SolrFileService` - Track file processing metrics
- `VectorEmbeddingService` - Track embedding generation metrics
- `ChatService` - Track search latency metrics

### Metrics Dashboard

**Recommended Visualizations**:

1. **Files Processed**: Line chart showing daily file processing volume
2. **Embedding Success Rate**: Gauge showing percentage
3. **Search Latency**: Bar chart comparing keyword/semantic/hybrid
4. **Storage Growth**: Area chart showing vector storage over time
5. **Cost Tracking**: Running total of estimated API costs

**Example Dashboard API Response**:

```json
{
  "files_processed": {
    "2025-10-01": 45,
    "2025-10-02": 67,
    "2025-10-03": 52
  },
  "embedding_stats": {
    "total": 1234,
    "successful": 1198,
    "failed": 36,
    "success_rate": 97.08,
    "estimated_cost_usd": 0.0779,
    "period_days": 30
  },
  "search_latency": {
    "keyword": {
      "count": 456,
      "avg_ms": 45.2,
      "min_ms": 12,
      "max_ms": 234
    },
    "semantic": {
      "count": 234,
      "avg_ms": 156.7,
      "min_ms": 67,
      "max_ms": 456
    },
    "hybrid": {
      "count": 345,
      "avg_ms": 234.5,
      "min_ms": 89,
      "max_ms": 567
    }
  },
  "storage_growth": {
    "daily_vectors_added": {
      "2025-10-01": 123,
      "2025-10-02": 156,
      "2025-10-03": 134
    },
    "current_storage_bytes": 45678912,
    "current_storage_mb": 43.56,
    "avg_vectors_per_day": 137.67,
    "period_days": 30
  }
}
```

## Testing Implementation

### Test Infrastructure Setup

#### PHPUnit Configuration

**File**: `phpunit.xml`

Ensure it includes:
```xml
<testsuites>
    <testsuite name="unit">
        <directory>./tests/Unit</directory>
    </testsuite>
    <testsuite name="integration">
        <directory>./tests/Integration</directory>
    </testsuite>
</testsuites>
```

#### Test Directory Structure

```
tests/
├── Unit/
│   ├── Service/
│   │   ├── SolrObjectServiceTest.php
│   │   ├── SolrFileServiceTest.php
│   │   ├── VectorEmbeddingServiceTest.php
│   │   └── MetricsServiceTest.php
│   └── Controller/
│       ├── SolrControllerTest.php
│       └── ChatControllerTest.php
└── Integration/
    ├── FileProcessingTest.php
    └── HybridSearchTest.php
```

### Running Tests

```bash
# Run all tests
vendor/bin/phpunit

# Run specific test suite
vendor/bin/phpunit tests/Unit

# Run specific test file
vendor/bin/phpunit tests/Unit/Service/SolrObjectServiceTest.php

# Run with coverage
vendor/bin/phpunit --coverage-html coverage/
```

## Implementation Checklist

### Monitoring

- [x] Create database migration for metrics table
- [x] Create MetricsService with all tracking methods
- [ ] Register MetricsService in DI container
- [ ] Integrate into SolrFileService
- [ ] Integrate into VectorEmbeddingService  
- [ ] Integrate into ChatService
- [ ] Add metrics API endpoint
- [ ] Add metrics dashboard UI (optional)
- [ ] Set up background job for metrics cleanup

### Testing

- [ ] Create test directory structure
- [ ] Write SolrObjectServiceTest
- [ ] Write SolrFileServiceTest
- [ ] Write VectorEmbeddingServiceTest
- [ ] Write MetricsServiceTest
- [ ] Write integration tests
- [ ] Set up CI/CD pipeline (optional)

## Related Documentation

- [Testing](./testing.md) - Testing improvements and best practices
- [Performance Optimization](./performance-optimization.md) - Performance monitoring

