---
title: RAG Semantic Search Fix
sidebar_position: 75
---

# RAG Semantic Search - Fix Summary

## ✅ FIXED ISSUES

### 1. Critical Bug: Wrong Parameter Type to semanticSearch()

**File:** `lib/Service/ChatService.php`

**Problem:**
```php
// WRONG - Line 403:
$results = $this->vectorService->semanticSearch(
    $query,
    $numSources * 2,
    0.7  // ← FLOAT passed instead of array!
);
```

**Expected Signature:**
```php
public function semanticSearch(
    string $query,
    int $limit = 10,
    array $filters = [],  // ← Must be array!
    ?string $provider = null
): array
```

**Fix Applied:**
```php
// Build filters array based on agent settings
$vectorFilters = [];
$entityTypes = [];
if ($includeObjects) $entityTypes[] = 'object';
if ($includeFiles) $entityTypes[] = 'file';
if (!empty($entityTypes) && count($entityTypes) < 2) {
    $vectorFilters['entity_type'] = $entityTypes;
}

$results = $this->vectorService->semanticSearch(
    $query,
    $numSources * 2,
    $vectorFilters  // ✅ Correct array!
);
```

**Impact:** This bug caused semantic search to **fail completely**, returning empty/invalid results, which is why chat showed "Unknown Source".

### 2. fetchVectors() Didn't Support Array Filters

**File:** `lib/Service/VectorEmbeddingService.php`

**Problem:**
```php
// Only supported string comparison:
if (isset($filters['entity_type'])) {
    $qb->andWhere($qb->expr()->eq('entity_type', ...));  // Only =
}
```

**Fix Applied:**
```php
// Now supports both string and array:
if (isset($filters['entity_type'])) {
    if (is_array($filters['entity_type'])) {
        $qb->andWhere($qb->expr()->in('entity_type', ...));  // IN clause
    } else {
        $qb->andWhere($qb->expr()->eq('entity_type', ...));  // = comparison
    }
}
```

**Impact:** Now we can filter by multiple entity types: `['object', 'file']`

### 3. extractSourceName() Didn't Check Metadata

**File:** `lib/Service/ChatService.php`

**Problem:**
```php
// Only checked top-level fields, not metadata:
if (!empty($result['entity_id'])) {
    return ($result['entity_type'] ?? 'Item') . ' #' . $result['entity_id'];
}
return 'Unknown Source';  // ← Always fell back to this!
```

**Fix Applied:**
```php
// Now checks metadata for object_title, file_name, etc:
if (!empty($result['metadata'])) {
    $metadata = is_array($result['metadata']) 
        ? $result['metadata'] 
        : json_decode($result['metadata'], true);
    
    if (!empty($metadata['object_title'])) return $metadata['object_title'];
    if (!empty($metadata['file_name'])) return $metadata['file_name'];
    // ... more fallbacks
}
```

**Impact:** Source names now display correctly instead of "Unknown Source"

## Testing

After fixes, verify:
1. Semantic search returns results
2. Source names display correctly
3. Entity type filters work (objects only, files only, both)
4. Chat responses include proper source citations

## Related Documentation

- [Performance Optimization](./performance-optimization.md) - Performance improvements
- [Vector Search Backends](../technical/vector-search-backends.md) - Vector search implementation

