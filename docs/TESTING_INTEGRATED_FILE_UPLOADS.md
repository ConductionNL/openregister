# Testing Strategy: Integrated File Uploads

## Overview

We have **two complementary test approaches** for the integrated file upload feature:

1. **Unit Tests** - Fast, isolated tests using mocks
2. **Integration Tests** - Real API tests using Guzzle HTTP client

## 1. Unit Tests (Mock-Based)

**File:** `tests/Unit/Service/ObjectHandlers/IntegratedFileUploadTest.php`

### What They Test
- ✅ Individual component logic (SaveObject handler)
- ✅ File processing methods
- ✅ Validation logic
- ✅ Error handling

### Characteristics
- **Speed:** Very fast (<1 second)
- **Dependencies:** None (all mocked)
- **Scope:** Internal logic only
- **Coverage:** Code paths and edge cases

### How to Run

```bash
cd openregister
./vendor/bin/phpunit tests/Unit/Service/ObjectHandlers/IntegratedFileUploadTest.php --testdox
```

### Test Cases
1. Multipart file upload (mocked)
2. Base64 file with data URI
3. URL file reference
4. Array of files
5. Mixed file types
6. Upload errors (UPLOAD_ERR_*)
7. Invalid MIME types
8. Files exceeding max size
9. Corrupted base64 data
10. Array validation errors

### Pros & Cons

**Pros:**
- ⚡ Extremely fast
- 🔧 No setup required
- 🎯 Pinpoint specific logic
- 📊 Great for code coverage

**Cons:**
- ❌ Doesn't test real file operations
- ❌ Doesn't verify API contracts
- ❌ Can't catch integration issues
- ❌ Doesn't test Nextcloud file system

---

## 2. Integration Tests (Guzzle API)

**File:** `tests/Integration/IntegratedFileUploadIntegrationTest.php`

### What They Test
- ✅ Full API endpoints (POST/PUT/GET)
- ✅ Real file uploads to Nextcloud
- ✅ Database persistence
- ✅ File metadata hydration
- ✅ Schema validation end-to-end
- ✅ Multitenancy and RBAC

### Characteristics
- **Speed:** Slower (~10-30 seconds)
- **Dependencies:** Running Nextcloud container + OpenRegister enabled
- **Scope:** Full stack (API → Controller → Service → FileService → Database → File System)
- **Coverage:** Real-world scenarios

### Prerequisites

**Option A: Running inside container** (preferred)
```bash
docker exec -u 33 master-nextcloud-1 php /var/www/html/apps-extra/openregister/vendor/bin/phpunit \
  /var/www/html/apps-extra/openregister/tests/Integration/IntegratedFileUploadIntegrationTest.php \
  --testdox
```

**Option B: From host with Guzzle**
```bash
cd openregister
NEXTCLOUD_URL=http://localhost:8080 ./vendor/bin/phpunit tests/Integration/IntegratedFileUploadIntegrationTest.php --testdox
```

### Test Matrix

The integration tests cover:

| Upload Method | Schema Config | Test Case |
|--------------|---------------|-----------|
| Multipart | Single file, PDF only | ✅ testMultipartUploadSinglePdf |
| Multipart | Multiple files, mixed types | ✅ testMultipartUploadMultipleFiles |
| Base64 | Data URI, PDF | ✅ testBase64UploadWithDataUri |
| URL | External PDF download | ✅ testUrlReferenceUpload |
| Multipart | Array of images | ✅ testArrayOfFilesMultipart |
| Base64 | Array of images | ✅ testArrayOfFilesBase64 |
| Base64 | Wrong MIME type (validation) | ✅ testValidationWrongMimeType |
| Base64 | File too large (validation) | ✅ testValidationFileTooLarge |
| Base64 | Corrupted data (validation) | ✅ testValidationCorruptedBase64 |
| GET | File metadata hydration | ✅ testGetReturnsFileMetadata |
| PUT | Update with new file | ✅ testUpdateObjectWithNewFile |
| Mixed | Multipart + Base64 in same request | ✅ testMixedMethodsMultipartAndJson |

### Test Cases

#### 1. **Multipart Uploads**
- Single file upload
- Multiple files in one request
- Arrays of files using `name[]` notation

#### 2. **Base64 Uploads**
- Data URI format (`data:mime;base64,content`)
- Arrays of base64 files
- Mixed with other properties

#### 3. **URL References**
- Download from external URL
- Validate downloaded content

#### 4. **Schema Validation**
- Wrong MIME type rejection
- File size limit enforcement
- Corrupted data handling

#### 5. **CRUD Operations**
- POST: Create with files
- PUT: Update with new files
- GET: File metadata hydration

#### 6. **Complex Scenarios**
- Mixed upload methods
- Arrays of files
- Multiple file properties

### Automatic Cleanup

The integration tests **automatically**:
- ✅ Create test registers and schemas in `setUp()`
- ✅ Track created objects
- ✅ Delete all objects in `tearDown()`
- ✅ Delete test register (cascades to schemas)

**No manual cleanup needed!**

### Pros & Cons

**Pros:**
- ✅ Tests real behavior
- ✅ Catches integration bugs
- ✅ Verifies API contracts
- ✅ Tests actual file system operations
- ✅ Validates database persistence

**Cons:**
- 🐌 Slower execution
- 🔧 Requires running environment
- 🏗️ More complex setup
- 📦 Harder to debug failures

---

## When to Use Which?

### Use Unit Tests When:
- 🔧 Developing new features
- 🐛 Debugging specific logic
- 📊 Improving code coverage
- ⚡ Need fast feedback loop
- 🎯 Testing edge cases and error paths

### Use Integration Tests When:
- 🚀 Before deployment
- 🔗 Verifying API contracts
- 🗂️ Testing file operations
- 🌍 Testing with real Nextcloud environment
- ✅ Acceptance testing

---

## Best Practice: Run Both!

### Development Workflow

```bash
# 1. Fast feedback during development
./vendor/bin/phpunit tests/Unit/Service/ObjectHandlers/IntegratedFileUploadTest.php

# 2. Verify integration before commit
docker exec -u 33 master-nextcloud-1 php /var/www/html/apps-extra/openregister/vendor/bin/phpunit \
  /var/www/html/apps-extra/openregister/tests/Integration/IntegratedFileUploadIntegrationTest.php
```

### CI/CD Pipeline

```yaml
# Example GitHub Actions
test:
  steps:
    - name: Unit Tests
      run: ./vendor/bin/phpunit tests/Unit --coverage-text
    
    - name: Start Nextcloud
      run: docker-compose up -d
    
    - name: Integration Tests
      run: docker exec nextcloud php /var/www/html/apps-extra/openregister/vendor/bin/phpunit tests/Integration
```

---

## Troubleshooting

### Integration Tests Fail with "Connection Refused"

**Problem:** Can't reach Nextcloud container

**Solutions:**
```bash
# 1. Check container is running
docker ps | grep nextcloud

# 2. Verify container name
docker ps --format "table {{.Names}}\t{{.Status}}"

# 3. Update baseUrl in test or set env var
export NEXTCLOUD_URL=http://your-container-name
```

### Integration Tests Fail with "App not found"

**Problem:** OpenRegister not enabled

**Solution:**
```bash
docker exec -u 33 master-nextcloud-1 php occ app:enable openregister
```

### Unit Tests Fail with "Class not found"

**Problem:** Missing dependencies

**Solution:**
```bash
cd openregister
composer install
```

---

## Test Coverage Report

Generate coverage (requires Xdebug):

```bash
./vendor/bin/phpunit tests/Unit --coverage-html coverage/
open coverage/index.html
```

---

## Summary

| Aspect | Unit Tests | Integration Tests |
|--------|-----------|-------------------|
| **Speed** | ⚡ Fast (<1s) | 🐌 Slow (~30s) |
| **Setup** | None | Nextcloud + OpenRegister |
| **Scope** | Internal logic | Full API stack |
| **Dependencies** | Mocked | Real (DB, Files, API) |
| **Use Case** | Development | Pre-deployment |
| **Debugging** | Easy | Complex |
| **Coverage** | Code paths | Real scenarios |

**Recommendation:** Run unit tests frequently during development, and integration tests before commits/deployments.



