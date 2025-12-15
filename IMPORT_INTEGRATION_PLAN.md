# Import Integration Plan

**Date:** December 15, 2024  
**Goal:** Integrate the `import()` controller method with `ExportHandler`

---

## Current Situation Analysis

### Controller Import Logic (ObjectsController.php)

**Handles 3 file types:**
1. **Excel (.xlsx, .xls)**
   - Calls: `ImportService->importFromExcel()`
   - Parameters: filePath, register, schema=null, validation, events, _rbac, _multitenancy, publish, currentUser
   
2. **CSV (.csv)**
   - Has **schema resolution logic** (complex!)
   - If no schema specified, gets first available schema from register
   - Calls: `ImportService->importFromCsv()`
   - Parameters: filePath, register, schema, validation, events, _rbac, _multitenancy, currentUser

3. **Other formats**
   - Returns error

**HTTP-specific concerns:**
- File upload handling
- Parameter extraction and validation
- Schema resolution for CSV
- Response formatting

---

### Handler Import Logic (ExportHandler.php)

**Currently handles 2 file types:**
1. **Excel (.xlsx, .xls)**
   - Calls: `ImportService->importFromExcel()`
   - Parameters: register, file, validation, events, currentUser
   
2. **CSV (.csv)**
   - Calls: `ImportService->importFromCsv()`
   - Parameters: register, file, validation, events, currentUser

**Missing:**
- Schema resolution for CSV
- RBAC/multitenancy parameters
- Publish parameter for Excel

---

## Issues Identified

### 🔴 Issue 1: Parameter Mismatch
Controller uses:
- `filePath` (string path to temp file)
- More parameters: `_rbac`, `_multitenancy`, `publish`, `schema` for Excel

Handler uses:
- `file` (uploaded file array)
- Fewer parameters

### 🔴 Issue 2: Schema Resolution
Controller has complex logic for CSV:
```php
// For CSV, schema can be specified in the request.
$schemaId = $this->request->getParam(key: 'schema');

if ($schemaId === null || $schemaId === '') {
    // Get first available schema from register
    $schemas = $registerEntity->getSchemas();
    if (empty($schemas) === true) {
        return new JSONResponse(data: ['error' => 'No schema found for register'], statusCode: 400);
    }
    $schemaId = reset($schemas);
}

$schema = $this->schemaMapper->find($schemaId);
```

Handler doesn't have this logic.

### 🔴 Issue 3: ImportService Signature Uncertainty
Need to verify what `ImportService` actually expects:
- Does it use `filePath` or `file`?
- What parameters does it actually support?

---

## Integration Strategy

### Option A: Enhanced Handler (Recommended) ✅

**Approach:**
1. Update `ExportHandler->import()` to handle all parameters
2. Move schema resolution logic to handler
3. Keep HTTP concerns in controller (file upload, response formatting)

**Benefits:**
- ✅ Handler becomes more powerful
- ✅ Controller becomes simpler
- ✅ Business logic centralized

**Changes Required:**
1. Update `ExportHandler->import()` signature
2. Add schema resolution to handler
3. Update `ObjectService->importObjects()` signature  
4. Update controller to use handler

---

### Option B: Keep Current Implementation

**Approach:**
- Leave controller as-is
- Mark as "complex business logic that belongs in controller"

**Benefits:**
- ✅ No risk of breaking functionality
- ✅ No changes needed

**Drawbacks:**
- ❌ Inconsistent with other handlers
- ❌ Missed opportunity for cleanup

---

## Recommended Plan (Option A)

### Phase 1: Verify ImportService Signatures

**Action:** Check what ImportService methods actually expect

```bash
# Check ImportService method signatures
grep -A 20 "public function importFromExcel" lib/Service/ImportService.php
grep -A 20 "public function importFromCsv" lib/Service/ImportService.php
```

---

### Phase 2: Update ExportHandler

**File:** `lib/Service/Object/Handlers/ExportHandler.php`

**Changes:**

1. **Update import() method signature:**
```php
public function import(
    Register $register,
    array $uploadedFile,
    ?Schema $schema=null,  // ADD: Schema for CSV, null for Excel
    bool $validation=false,
    bool $events=false,
    bool $rbac=true,       // ADD: RBAC flag
    bool $multitenancy=true, // ADD: Multitenancy flag
    bool $publish=false,   // ADD: Publish flag for Excel
    ?IUser $currentUser=null
): array
```

2. **Add schema resolution for CSV:**
```php
// If CSV and no schema provided, get first from register
if ($extension === 'csv' && $schema === null) {
    $schemas = $register->getSchemas();
    if (empty($schemas)) {
        throw new \InvalidArgumentException('No schema found for register');
    }
    $schemaId = reset($schemas);
    $schema = $this->schemaMapper->find($schemaId);
}
```

3. **Pass all parameters to ImportService:**
```php
// For Excel
$result = $this->importService->importFromExcel(
    filePath: $uploadedFile['tmp_name'],
    register: $register,
    schema: $schema,
    validation: $validation,
    events: $events,
    _rbac: $rbac,
    _multitenancy: $multitenancy,
    publish: $publish,
    currentUser: $currentUser
);

// For CSV
$result = $this->importService->importFromCsv(
    filePath: $uploadedFile['tmp_name'],
    register: $register,
    schema: $schema,
    validation: $validation,
    events: $events,
    _rbac: $rbac,
    _multitenancy: $multitenancy,
    currentUser: $currentUser
);
```

---

### Phase 3: Update ObjectService

**File:** `lib/Service/ObjectService.php`

**Update importObjects() method:**
```php
public function importObjects(
    Register $register,
    array $uploadedFile,
    ?Schema $schema=null,
    bool $validation=false,
    bool $events=false,
    bool $rbac=true,
    bool $multitenancy=true,
    bool $publish=false,
    ?IUser $currentUser=null
): array {
    return $this->exportHandler->import(
        register: $register,
        uploadedFile: $uploadedFile,
        schema: $schema,
        validation: $validation,
        events: $events,
        rbac: $rbac,
        multitenancy: $multitenancy,
        publish: $publish,
        currentUser: $currentUser
    );
}
```

---

### Phase 4: Update Controller

**File:** `lib/Controller/ObjectsController.php`

**Simplified import() method:**
```php
public function import(int $register): JSONResponse
{
    try {
        // Get the uploaded file.
        $uploadedFile = $this->request->getUploadedFile('file');
        if ($uploadedFile === null) {
            return new JSONResponse(data: ['error' => 'No file uploaded'], statusCode: 400);
        }

        // Find the register.
        $registerEntity = $this->registerMapper->find($register);

        // Get optional schema for CSV (can be null).
        $schemaId = $this->request->getParam(key: 'schema');
        $schema = $schemaId ? $this->schemaMapper->find($schemaId) : null;

        // Get optional parameters.
        $validation = filter_var($this->request->getParam(key: 'validation', default: false), FILTER_VALIDATE_BOOLEAN);
        $events     = filter_var($this->request->getParam(key: 'events', default: false), FILTER_VALIDATE_BOOLEAN);
        $rbac       = filter_var($this->request->getParam(key: 'rbac', default: true), FILTER_VALIDATE_BOOLEAN);
        $multi      = filter_var($this->request->getParam(key: 'multi', default: true), FILTER_VALIDATE_BOOLEAN);
        $publish    = filter_var($this->request->getParam(key: 'publish', default: false), FILTER_VALIDATE_BOOLEAN);

        // Use ObjectService delegation to ExportHandler.
        $result = $this->objectService->importObjects(
            register: $registerEntity,
            uploadedFile: $uploadedFile,
            schema: $schema,
            validation: $validation,
            events: $events,
            rbac: $rbac,
            multitenancy: $multi,
            publish: $publish,
            currentUser: $this->userSession->getUser()
        );

        return new JSONResponse(
            data: [
                'message' => 'Import successful',
                'summary' => $result,
            ]
        );
    } catch (Exception $e) {
        return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 500);
    }
}
```

---

### Phase 5: Test & Verify

1. **Test Excel import**
   - Upload .xlsx file
   - Verify objects created

2. **Test CSV import with schema**
   - Upload .csv file with schema parameter
   - Verify objects created

3. **Test CSV import without schema**
   - Upload .csv file without schema parameter
   - Verify first schema used

4. **Run PHPQA**
   - Verify no new errors

---

## Benefits After Integration

### ✅ Cleaner Controller
- Reduced from ~80 lines to ~40 lines
- No file type switch statement
- No schema resolution logic

### ✅ Centralized Logic
- All import logic in ExportHandler
- Consistent logging
- Easier to test

### ✅ Better Error Handling
- Handler provides consistent error messages
- Logging for debugging

### ✅ Future Extensibility
- Easy to add new file types (JSON, XML, etc.)
- Parameters managed in one place

---

## Risk Assessment

### 🟡 Medium Risk

**Why:**
- Import is a critical operation
- Complex logic with multiple file types
- Schema resolution could break if not done correctly

**Mitigation:**
1. Test thoroughly with each file type
2. Keep original logic accessible for rollback
3. Verify ImportService signatures before changes
4. Test with real files

---

## Execution Order

1. ✅ Verify ImportService signatures
2. ✅ Update ExportHandler->import()
3. ✅ Update ObjectService->importObjects()
4. ✅ Update ObjectsController->import()
5. ✅ Test all file types
6. ✅ Run PHPQA
7. ✅ Create documentation

---

## Decision

**Proceed with Option A?**

Yes / No / Modify

---

**Created:** December 15, 2024  
**Status:** 📋 PLAN READY FOR EXECUTION  
**Estimated Time:** 30-45 minutes

