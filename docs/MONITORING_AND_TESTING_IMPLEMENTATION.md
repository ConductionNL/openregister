# Monitoring & Testing Implementation Guide

## Overview

This document outlines the monitoring infrastructure and testing framework implementation for OpenRegister's AI-powered features.

---

## ✅ Monitoring Implementation

### Database Schema (COMPLETED)

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

### MetricsService (COMPLETED)

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

### Integration Points (TODO)

#### 1. Register MetricsService

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

#### 2. Integrate into SolrFileService

**File**: `lib/Service/SolrFileService.php`

```php
// Add to constructor
private MetricsService $metricsService;

public function __construct(
    // ... existing params
    MetricsService $metricsService
) {
    // ... existing assignments
    $this->metricsService = $metricsService;
}

// In processAndIndexFile()
$startTime = microtime(true);
try {
    // ... existing processing logic
    
    $duration = (int)((microtime(true) - $startTime) * 1000);
    $this->metricsService->recordMetric(
        MetricsService::METRIC_FILE_PROCESSED,
        'file',
        (string)$fileId,
        'success',
        $duration,
        ['filename' => $filename, 'chunks' => count($chunks)]
    );
} catch (\Exception $e) {
    $duration = (int)((microtime(true) - $startTime) * 1000);
    $this->metricsService->recordMetric(
        MetricsService::METRIC_FILE_PROCESSED,
        'file',
        (string)$fileId,
        'failure',
        $duration,
        null,
        $e->getMessage()
    );
    throw $e;
}
```

#### 3. Integrate into VectorEmbeddingService

**File**: `lib/Service/VectorEmbeddingService.php`

```php
// Add to constructor
private MetricsService $metricsService;

// In generateEmbedding() and generateBatchEmbeddings()
$startTime = microtime(true);
try {
    // ... existing embedding logic
    
    $duration = (int)((microtime(true) - $startTime) * 1000);
    $this->metricsService->recordMetric(
        MetricsService::METRIC_EMBEDDING_GENERATED,
        $entityType,
        $entityId,
        'success',
        $duration,
        ['model' => $model, 'dimensions' => count($embedding)]
    );
} catch (\Exception $e) {
    $duration = (int)((microtime(true) - $startTime) * 1000);
    $this->metricsService->recordMetric(
        MetricsService::METRIC_EMBEDDING_GENERATED,
        $entityType,
        $entityId,
        'failure',
        $duration,
        null,
        $e->getMessage()
    );
    throw $e;
}
```

#### 4. Integrate into Search Methods

**File**: `lib/Service/VectorEmbeddingService.php`

```php
// In semanticSearch()
$startTime = microtime(true);
$results = // ... existing logic
$duration = (int)((microtime(true) - $startTime) * 1000);
$this->metricsService->recordMetric(
    MetricsService::METRIC_SEARCH_SEMANTIC,
    'search',
    null,
    'success',
    $duration,
    ['query_length' => strlen($query), 'results' => count($results)]
);

// In hybridSearch()
$startTime = microtime(true);
$results = // ... existing logic
$duration = (int)((microtime(true) - $startTime) * 1000);
$this->metricsService->recordMetric(
    MetricsService::METRIC_SEARCH_HYBRID,
    'search',
    null,
    'success',
    $duration,
    ['query_length' => strlen($query), 'results' => count($results)]
);
```

#### 5. Add API Endpoint for Metrics

**File**: `lib/Controller/SettingsController.php`

```php
/**
 * Get operational metrics
 *
 * @NoAdminRequired
 * @NoCSRFRequired
 *
 * @return JSONResponse
 */
public function getMetrics(): JSONResponse {
    try {
        $metricsService = \OC::$server->get(MetricsService::class);
        $metrics = $metricsService->getDashboardMetrics();
        
        return new JSONResponse($metrics, 200);
    } catch (\Exception $e) {
        return new JSONResponse(
            ['error' => $e->getMessage()],
            500
        );
    }
}
```

**File**: `appinfo/routes.php`

```php
['name' => 'settings#getMetrics', 'url' => '/api/metrics', 'verb' => 'GET'],
```

---

## 📊 Unit Testing Implementation

### Test Infrastructure Setup

#### 1. PHPUnit Configuration

**File**: `phpunit.xml` (already exists)

Ensure it includes:
```xml
<testsuites>
    <testsuite name="unit">
        <directory>./tests/Unit</directory>
    </testsuite>
</testsuites>
```

#### 2. Create Test Directory Structure

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

### Unit Test Examples

#### SolrObjectServiceTest.php

```php
<?php

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Service\SolrObjectService;
use OCA\OpenRegister\Service\GuzzleSolrService;
use OCA\OpenRegister\Service\SettingsService;
use OCA\OpenRegister\Db\ObjectEntity;
use Test\TestCase;
use PHPUnit\Framework\MockObject\MockObject;

class SolrObjectServiceTest extends TestCase {
    /** @var GuzzleSolrService|MockObject */
    private $guzzleSolrService;
    
    /** @var SettingsService|MockObject */
    private $settingsService;
    
    /** @var SolrObjectService */
    private $service;
    
    protected function setUp(): void {
        parent::setUp();
        
        $this->guzzleSolrService = $this->createMock(GuzzleSolrService::class);
        $this->settingsService = $this->createMock(SettingsService::class);
        
        $this->service = new SolrObjectService(
            $this->guzzleSolrService,
            $this->settingsService,
            $this->createMock(\Psr\Log\LoggerInterface::class)
        );
    }
    
    public function testConvertObjectToText() {
        $object = new ObjectEntity();
        $object->setUuid('test-uuid-123');
        $object->setData([
            'title' => 'Test Object',
            'description' => 'A test description',
            'metadata' => [
                'author' => 'John Doe',
                'tags' => ['test', 'example']
            ]
        ]);
        
        $text = $this->service->convertObjectToText($object);
        
        $this->assertIsString($text);
        $this->assertStringContainsString('Test Object', $text);
        $this->assertStringContainsString('A test description', $text);
        $this->assertStringContainsString('John Doe', $text);
        $this->assertStringContainsString('test', $text);
        $this->assertStringContainsString('example', $text);
    }
    
    public function testConvertObjectToTextWithNestedData() {
        $object = new ObjectEntity();
        $object->setUuid('nested-uuid');
        $object->setData([
            'level1' => [
                'level2' => [
                    'level3' => 'Deep value'
                ]
            ]
        ]);
        
        $text = $this->service->convertObjectToText($object);
        
        $this->assertStringContainsString('Deep value', $text);
    }
    
    public function testConvertObjectsToText() {
        $objects = [
            $this->createObjectWithTitle('Object 1'),
            $this->createObjectWithTitle('Object 2'),
        ];
        
        $texts = $this->service->convertObjectsToText($objects);
        
        $this->assertCount(2, $texts);
        $this->assertStringContainsString('Object 1', $texts[0]);
        $this->assertStringContainsString('Object 2', $texts[1]);
    }
    
    private function createObjectWithTitle(string $title): ObjectEntity {
        $object = new ObjectEntity();
        $object->setUuid('uuid-' . $title);
        $object->setData(['title' => $title]);
        return $object;
    }
}
```

#### SolrFileServiceTest.php

```php
<?php

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Service\SolrFileService;
use Test\TestCase;

class SolrFileServiceTest extends TestCase {
    /** @var SolrFileService */
    private $service;
    
    protected function setUp(): void {
        parent::setUp();
        
        $this->service = $this->createPartialMock(
            SolrFileService::class,
            []
        );
    }
    
    public function testChunkFixedSize() {
        $text = str_repeat('A', 1500); // 1500 characters
        $chunks = $this->invokePrivateMethod(
            $this->service,
            'chunkFixedSize',
            [$text, 500, 100]
        );
        
        $this->assertIsArray($chunks);
        $this->assertGreaterThan(2, count($chunks));
        
        // Check overlap
        $this->assertEquals(
            substr($chunks[0], -100),
            substr($chunks[1], 0, 100)
        );
    }
    
    public function testExtractFromTextFile() {
        $testContent = "Hello World\nThis is a test file.";
        $tempFile = tmpfile();
        fwrite($tempFile, $testContent);
        $path = stream_get_meta_data($tempFile)['uri'];
        
        $extracted = $this->invokePrivateMethod(
            $this->service,
            'extractFromTextFile',
            [$path]
        );
        
        $this->assertEquals($testContent, $extracted);
        
        fclose($tempFile);
    }
    
    public function testCleanText() {
        $dirtyText = "Hello   World\n\n\n  Test  ";
        $clean = $this->invokePrivateMethod(
            $this->service,
            'cleanText',
            [$dirtyText]
        );
        
        $this->assertEquals("Hello World\n\nTest", $clean);
    }
    
    private function invokePrivateMethod($object, $methodName, array $parameters = []) {
        $reflection = new \ReflectionClass(get_class($object));
        $method = $reflection->getMethod($methodName);
        $method->setAccessible(true);
        return $method->invokeArgs($object, $parameters);
    }
}
```

#### VectorEmbeddingServiceTest.php

```php
<?php

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Service\VectorEmbeddingService;
use Test\TestCase;

class VectorEmbeddingServiceTest extends TestCase {
    /** @var VectorEmbeddingService */
    private $service;
    
    protected function setUp(): void {
        parent::setUp();
        
        // Mock dependencies
        $db = $this->createMock(\OCP\IDBConnection::class);
        $settings = $this->createMock(\OCA\OpenRegister\Service\SettingsService::class);
        $logger = $this->createMock(\Psr\Log\LoggerInterface::class);
        
        $this->service = new VectorEmbeddingService($db, $settings, $logger);
    }
    
    public function testCosineSimilarity() {
        $vector1 = [1.0, 0.0, 0.0];
        $vector2 = [1.0, 0.0, 0.0];
        
        $similarity = $this->invokePrivateMethod(
            $this->service,
            'cosineSimilarity',
            [$vector1, $vector2]
        );
        
        $this->assertEquals(1.0, $similarity);
    }
    
    public function testCosineSimilarityOrthogonal() {
        $vector1 = [1.0, 0.0];
        $vector2 = [0.0, 1.0];
        
        $similarity = $this->invokePrivateMethod(
            $this->service,
            'cosineSimilarity',
            [$vector1, $vector2]
        );
        
        $this->assertEquals(0.0, $similarity, '', 0.001);
    }
    
    public function testReciprocalRankFusion() {
        $keywordResults = [
            ['id' => 'A', 'score' => 10],
            ['id' => 'B', 'score' => 8],
            ['id' => 'C', 'score' => 6],
        ];
        
        $semanticResults = [
            ['id' => 'B', 'similarity' => 0.9],
            ['id' => 'A', 'similarity' => 0.8],
            ['id' => 'D', 'similarity' => 0.7],
        ];
        
        $merged = $this->invokePrivateMethod(
            $this->service,
            'reciprocalRankFusion',
            [$keywordResults, $semanticResults, 60]
        );
        
        $this->assertCount(4, $merged);
        // B should be first (appears in both lists)
        $this->assertEquals('B', $merged[0]['id']);
    }
    
    private function invokePrivateMethod($object, $methodName, array $parameters = []) {
        $reflection = new \ReflectionClass(get_class($object));
        $method = $reflection->getMethod($methodName);
        $method->setAccessible(true);
        return $method->invokeArgs($object, $parameters);
    }
}
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

---

## 🔄 Implementation Checklist

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

---

## 📈 Metrics Dashboard

### Recommended Visualizations

1. **Files Processed**: Line chart showing daily file processing volume
2. **Embedding Success Rate**: Gauge showing percentage
3. **Search Latency**: Bar chart comparing keyword/semantic/hybrid
4. **Storage Growth**: Area chart showing vector storage over time
5. **Cost Tracking**: Running total of estimated API costs

### Example Dashboard API Response

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

---

## 🎯 Next Steps

1. **Complete Integration**: Follow the integration points above to wire up MetricsService
2. **Write Tests**: Implement the unit test examples provided
3. **Run Tests**: Ensure all tests pass
4. **Create Dashboard**: Build UI to visualize metrics (optional but recommended)
5. **Set Up Alerts**: Configure monitoring alerts for failures/latency spikes (production)

---

*Document created: October 13, 2025*  
*Status: Monitoring infrastructure complete, integration pending*

