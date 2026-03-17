# Magic Mapper SQL Indexing Strategy

## Automatische Index Detectie

De Magic Mapper bepaalt SQL indexing **automatisch** op basis van schema property configuratie en metadata velden. Er is geen expliciete index configuratie in de register JSON nodig.

## Welke Velden Krijgen Indexes?

### 1. Metadata Velden (Altijd Aanwezig)

Alle magic mapper tables hebben deze metadata columns met automatische indexes:

| Column | Type | Index | Gebruikt Voor |
|--------|------|-------|---------------|
| `_id` | BIGINT | PRIMARY KEY | Row identifier |
| `_uuid` | VARCHAR(36) | UNIQUE INDEX | Object UUID lookups |
| `_slug` | VARCHAR(255) | INDEX | URL routing en lookups |
| `_name` | VARCHAR(255) | **INDEX** | **_search queries!** |
| `_description` | TEXT | **GEEN INDEX** | _search queries (full scan) |
| `_summary` | TEXT | **GEEN INDEX** | _search queries (full scan) |
| `_register` | VARCHAR(255) | INDEX | Filter op register |
| `_schema` | VARCHAR(255) | INDEX | Filter op schema |
| `_owner` | VARCHAR(64) | INDEX | RBAC filtering |
| `_organisation` | VARCHAR(36) | INDEX | Multi-tenancy |
| `_created` | TIMESTAMP | INDEX | Tijdgebaseerde queries |
| `_updated` | TIMESTAMP | INDEX | Tijdgebaseerde queries |
| `_published` | TIMESTAMP | INDEX | Publicatie filtering |
| `_depublished` | TIMESTAMP | INDEX | Depublicatie filtering |
| `_expires` | TIMESTAMP | INDEX | Expiratie filtering |

### 2. Schema Properties met `facetable: true`

Properties die `facetable: true` hebben krijgen automatisch een SQL INDEX:

```json
{
  "properties": {
    "status": {
      "type": "string",
      "enum": ["active", "inactive"],
      "facetable": true    // ✅ SQL INDEX wordt aangemaakt!
    },
    "description": {
      "type": "string",
      "facetable": false   // ❌ Geen index
    }
  }
}
```

## _search Parameter Werking

De `_search` parameter zoekt in **3 metadata velden**:

```php
// MagicSearchHandler.php - applyFullTextSearch()
$searchConditions->add($qb->expr()->like('t._name', '%search%'));
$searchConditions->add($qb->expr()->like('t._description', '%search%'));
$searchConditions->add($qb->expr()->like('t._summary', '%search%'));
```

### ⚠️ Performance Probleem

**Alleen `_name` heeft een INDEX!** De andere twee velden (`_description` en `_summary`) zijn `TEXT` type zonder index, wat resulteert in:

- **Full table scan** voor elke `_search` query
- **Langzame queries** bij grote datasets (>10k objecten)
- **Database load** door LIKE queries op TEXT velden

### 💡 Oplossing: Full-Text Search Index

**Optie 1: PostgreSQL Full-Text Search**

```sql
-- Voeg GIN index toe voor full-text search (PostgreSQL)
CREATE INDEX idx_openregister_table_X_Y_fts 
ON oc_openregister_table_X_Y 
USING GIN (to_tsvector('english', 
    COALESCE(_name, '') || ' ' || 
    COALESCE(_description, '') || ' ' || 
    COALESCE(_summary, '')
));
```

**Optie 2: MySQL FULLTEXT Index**

```sql
-- Voeg FULLTEXT index toe (MySQL/MariaDB)
ALTER TABLE oc_openregister_table_X_Y 
ADD FULLTEXT INDEX idx_search_fields (_name, _description, _summary);
```

**Optie 3: Separate Search Service (Huidige Aanpak)**

Gebruik SOLR/Elasticsearch voor full-text search via `searchable: true` op schema niveau:

```json
{
  "components": {
    "schemas": {
      "publication": {
        "slug": "publication",
        "searchable": true,    // ✅ Geïndexeerd in SOLR
        "properties": { ... }
      }
    }
  }
}
```

## Best Practices voor Indexing

### ✅ Gebruik `facetable: true` voor:

1. **Filtering velden**:
   - Status enums (`status`, `type`, `state`)
   - Boolean flags (`published`, `listed`, `active`)
   - Foreign keys (`organizationId`, `catalogId`)

2. **Sorting velden**:
   - Titels (`title`, `name` - maar `_name` heeft al index!)
   - Numerieke waardes (`position`, `order`, `priority`)
   - Dates (`created`, `updated` - al indexed via metadata)

3. **Lookup velden**:
   - Unique identifiers (`oin`, `rsin`, `pki`)
   - Codes en slugs (`code`, `slug` - maar `_slug` heeft al index!)

4. **Relatie velden**:
   - Array properties die gefilterd worden (`themes`, `tags`)
   - Foreign key references

### ❌ Houd `facetable: false` voor:

1. **Long text velden**: `description`, `summary`, `content`
2. **Rich content**: Markdown, HTML, JSON
3. **Rarely queried**: Metadata die bijna nooit gefilterd wordt
4. **Very large content**: Files, embeddings, large JSON

## Metadata Velden vs Schema Properties

### Wanneer Gebruik je Metadata Velden?

De `_name`, `_description`, en `_summary` metadata velden worden **automatisch gevuld** vanuit:

1. Schema's `objectNameField` configuratie → `_name`
2. Schema's `objectSummaryField` configuratie → `_summary`  
3. Schema's `objectDescriptionField` configuratie → `_description`

**Voorbeeld:**

```json
{
  "components": {
    "schemas": {
      "publication": {
        "slug": "publication",
        "properties": {
          "title": { "type": "string" },
          "summary": { "type": "string" },
          "description": { "type": "string" }
        },
        "configuration": {
          "objectNameField": "title",           // → _name (INDEXED!)
          "objectSummaryField": "summary",      // → _summary (niet indexed)
          "objectDescriptionField": "description" // → _description (niet indexed)
        }
      }
    }
  }
}
```

### Waarom `_name` WEL indexeren?

`_name` heeft een **VARCHAR(255)** type en een **INDEX** omdat:

1. **Veel gebruikt** voor sorting: `?_order[name]=asc`
2. **Relatief kort** (max 255 chars) - efficiënt te indexeren
3. **Primaire identifier** voor gebruikers naast UUID
4. **Geen full-text search** nodig - gewone LIKE queries werken snel

### Waarom `_description` en `_summary` NIET indexeren?

Deze velden zijn **TEXT** type zonder index omdat:

1. **Erg lang** - indexes worden te groot
2. **Full-text search** vereist - reguliere indexes helpen niet veel
3. **Beter via SOLR/Elasticsearch** - geoptimaliseerd voor full-text
4. **Minder vaak gefilterd** - meestal alleen via `_search`

## Aanbeveling voor _search Performance

### Korte Termijn (Huidige Code):

Blijf SOLR/Elasticsearch gebruiken voor `_search` queries:

```json
{
  "slug": "publication",
  "searchable": true    // ✅ Index in SOLR voor snelle full-text search
}
```

### Lange Termijn (Optimalisatie):

Implementeer database-native full-text search als fallback wanneer SOLR niet beschikbaar is:

1. **PostgreSQL**: GIN index met `to_tsvector()`
2. **MySQL**: FULLTEXT index op `_name`, `_description`, `_summary`
3. **Automatische detectie**: Gebruik SOLR als beschikbaar, anders database FTS

## Index Overhead

### Storage Overhead:

Elke index voegt ~30-50% storage toe per kolom:

```
Tabel size: 1GB
Met 5 extra indexes: ~1.35GB (+35%)
```

### Write Performance:

Meer indexes = tragere inserts/updates:

```
Geen extra indexes:  10ms insert
5 extra indexes:     15ms insert (+50%)
10 extra indexes:    25ms insert (+150%)
```

### Query Performance Gain:

Goede indexes leveren 10-100x sneller queries:

```
Zonder index: 450ms (full table scan)
Met index:    15ms (index seek)
Verbetering:  30x sneller!
```

## Monitoring Query Performance

### Check Welke Queries Langzaam Zijn:

```sql
-- PostgreSQL: Enable query logging
SET log_min_duration_statement = 100;  -- Log queries > 100ms

-- MySQL: Enable slow query log
SET GLOBAL slow_query_log = 'ON';
SET GLOBAL long_query_time = 0.1;  -- Log queries > 100ms
```

### Analyseer Query Plans:

```sql
-- PostgreSQL
EXPLAIN ANALYZE 
SELECT * FROM oc_openregister_table_5_12 
WHERE status = 'active' 
ORDER BY _name;

-- MySQL
EXPLAIN 
SELECT * FROM oc_openregister_table_5_12 
WHERE status = 'active' 
ORDER BY _name;
```

Zoek naar:
- ❌ `Seq Scan` (PostgreSQL) of `ALL` (MySQL) = full table scan
- ✅ `Index Scan` of `ref` = index gebruikt

## Conclusie

1. **Metadata indexes** worden automatisch aangemaakt voor belangrijke velden
2. **`_name` is WEL indexed** - goed voor sorting en exacte matches
3. **`_description` en `_summary` zijn NIET indexed** - gebruik SOLR voor full-text
4. **`facetable: true`** triggt automatisch index creatie voor schema properties
5. **Balanceer storage/write overhead** tegen query performance gains
6. **Monitor slow queries** en voeg indexes toe waar nodig

## Zie Ook

- [Magic Mapper Configuration](./magic-mapper.md)
- [SOLR Integration](../features/search/solr-integration.md)
- [Performance Optimization](../features/performance/optimization.md)


