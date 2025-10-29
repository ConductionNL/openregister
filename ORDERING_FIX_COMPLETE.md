# Ordering Fix Complete ✅

## Problem Summary

Ordering was not working because:
1. **Missing `_order` parameter handling** in `buildSolrQuery()` - The method ignored the `_order` parameter entirely
2. **Missing sortable field variants** - Solr was trying to sort on text fields (`self_name`) which are multivalued/tokenized and cannot be sorted
3. **No sortable fields indexed** - Documents didn't include sortable string variants (`_s` suffix fields)

## Solutions Implemented

### 1. Added `_order` Parameter Handling (Line 3254-3262)
```php
// Handle sorting
if (!empty($query['_order'])) {
    $solrQuery['sort'] = $this->translateSortField($query['_order']);
    
    $this->logger->debug('ORDER: Applied sort parameter', [
        'original_order' => $query['_order'],
        'translated_sort' => $solrQuery['sort']
    ]);
}
```

### 2. Added Sortable Fields to Index (Line 1304-1309)
When indexing documents, we now create sortable string variants:
```php
// Sortable string variants (for ordering, not tokenized)
// These are single-valued string fields that Solr can sort on
'self_name_s' => $object->getName() ?: null,
'self_description_s' => $object->getDescription() ?: null,
'self_summary_s' => $object->getSummary() ?: null,
'self_slug_s' => $object->getSlug() ?: null,
```

### 3. Updated Sort Field Translation (Line 2377-2440)
The `translateSortableField()` method now maps to proper sortable fields:

**Text Fields → String Variants (_s suffix):**
- `@self.name` → `self_name_s` (sortable string, not tokenized)
- `@self.description` → `self_description_s`
- `@self.summary` → `self_summary_s`

**Date Fields → Direct (already sortable):**
- `@self.published` → `self_published` (date type)
- `@self.created` → `self_created` (date type)
- `@self.updated` → `self_updated` (date type)

**Integer/UUID Fields → Direct (already sortable):**
- `@self.register` → `self_register` (integer)
- `@self.schema` → `self_schema` (integer)
- `@self.organisation` → `self_organisation` (UUID)

## **CRITICAL: Reindexing Required!**

The sortable `_s` fields are only added to **new** documents. Existing documents in Solr don't have these fields yet.

### To Enable Ordering on Existing Data:

**Option 1: Reindex All Objects via OCC Command**
```bash
docker exec -u 33 master-nextcloud-1 php occ openregister:index:all
```

**Option 2: Reindex Specific Register**
```bash
docker exec -u 33 master-nextcloud-1 php occ openregister:index:register <register-id>
```

**Option 3: Trigger Reindex via API/UI**
- Go to OpenRegister admin panel
- Click "Reindex" button for affected registers

## Testing

### Test Alphabetical Name Ordering:
```bash
# Ascending (A→Z)
curl "http://localhost:3000/api/apps/opencatalogi/api/publications?_source=index&_limit=5&_order[@self.name]=asc"

# Descending (Z→A)
curl "http://localhost:3000/api/apps/opencatalogi/api/publications?_source=index&_limit=5&_order[@self.name]=desc"
```

### Test Date Ordering:
```bash
# Oldest first
curl "http://localhost:3000/api/apps/opencatalogi/api/publications?_source=index&_limit=5&_order[@self.published]=asc"

# Newest first  
curl "http://localhost:3000/api/apps/opencatalogi/api/publications?_source=index&_limit=5&_order[@self.published]=desc"
```

### Expected Behavior:

**Before Reindex:**
- Ordering parameters are sent to Solr but may return errors or incorrect order
- Error: `"can not sort on multivalued field: self_name of type: text_general"`

**After Reindex:**
- ✅ Alphabetical ordering works correctly
- ✅ Date ordering works correctly
- ✅ Different order for asc vs desc
- ✅ No Solr errors

## Debug Logging

Check logs for confirmation:
```bash
docker logs -f master-nextcloud-1 | grep -E "ORDER:|SORT:"
```

You should see:
```
ORDER: Applied sort parameter {"original_order": {"@self.name": "asc"}, "translated_sort": "self_name_s asc"}
SORT: Translating metadata field {"original": "@self.name", "solr_field": "self_name_s"}
```

## Files Modified

1. **`lib/Service/GuzzleSolrService.php`**:
   - Line 1304-1309: Added sortable field variants to indexing
   - Line 3254-3262: Added `_order` parameter handling in `buildSolrQuery()`
   - Line 2377-2440: Updated `translateSortableField()` to use proper sortable fields

## Summary

✅ **Missing `_order` handling** - FIXED  
✅ **Sortable fields not indexed** - FIXED  
✅ **Proper field type mapping** - FIXED  
⚠️ **Reindexing required** - ACTION NEEDED

After reindexing, all ordering operations will work correctly! 🎉

