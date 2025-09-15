# Testing Improvements - Lessons Learned from the json_decode Bug

## The Problem

On September 15, 2024, we encountered a critical bug in production:

```
json_decode(): Argument #1 ($json) must be of type string, 
GuzzleHttp\Psr7\Stream given in file 'lib/Service/GuzzleSolrService.php' line 2231
```

This bug occurred when we switched from Nextcloud's HTTP client to a direct Guzzle client to bypass local access restrictions. The bug caused HTTP 500 errors on the SOLR connection test API endpoint.

## Root Cause Analysis

**Why this bug occurred:**
1. **Architectural Change**: We switched from `IClientService` to direct `GuzzleHttp\Client`
2. **Response Type Difference**: 
   - Nextcloud HTTP Client: `$response->getBody()` returns a `string`
   - Direct Guzzle Client: `$response->getBody()` returns a `GuzzleHttp\Psr7\Stream` object
3. **Missing Type Casting**: Our code assumed `getBody()` always returned a string
4. **Insufficient Testing**: No tests validated HTTP client response handling

## The Fix

We fixed the issue by casting all response bodies to strings:

```php
// BEFORE (causing TypeError):
$data = json_decode($response->getBody(), true);

// AFTER (fixed):
$data = json_decode((string)$response->getBody(), true);
```

**Files Updated:**
- Fixed 12 instances in `lib/Service/GuzzleSolrService.php`
- All methods that parse JSON responses now properly cast response bodies

## Testing Improvements Implemented

### 1. Integration Tests (`tests/Integration/SolrApiIntegrationTest.php`)

**Key Features:**
- **Mock HTTP Responses**: Uses Guzzle's `MockHandler` to simulate real HTTP responses
- **Stream Bug Reproduction**: Specifically tests the scenario that caused our bug
- **Response Type Validation**: Ensures all methods handle Stream objects correctly
- **Exception Detection**: Catches `TypeError` exceptions related to `json_decode()`

**Critical Test:**
```php
public function testJsonDecodeStreamBugIsFixed(): void
{
    // Create a Stream object (what Guzzle returns)
    $stream = \GuzzleHttp\Psr7\Utils::streamFor($jsonString);
    $mockResponse = new Response(200, [], $stream);
    
    // This test would FAIL before our fix and PASS after
    $result = $this->guzzleSolrService->testConnection();
}
```

### 2. Controller Unit Tests (`tests/Unit/Controller/SettingsControllerTest.php`)

**Key Features:**
- **Comprehensive Coverage**: Tests ALL 25+ SettingsController endpoints
- **API Response Validation**: Ensures all endpoints return proper `JSONResponse` objects
- **Exception Handling**: Tests that controller gracefully handles service exceptions
- **JSON Structure Validation**: Validates response structure and required fields
- **Serialization Testing**: Ensures response data is JSON-encodable

**Endpoints Covered:**
- **SOLR**: `testSolrConnection`, `setupSolr`, `testSolrSetup`, `getSolrSettings`, `updateSolrSettings`, `getSolrDashboardStats`, `warmupSolrIndex`, `testSchemaMapping`
- **Cache**: `getCacheStats`, `clearCache`, `warmupNamesCache`
- **RBAC**: `getRbacSettings`, `updateRbacSettings`
- **Multitenancy**: `getMultitenancySettings`, `updateMultitenancySettings`
- **Retention**: `getRetentionSettings`, `updateRetentionSettings`
- **Core**: `load`, `update`, `updatePublishingOptions`, `rebase`, `stats`, `getStatistics`, `getVersionInfo`

**Critical Tests:**
```php
// Exception handling test
public function testSolrConnectionTestHandlesServiceExceptions(): void
{
    // Mock service throwing our exact bug
    $this->settingsService
        ->method('testSolrConnection')
        ->willThrowException(new \TypeError('json_decode(): Argument #1...'));
    
    // Controller should return valid JSON, not crash
    $response = $this->controller->testSolrConnection();
    $this->assertInstanceOf(JSONResponse::class, $response);
}

// Error reporting tests
public function testSolrSetupErrorReportingWithPortZero(): void
{
    // Test port 0 scenario that was causing issues
    $this->settingsService->method('getSolrSettings')
        ->willReturn(['host' => 'localhost', 'port' => 0]);
    
    $response = $this->controller->setupSolr();
    $data = $response->getData();
    
    // Verify port 0 is not included in URLs
    $generatedUrl = $data['error_details']['configuration_used']['generated_url'];
    $this->assertStringNotContainsString(':0', $generatedUrl);
}

// Kubernetes service name handling
public function testUrlBuildingWithKubernetesServiceNames(): void
{
    // Mock Kubernetes service configuration
    $this->config->method('getAppValue')
        ->willReturnMap([
            ['openregister', 'solr_host', 'localhost', 'con-solr-solrcloud-common.solr.svc.cluster.local'],
            ['openregister', 'solr_port', '8983', '0']
        ]);
    
    // Test should pass without port issues
    $result = $this->guzzleSolrService->testConnection();
    $this->assertIsArray($result);
}
```

## What These Tests Catch

### ✅ **Issues These Tests WOULD Have Caught:**
1. **json_decode Type Errors**: Direct detection of Stream vs string issues
2. **HTTP Client Changes**: Any breaking changes when switching HTTP clients
3. **Response Parsing Failures**: Invalid JSON handling, malformed responses
4. **Controller Exception Handling**: Unhandled service exceptions causing crashes
5. **API Response Structure**: Missing required fields, wrong data types
6. **URL Building Issues**: Port 0 problems, Kubernetes service name handling
7. **Error Reporting Regressions**: Missing error details, generic messages
8. **Configuration Edge Cases**: Various hostname/port combinations

### ✅ **Additional Benefits:**
1. **Regression Prevention**: Future HTTP client changes won't break existing functionality
2. **Documentation**: Tests serve as living documentation of expected behavior
3. **Confidence**: Developers can refactor knowing tests will catch breaking changes
4. **Debugging**: Failed tests provide specific error context

## Best Practices Established

### 1. **Always Test HTTP Client Integration**
```php
// Mock different HTTP client behaviors
$mockHandler = new MockHandler([
    new Response(200, [], $jsonStream),  // Stream response
    new Response(500, [], $errorString), // String response
    new Response(404, [], 'Not Found'),  // Plain text
]);
```

### 2. **Test Exception Handling in Controllers**
```php
// Ensure controllers handle service exceptions gracefully
$this->settingsService->method('testSolrConnection')
    ->willThrowException(new \TypeError('...'));
    
$response = $this->controller->testSolrConnection();
$this->assertInstanceOf(JSONResponse::class, $response);
```

### 3. **Validate Response Structure**
```php
// Ensure API responses are consistent
$data = $response->getData();
$this->assertArrayHasKey('success', $data);
$this->assertArrayHasKey('message', $data);
$this->assertIsBool($data['success']);
```

### 4. **Test Type Casting Edge Cases**
```php
// Test that response body casting works correctly
$stream = \GuzzleHttp\Psr7\Utils::streamFor($jsonString);
$result = json_decode((string)$stream->getBody(), true);
$this->assertIsArray($result);
```

## Running the Tests

```bash
# Run all tests
./vendor/bin/phpunit

# Run specific test suites
./vendor/bin/phpunit tests/Integration/SolrApiIntegrationTest.php --testdox
./vendor/bin/phpunit tests/Unit/Controller/SettingsControllerTest.php --testdox

# Run with coverage
./vendor/bin/phpunit --coverage-html coverage/
```

## Future Improvements

1. **Pre-commit Hooks**: Automatically run these tests before commits
2. **CI/CD Integration**: Include these tests in automated pipelines
3. **Performance Testing**: Add tests for response time and memory usage
4. **Error Scenario Coverage**: Test more edge cases and failure modes

## Conclusion

This bug taught us the critical importance of:
- **Testing architectural changes** thoroughly
- **Understanding HTTP client differences** between libraries
- **Implementing comprehensive integration tests** for external dependencies
- **Validating assumptions** about third-party library behavior

The tests we've implemented will prevent similar issues in the future and provide confidence when making changes to HTTP client implementations.
