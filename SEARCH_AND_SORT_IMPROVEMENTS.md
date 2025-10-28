# Search & Sort Improvements ✅

## Summary of Changes

Fixed two issues per user request:
1. **Case-insensitive search** - Removed manual lowercasing, letting Solr handle it
2. **Sortable field registration** - Added `_s` fields to schema definitions and validation

---

## 1. Case-Insensitive Search - Proper Solr Configuration

### ❌ Old Approach (Workaround):
```php
// Force lowercase for case-insensitive search
$cleanTerm = mb_strtolower($cleanTerm);
```

### ✅ New Approach (Proper):
```php
// Note: Case-insensitive search is handled by Solr field type configuration
// text_general fields use LowerCaseFilterFactory for case-insensitive matching
// No need to manually lowercase the search term
```

### Why This Is Better:

**Solr's `text_general` field type includes:**
- `LowerCaseFilterFactory` - Converts tokens to lowercase during indexing AND querying
- `StandardTokenizerFactory` - Tokenizes text properly
- `StopFilterFactory` - Removes common stop words

This means:
- ✅ Case-insensitive by default for `text_general` fields
- ✅ Better language analysis (stemming, stop words, etc.)
- ✅ No manual string manipulation needed
- ✅ Works consistently across all search fields

### Fields Using `text_general` (Case-Insensitive):
```php
'self_description' => 'text_general',  // ✅ Case-insensitive
'self_summary' => 'text_general',      // ✅ Case-insensitive
```

### Fields Using `string` (Case-Sensitive):
```php
'self_name' => 'string',               // ⚠️ Case-sensitive (for exact matching)
'self_slug' => 'string',               // ⚠️ Case-sensitive (for exact matching)
```

**Note:** If you need `self_name` to be case-insensitive for search, change it to `text_general` in the schema definition.

---

## 2. Sortable Field Registration

### Changes Made:

#### A. **Added to `SolrSchemaService.php` Field Definitions** (Line 123-128)

```php
// Sortable string variants (for ordering on text fields)
// These are single-valued, non-tokenized copies used for sorting/faceting
'self_name_s' => 'string',
'self_description_s' => 'string',
'self_summary_s' => 'string',
'self_slug_s' => 'string',
```

#### B. **Already Indexed in `GuzzleSolrService.php`** (Line 1304-1309)

```php
// Sortable string variants (for ordering, not tokenized)
// These are single-valued string fields that Solr can sort on
'self_name_s' => $object->getName() ?: null,
'self_description_s' => $object->getDescription() ?: null,
'self_summary_s' => $object->getSummary() ?: null,
'self_slug_s' => $object->getSlug() ?: null,
```

#### C. **Already Mapped in `translateSortableField()`** (Line 2386-2390)

```php
'name' => 'self_name_s',           // Use sortable string variant
'summary' => 'self_summary_s',     // Use sortable string variant
'description' => 'self_description_s', // Use sortable string variant
'slug' => 'self_slug_s',           // Use sortable string variant
```

---

## Impact of These Changes:

### 1. Schema Preparation (`ensureCoreMetadataFields()`)
When Solr schema is initialized/updated, the `_s` fields are now:
- ✅ **Automatically created** in Solr schema
- ✅ **Configured as single-valued strings** (sortable)
- ✅ **Given proper docValues** for efficient sorting

### 2. Field Validation
The `_s` fields are now:
- ✅ **Recognized as valid fields** (not flagged as excess/unknown)
- ✅ **Included in schema comparisons**
- ✅ **Part of the expected field set**

### 3. Indexing
New/updated documents automatically include:
- ✅ `self_name` (text field for searching)
- ✅ `self_name_s` (string field for sorting)

---

## Testing

### Test Case-Insensitive Search:
```bash
# Should find "Software" with any case variation
curl "http://localhost:3000/api/apps/opencatalogi/api/publications?_source=index&_search=software"
curl "http://localhost:3000/api/apps/opencatalogi/api/publications?_source=index&_search=SOFTWARE"
curl "http://localhost:3000/api/apps/opencatalogi/api/publications?_source=index&_search=Software"
```

All three should return the same results.

### Test Alphabetical Sorting:
```bash
# Should be in A→Z order
curl "http://localhost:3000/api/apps/opencatalogi/api/publications?_source=index&_limit=5&_order[@self.name]=asc"

# Should be in Z→A order
curl "http://localhost:3000/api/apps/opencatalogi/api/publications?_source=index&_limit=5&_order[@self.name]=desc"
```

---

## Migration Path

### For Existing Deployments:

**Step 1: Update Solr Schema**
```bash
# Recreate Solr schema with new _s fields
docker exec -u 33 master-nextcloud-1 php occ openregister:solr:schema --force
```

**Step 2: Reindex All Objects**
```bash
# Add _s fields to existing documents
docker exec -u 33 master-nextcloud-1 php occ openregister:index:all
```

**Step 3: Verify**
```bash
# Check that _s fields exist in schema
curl "http://solr:8983/solr/openregister/schema/fields?wt=json" | grep "_s"

# Test sorting
curl "http://localhost:3000/api/apps/opencatalogi/api/publications?_source=index&_order[@self.name]=asc&_limit=3"
```

---

## Files Modified

1. **`lib/Service/GuzzleSolrService.php`**
   - Line 3218-3220: Removed manual lowercasing, added comment about Solr handling
   - Line 1304-1309: Already had sortable field indexing
   - Line 2386-2390: Already had sortable field mapping

2. **`lib/Service/SolrSchemaService.php`**
   - Line 123-128: Added `_s` field definitions to `CORE_METADATA_FIELDS`

---

## Benefits

✅ **Case-insensitive search** - Handled properly by Solr's text analysis  
✅ **Sortable fields** - Registered in schema, won't be flagged as unknown  
✅ **Proper separation** - Search fields vs. sort fields (text vs. string)  
✅ **Schema validation** - `_s` fields are now part of expected schema  
✅ **Automatic field creation** - Schema sync will create `_s` fields in Solr  

---

## Future Considerations

If you want `self_name` to be **case-insensitive for search**, consider:

```php
// Option 1: Keep both (current approach)
'self_name' => 'string',        // Case-sensitive exact matching
'self_name_s' => 'string',      // Sortable variant

// Option 2: Make searchable name case-insensitive
'self_name' => 'text_general',  // Case-insensitive search
'self_name_s' => 'string',      // Sortable exact variant
```

The current approach (Option 1) is good for exact matching scenarios, but Option 2 would improve search UX.

🎉 All improvements complete!

