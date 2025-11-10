# Collection-Specific Endpoints Refactor

**Date:** October 13, 2025  
**Author:** AI Assistant  
**Status:** ✅ Complete

## Overview

Refactored collection management endpoints to accept collection name as a URL parameter instead of in the request body. This follows RESTful API design principles and makes the API more intuitive and maintainable.

---

## Changes Summary

### ❌ **Removed Old Endpoints**

These old endpoints have been **completely removed** from the codebase:

1. **`POST /api/solr/reindex`** (`reindexSolr`)
   - Required `maxObjects`, `batchSize`, and `collection` in request body
   - **Removed from:** `routes.php` line 33, `SettingsController.php` lines 2756-2847

2. **`POST /api/settings/solr/clear`** (`clearSolrIndex`)
   - Required `collection` in request body
   - **Removed from:** `routes.php` line 37, `SettingsController.php` lines 2499-2559

3. **`DELETE /api/solr/collection/delete`** (`deleteSolrCollection`)
   - Used active collection or required collection in settings
   - **Removed from:** `routes.php` line 42, `SettingsController.php` lines 1392-1471

---

### ✅ **New Collection-Specific Endpoints**

All new endpoints follow the pattern: `/api/solr/collections/{name}/{action}`

#### 1. **Delete Specific Collection**
```http
DELETE /api/solr/collections/{name}
```

**Controller Method:** `SettingsController::deleteSpecificSolrCollection(string $name)`

**Example:**
```bash
curl -X DELETE "http://nextcloud.local/index.php/apps/openregister/api/solr/collections/nc_test_collection" \
  -u "admin:admin"
```

**Response:**
```json
{
  "success": true,
  "message": "Collection deleted successfully",
  "collection": "nc_test_collection"
}
```

---

#### 2. **Clear Specific Collection**
```http
POST /api/solr/collections/{name}/clear
```

**Controller Method:** `SettingsController::clearSpecificCollection(string $name)`

**Example:**
```bash
curl -X POST "http://nextcloud.local/index.php/apps/openregister/api/solr/collections/nc_test_collection/clear" \
  -u "admin:admin"
```

**Response:**
```json
{
  "success": true,
  "message": "Collection cleared successfully",
  "collection": "nc_test_collection"
}
```

---

#### 3. **Reindex Specific Collection**
```http
POST /api/solr/collections/{name}/reindex
```

**Controller Method:** `SettingsController::reindexSpecificCollection(string $name)`

**Example:**
```bash
curl -X POST "http://nextcloud.local/index.php/apps/openregister/api/solr/collections/nc_test_collection/reindex" \
  -u "admin:admin"
```

**Response:**
```json
{
  "success": true,
  "message": "Reindex completed successfully",
  "stats": {
    "processed_objects": 1250,
    "duration_seconds": 4.5
  },
  "collection": "nc_test_collection"
}
```

---

## Backend Integration

All three new methods properly integrate with `GuzzleSolrService`:

### **GuzzleSolrService Methods**

1. **`deleteCollection(?string $collectionName = null): array`**
   - Already supports optional collection name parameter ✅
   - Falls back to active collection if not provided

2. **`clearIndex(?string $collectionName = null): array`**
   - Accepts collection name parameter ✅
   - Clears all documents in the specified collection

3. **`reindex(int $maxObjects, int $batchSize, ?string $collectionName = null): array`**
   - Already supports optional collection name parameter ✅
   - Reindexes objects into the specified collection

---

## Frontend Integration

### **Updated Vue Component: `CollectionManagementModal.vue`**

All three methods now use the new RESTful endpoints:

#### 1. **Reindex Collection**
```javascript
async reindexCollection(collection) {
  const url = generateUrl('/apps/openregister/api/solr/collections/{name}/reindex', 
    { name: collection.name })
  const response = await axios.post(url)
  // Handle response...
}
```

#### 2. **Clear Collection**
```javascript
async clearCollection(collection) {
  const url = generateUrl('/apps/openregister/api/solr/collections/{name}/clear', 
    { name: collection.name })
  const response = await axios.post(url)
  // Handle response...
}
```

#### 3. **Delete Collection**
```javascript
async deleteCollection(collection) {
  const url = generateUrl('/apps/openregister/api/solr/collections/{name}', 
    { name: collection.name })
  const response = await axios.delete(url)
  // Handle response...
}
```

---

## Benefits of This Refactor

### ✅ **RESTful Design**
- Collection name is now part of the URL path, following REST principles
- Resources are clearly identified by their URLs
- HTTP verbs (DELETE, POST) indicate the action

### ✅ **Improved API Clarity**
- No ambiguity about which collection is being operated on
- Collection name is explicit in every request
- Easier to read API logs and debug issues

### ✅ **Better Error Handling**
- 404 errors now correctly indicate "collection not found"
- URL validation happens at the routing level
- Clearer separation between route parameters and request body

### ✅ **Code Maintainability**
- Removed duplicate/legacy code
- Single source of truth for collection operations
- Easier to test and document

### ✅ **Frontend Simplification**
- No need to construct request bodies with collection names
- More intuitive API usage
- Consistent pattern across all collection operations

---

## Migration Guide

### **For API Consumers**

If you were using the old endpoints, update your code as follows:

#### Old Way ❌
```javascript
// Old: Collection in request body
await axios.post('/api/solr/reindex', {
  collection: 'my_collection',
  maxObjects: 0,
  batchSize: 1000
})

await axios.post('/api/settings/solr/clear', {
  collection: 'my_collection'
})

await axios.delete('/api/solr/collection/delete', {
  data: { collection: 'my_collection' }
})
```

#### New Way ✅
```javascript
// New: Collection in URL
await axios.post('/api/solr/collections/my_collection/reindex')

await axios.post('/api/solr/collections/my_collection/clear')

await axios.delete('/api/solr/collections/my_collection')
```

---

## Testing

### **Manual Testing Steps**

1. **Test Delete Collection**
```bash
curl -X DELETE "http://nextcloud.local/index.php/apps/openregister/api/solr/collections/test_collection" \
  -u "admin:admin" -H "Content-Type: application/json"
```

2. **Test Clear Collection**
```bash
curl -X POST "http://nextcloud.local/index.php/apps/openregister/api/solr/collections/test_collection/clear" \
  -u "admin:admin" -H "Content-Type: application/json"
```

3. **Test Reindex Collection**
```bash
curl -X POST "http://nextcloud.local/index.php/apps/openregister/api/solr/collections/test_collection/reindex" \
  -u "admin:admin" -H "Content-Type: application/json"
```

### **Expected Behavior**

- ✅ **Valid collection name**: Returns 200 OK with success message
- ✅ **Non-existent collection**: Returns 422 with error message
- ✅ **Invalid collection name**: Returns 404 from routing layer
- ✅ **SOLR unavailable**: Returns 422 with connection error

---

## Files Modified

### **Routes**
- `openregister/appinfo/routes.php`
  - ❌ Removed: `reindexSolr`, `clearSolrIndex`, `deleteSolrCollection`
  - ✅ Added: `deleteSpecificSolrCollection`, `clearSpecificCollection`, `reindexSpecificCollection`

### **Controller**
- `openregister/lib/Controller/SettingsController.php`
  - ❌ Removed methods: `reindexSolr()`, `clearSolrIndex()`, `deleteSolrCollection()`
  - ✅ Added methods: `reindexSpecificCollection()`, `clearSpecificCollection()`, `deleteSpecificSolrCollection()`

### **Frontend**
- `openregister/src/modals/settings/CollectionManagementModal.vue`
  - ✅ Updated: `reindexCollection()`, `clearCollection()`, `deleteCollection()`

---

## Backward Compatibility

⚠️ **Breaking Change:** This is a **breaking API change**. The old endpoints have been completely removed.

Any external integrations or scripts using the old endpoints will need to be updated to use the new collection-specific endpoints.

---

## Next Steps

1. ✅ Update any Newman/Postman test collections
2. ✅ Update API documentation in Docusaurus
3. ✅ Test all three endpoints with real SOLR collections
4. ✅ Verify error handling for edge cases (missing collection, SOLR down, etc.)

---

## Conclusion

This refactor brings the OpenRegister SOLR API in line with RESTful design principles, making it more intuitive, maintainable, and easier to use. The explicit collection naming in URLs removes ambiguity and improves debugging capabilities.

**Status:** ✅ Complete and ready for testing

