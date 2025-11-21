---
title: Search
sidebar_position: 5
description: An overview of how core concepts in Open Register interact with each other.
keywords:
  - Open Register
  - Core Concepts
  - Relationships
---

# Advanced Search

Open Register provides powerful search capabilities that allow filtering objects based on their properties using a flexible query syntax.

## Automatic Facets

OpenRegister provides a comprehensive faceting system that enables dynamic filtering based on object properties and metadata. The system supports multiple facet types and automatic field discovery.

For detailed information about faceting capabilities, see the **[Comprehensive Faceting Documentation](../Features/faceting.md)**.

### Key Faceting Features

- **Facet Types**: Terms, date histograms, and numeric ranges
- **Automatic Discovery**: Use `_facetable=true` to discover available facetable fields
- **Schema-Based**: Facets configured via schema properties marked with `facetable: true`
- **UUID Resolution**: Automatic conversion of UUIDs to human-readable names
- **Disjunctive Faceting**: Facet options remain visible even when selected
- **Two-Stage System**: Discovery (`_facetable=true`) and data retrieval (`_facets[...]`)

### Quick Example

```bash
# Discover available facets
GET /api/objects/5/24?_facetable=true&_limit=0

# Get facet data
GET /api/objects/5/24?_facets[@self][register][type]=terms&_facets[status][type]=terms
```

## Content Search

Content Search provides powerful search capabilities across objects and their associated files through text extraction and indexing.

For detailed information about text extraction, see the **[Text Extraction Sources Documentation](../features/text-extraction-sources.md)**.

### Text Extraction Overview

OpenRegister processes content from two distinct sources:

1. **Files**: Documents (PDF, DOCX), images (OCR), spreadsheets, and text files
2. **Objects**: Structured data entities with property values extracted as text

### Processing Pipeline

Both sources go through:
- **Text Extraction**: Files via LLPhant/Dolphin, objects via property concatenation
- **Chunking**: Smart text splitting (recursive or fixed-size strategies)
- **Solr Indexing**: Full-text search indexing
- **Optional Enhancements**: Vector embeddings, entity extraction, language detection

### Supported File Types

- **Documents**: PDF, DOCX, DOC, ODT, RTF
- **Spreadsheets**: XLSX, XLS, CSV
- **Presentations**: PPTX
- **Text Files**: TXT, MD, HTML, JSON, XML
- **Images**: JPG, PNG, GIF, WebP, TIFF (via OCR)

### Search Integration

Extracted text is indexed in Solr, enabling:
- Full-text search across file content and object properties
- Combined search results from files and objects
- Faceted search by file type, metadata, and object properties

## Solr Integration

OpenRegister uses Apache Solr as its search engine, providing advanced search and analytics capabilities for objects and their content.

## Overview

The search system enables:
- Full-text search
- Metadata search
- File content search
- Advanced query options
- **[Saved Views](../features/views.md)** - Save and reuse complex search configurations

## Key Benefits

1. **Discovery**
   - Find relevant content
   - Explore relationships
   - Discover patterns

2. **Integration**
   - Combined search results
   - Unified interface
   - Rich filtering

3. **Performance**
   - Optimized indexing
   - Fast results
   - Scalable search

## Overview

The search system enables you to filter objects using query parameters. However, it's important to note that this approach is limited by the maximum URL length supported by browsers and servers (typically 2,048 characters for most browsers).

For more complex queries that exceed URL length limitations, we are planning to implement GraphQL support in the future. This would enable:

- Deeply nested queries
- Complex filtering logic
- Precise field selection
- Batch operations
- Real-time subscriptions

> Note: GraphQL implementation is currently pending funding. If you're interested in supporting this feature, please contact us.

In the meantime, here are the available search capabilities using URL parameters:

## Open Catalogi Integration

If you have **Open Catalogi** installed alongside OpenRegister, you gain access to the more flexible `/api/publications` endpoint. This endpoint provides enhanced filtering capabilities that allow you to search across multiple registers and schemas without being constrained by URL path parameters.

### Publications Endpoint Advantages

The publications endpoint (`/api/publications`) offers several advantages over register/schema-specific endpoints:

- **Cross-register searching**: Filter objects from multiple registers simultaneously
- **Dynamic schema filtering**: Switch between schemas without changing the endpoint
- **Cleaner URLs**: No need to include register/schema in the URL path
- **More flexible queries**: Better support for complex filter combinations

### Register and Schema Filtering in Publications

When using the publications endpoint, register and schema become regular query parameters instead of URL path components:

#### Basic Publications Filtering
```bash
# Search across all registers and schemas
GET /api/publications

# Filter by specific register
GET /api/publications?register=5

# Filter by specific schema  
GET /api/publications?schema=24

# Filter by both register and schema
GET /api/publications?register=5&schema=24
```

#### Multiple Register/Schema Filtering
```bash
# Objects from multiple registers
GET /api/publications?register[]=5&register[]=6&register[]=7

# Objects from multiple schemas
GET /api/publications?schema[]=24&schema[]=25

# Complex combinations
GET /api/publications?register[]=5&register[]=6&schema[]=24&schema[]=25
```

#### Combined with Metadata Filters
```bash
# Date filtering across multiple registers
GET /api/publications?register[]=5&register[]=6&@self[created][gte]=2025-06-25T00:00:00

# Schema filtering with content search
GET /api/publications?schema=24&@self[title][~]=budget&_search=annual

# Complex multi-criteria search
GET /api/publications?register=5&schema[]=24&schema[]=25&@self[published][exists]=true&@self[created][gte]=2025-01-01T00:00:00
```

### Comparison: OpenRegister vs Publications Endpoints

| Feature | OpenRegister Endpoint | Publications Endpoint |
|---------|----------------------|----------------------|
| URL Format | `/api/objects/5/24?filters` | `/api/publications?register=5&schema=24&filters` |
| Cross-register | ❌ Not supported | ✅ Supported |
| Multiple schemas | ❌ Single schema only | ✅ Multiple schemas |
| URL complexity | Higher (path parameters) | Lower (query parameters) |
| Filter flexibility | Limited by path structure | Full query parameter flexibility |

### Migration Example

Convert existing OpenRegister queries to use the publications endpoint:

```bash
# OpenRegister format
GET /api/objects/5/24?@self[created][gte]=2025-06-25T00:00:00&@self[title][~]=budget

# Equivalent Publications format  
GET /api/publications?register=5&schema=24&@self[created][gte]=2025-06-25T00:00:00&@self[title][~]=budget

# Enhanced Publications format (multiple schemas)
GET /api/publications?register=5&schema[]=24&schema[]=25&@self[created][gte]=2025-06-25T00:00:00&@self[title][~]=budget
```

### When to Use Each Endpoint

**Use OpenRegister endpoints (`/api/objects/{register}/{schema}`) when:**
- Working with a single, specific register and schema
- Building register-specific applications
- Need guaranteed register/schema validation in URL routing

**Use Publications endpoint (`/api/publications`) when:**
- Searching across multiple registers or schemas
- Building general-purpose search interfaces
- Need maximum query flexibility
- Want cleaner, more maintainable URLs

:::tip Open Catalogi Installation

The publications endpoint requires Open Catalogi to be installed and configured alongside OpenRegister. If you don't see the `/api/publications` endpoint, check your Open Catalogi installation.

:::

## Metadata Filtering

OpenRegister supports comprehensive filtering on object metadata using query parameters. All metadata fields can be filtered using the '@self' prefix followed by the field name and optional operator.

### Core Metadata Fields

The following metadata fields are available for filtering on all objects:

#### System Fields
| Field | Description | Type | Example |
|-------|-------------|------|---------|
| `id` | Object unique identifier | UUID | `@self[id]=550e8400-e29b-41d4-a716-446655440000` |
| `uuid` | Object universal unique ID | UUID | `@self[uuid]=550e8400-e29b-41d4-a716-446655440000` |
| `register` | Register ID or slug | Integer/String | `@self[register]=5` or `@self[register]=pets` |
| `schema` | Schema ID or slug | Integer/String | `@self[schema]=24` or `@self[schema]=animal` |
| `version` | Object version number | String | `@self[version]=1.0.0` |
| `size` | Object size in bytes | String | `@self[size][gte]=1024` |
| `schemaVersion` | Schema version used | String | `@self[schemaVersion]=2.1.0` |

#### Timestamps
| Field | Description | Type | Example |
|-------|-------------|------|---------|
| `created` | Creation timestamp | DateTime | `@self[created][gte]=2025-06-25T00:00:00` |
| `updated` | Last update timestamp | DateTime | `@self[updated][lt]=2025-06-30T23:59:59` |
| `published` | Publication timestamp | DateTime | `@self[published][gte]=2025-01-01T00:00:00` |
| `depublished` | Depublication timestamp | DateTime | `@self[depublished][lte]=2025-12-31T23:59:59` |

#### Content Fields
| Field | Description | Type | Example |
|-------|-------------|------|---------|
| `name` | Object name | String | `@self[name][~]=Annual Report` |
| `description` | Object description | String | `@self[description][$]=implementation` |
| `uri` | Object URI | String | `@self[uri][^]=https://api.example.com` |
| `folder` | Object folder path | String | `@self[folder][~]=documents` |

#### Ownership & Access
| Field | Description | Type | Example |
|-------|-------------|------|---------|
| `organization` | Owning organization ID | UUID | `@self[organization]=550e8400-e29b-41d4-a716-446655440000` |
| `application` | Source application ID | UUID | `@self[application]=550e8400-e29b-41d4-a716-446655440000` |
| `owner` | Object owner | String | `@self[owner]=john.doe` |
| `license` | Object license | String | `@self[license]=CC-BY-4.0` |
| `source` | Data source identifier | String | `@self[source]=external-api` |

#### Status & State
| Field | Description | Type | Example |
|-------|-------------|------|---------|
| `hash` | Object content hash | String | `@self[hash]=a1b2c3d4e5f6` |
| `uri` | Object URI | String | `@self[uri][~]=api.example.com` |

### Filtering Operators

All metadata fields support the following operators for precise filtering:

| Operator | Description | Example |
|----------|-------------|---------|
| `=` | Equals (case insensitive) | `@self[name]=annual report` |
| `ne` | Not equals (case insensitive) | `@self[status][ne]=draft` |
| `gt` | Greater than | `@self[version][gt]=1` |
| `lt` | Less than | `@self[version][lt]=5` |
| `gte` | Greater than or equal | `@self[created][gte]=2025-01-01T00:00:00` |
| `lte` | Less than or equal | `@self[updated][lte]=2025-12-31T23:59:59` |
| `~` | Contains (case insensitive) | `@self[description][~]=budget` |
| `^` | Starts with (case insensitive) | `@self[name][^]=annual` |
| `$` | Ends with (case insensitive) | `@self[name][$]=2025` |
| `===` | Equals (case sensitive) | `@self[name][===]=Annual Report` |
| `exists` | Property exists check | `@self[published][exists]=true` |
| `empty` | Empty value check | `@self[summary][empty]=true` |
| `null` | Null value check | `@self[depublished][null]=true` |

:::info Why Operator Names Instead of Mathematical Symbols?

We use operator names like `gte`, `lte`, `gt`, `lt`, and `ne` instead of mathematical symbols like `>=`, `<=`, `>`, `<`, and `!=` to avoid URL encoding problems.

**The Problem:**
When using mathematical symbols in URL parameters, they get URL encoded:
- `age[>=]=5` becomes `age[%3E=]=5` 
- `weight[<=]=10` becomes `weight[%3C=]=10`

This URL encoding can cause parsing issues with PHP's `$_GET` parameter processing, especially when operators are used within array key brackets.

**The Solution:**
Using descriptive operator names ensures clean, readable URLs:
- `age[gte]=5` (greater than or equal)
- `weight[lte]=10` (less than or equal)
- `name[ne]=test` (not equal)

This approach maintains compatibility across different web servers and ensures reliable parameter parsing.

:::

### Metadata Filtering Examples

#### Basic Metadata Filtering
```
# Filter by register
GET /api/objects/5/24?@self[register]=5

# Filter by creation date
GET /api/objects/5/24?@self[created][gte]=2025-06-01T00:00:00

# Filter by name containing specific text
GET /api/objects/5/24?@self[name][~]=budget

# Filter by published objects
GET /api/objects/5/24?@self[published][exists]=true
```

#### Advanced Metadata Combinations
```
# Objects created after date AND belonging to specific organization
GET /api/objects/5/24?@self[created][gte]=2025-01-01T00:00:00&@self[organization]=550e8400-e29b-41d4-a716-446655440000

# Published objects with specific name pattern
GET /api/objects/5/24?@self[published][exists]=true&@self[name][^]=Annual

# Objects updated within date range
GET /api/objects/5/24?@self[updated][gte]=2025-06-01T00:00:00&@self[updated][lte]=2025-06-30T23:59:59

# Exclude draft objects
GET /api/objects/5/24?@self[status][ne]=draft
```

#### Register and Schema Filtering
```
# Objects from multiple registers (when using general search endpoints)
GET /api/search?@self[register][]=5&@self[register][]=6

# Objects with specific schema
GET /api/search?@self[schema]=24

# Combined register, schema, and date filter
GET /api/search?@self[register]=5&@self[schema]=24&@self[created][gte]=2025-06-25T00:00:00
```

## Date Format Handling

When working with date fields like `created`, `updated`, `published`, and `depublished`, it's important to understand the date format requirements.

### Standard Date Format

Open Register uses ISO 8601 date format **without timezone suffixes** for optimal database compatibility:

**Correct Format:** `2025-06-23T14:30:00`  
**Avoid:** `2025-06-23T14:30:00.000Z` or `2025-06-23T14:30:00+00:00`

### Date Range Examples

```
# Objects created after June 21, 2025 at 22:00
GET /api/objects?@self[created][gte]=2025-06-21T22:00:00

# Objects updated before June 25, 2025 
GET /api/objects?@self[updated][lt]=2025-06-25T00:00:00

# Objects published within a specific date range
GET /api/objects?@self[published][gte]=2025-06-01T00:00:00&@self[published][lte]=2025-06-30T23:59:59
```

### Frontend Implementation

When implementing date filters in JavaScript/frontend code, ensure you convert dates to the correct format:

```javascript
// Convert Date object to OpenRegister format
const formatDateForOpenRegister = (date) => {
    if (!date) return null
    // Remove timezone info to match database format
    return new Date(date).toISOString().replace(/\.000Z$/, '')
}

// Example usage
const fromDate = formatDateForOpenRegister(new Date('2025-06-21'))
// Result: '2025-06-21T00:00:00'
```

:::tip
This standardized format ensures consistent date comparisons across different timezones and database configurations.

:::

## Search User Interface

OpenRegister provides an optimized search interface with explicit search actions and multiple search term support.

### Explicit Search Action

Search operations require explicit user action rather than automatic triggering:

**Features:**
- **User-Controlled**: Search executes only when user clicks Search button or presses Enter
- **Loading Indicators**: Visual feedback during search execution
- **Performance Statistics**: Real-time display of search execution time and result counts
- **No Automatic Triggers**: Eliminates excessive API calls from typing

**Benefits:**
- **Reduced Server Load**: Fewer unnecessary API calls (1 call vs 4+ for typing 'test')
- **Better Performance**: Only executes when user intends to search
- **User Feedback**: Clear indication of search progress and results

### Multiple Search Terms Support

The search interface supports multiple search terms:

**Features:**
- **Comma or Space Separated**: Enter multiple terms separated by commas or spaces
- **Visual Term Chips**: Each search term displayed as a removable chip
- **Individual Term Removal**: Remove specific terms without re-entering others
- **Combined Search**: All terms searched together across object fields

**Example:**
```
Search: "budget, annual, 2025"
Results: Objects containing all three terms
```

### Search Performance Monitoring

The system provides real-time performance feedback:

**Statistics Displayed:**
- Total results found
- Search execution time in milliseconds
- Search term count

**Performance Benefits:**
- Transparency for users
- Debugging information for developers
- Performance trend tracking

## Full Text Search

The '_search' parameter allows searching across all text properties of objects in a case-insensitive way:

```
GET /api/pets?_search=nemo
```

This searches for "nemo" in all text fields like name, description, notes etc.

**Multiple Search Terms:**
```
GET /api/pets?_search=budget annual 2025
```

Searches for objects containing all specified terms.

### Wildcard Search
You can use wildcards in the search term:

- `*` matches zero or more characters
```
GET /api/pets?_search=ne*o
``` 
Matches "nemo", "negro", "neuro" etc.

- `?` matches exactly one character
```
GET /api/pets?_search=ne?o
```
Matches "nemo", "nero" but not "neuro"

### Pattern Matching
- `^` matches start of text
```
GET /api/pets?_search=^ne
```
Matches text starting with "ne"

- `$` matches end of text
```
GET /api/pets?_search=mo$
```
Matches text ending with "mo"

### Phrase Search
Use quotes for exact phrase matching:
```
GET /api/pets?_search="orange fish"
```
Matches the exact phrase "orange fish"

## Basic Search

Simple equals search (case insensitive):
```
GET /api/pets?name=nemo
```

This returns all pets named "nemo", "Nemo", "NEMO", etc.

Case sensitive search:
```
GET /api/pets?name[===]=Nemo
```

This returns only pets named exactly "Nemo".

## Comparison Operators

### Not Equals `ne`
```
GET /api/pets?name[ne]=nemo
```
Returns all pets NOT named "nemo" (case insensitive)

### Greater Than `gt`
```
GET /api/pets?age[gt]=5
```
Returns pets older than 5 years

### Less Than `lt`
```
GET /api/pets?weight[lt]=10
```
Returns pets weighing less than 10kg

### Greater Than or Equal `gte`
```
GET /api/pets?age[gte]=2
```
Returns pets 2 years or older

### Less Than or Equal `lte`
```
GET /api/pets?age[lte]=10
```
Returns pets 10 years or younger

### Contains `~`
```
GET /api/pets?name[~]=ne
```
Returns pets with "ne" in their name (like "nemo", "nero", "Nemo", etc) - case insensitive

### Starts With `^`
```
GET /api/pets?name[^]=ne
```
Returns pets whose names start with "ne" (case insensitive)

### Ends With `$`
```
GET /api/pets?name[$]=mo
```
Returns pets whose names end with "mo" (case insensitive)

## Combining Multiple Conditions

### AND Operations
```
GET /api/pets?name=nemo&type=fish
```
Returns pets named "nemo" (case insensitive) AND of type "fish"

### OR Operations
```
GET /api/pets?name[]=nemo&name[]=dory
```
Returns pets named either "nemo" OR "dory" (case insensitive)

## Special Filters

### Exists Check
```
GET /api/pets?microchip[exists]=true
```
Returns pets that have a microchip property

### Empty Check
```
GET /api/pets?notes[empty]=true
```
Returns pets with empty notes

### Null Check
```
GET /api/pets?owner[null]=true
```
Returns pets with no owner

### Between Range
```
GET /api/pets?age[gte]=2&age[lte]=5
```
Returns pets between 2 and 5 years old (inclusive)

```
GET /api/pets?age[gt]=2&age[lt]=5
``` 
Returns pets between 2 and 5 years old (exclusive)

## Searching Nested Properties

```
GET /api/pets?owner.city=Amsterdam
```
Returns pets whose owners live in Amsterdam (case insensitive)

```
GET /api/pets?vaccinations.date[gt]=2023-01-01
```
Returns pets with vaccinations after January 1st, 2023

## Best Practices

1. Use URL encoding for special characters
2. Keep queries focused and specific
3. Use pagination for large result sets
4. Consider URL length limitations
5. Break complex queries into multiple requests if needed

## Performance Considerations

### Indexing
- Core metadata fields (`id`, `uuid`, `created`, `updated`, `register`, `schema`) are automatically indexed
- Date fields support efficient range queries
- String fields with operators like `~`, `^`, `$` may be slower on large datasets

### Query Optimization
```
# Efficient: Use indexed fields first
GET /api/objects/5/24?@self[created][gte]=2025-01-01&@self[title][~]=budget

# Less efficient: Complex string operations on large datasets
GET /api/objects/5/24?@self[description][~]=very_specific_text
```

### Pagination with Metadata Filters
```
# Always use pagination with filters
GET /api/objects/5/24?@self[created][gte]=2025-01-01&_limit=50&_offset=0
```

## Search Engine Implementation

### Solr Integration

OpenRegister uses Apache Solr as its search engine, providing powerful full-text search and faceting capabilities.

#### Case-Insensitive Search

All text searches are case-insensitive by default. The search implementation uses 'mb_strtolower()' to normalize search terms before querying Solr:

```php
// Search terms are normalized to lowercase
$cleanTerm = mb_strtolower($cleanTerm);
```

This ensures that searches work consistently regardless of input case:
- 'software' = 'SOFTWARE' = 'SoFtWaRe' (all return the same results)
- Handles international characters correctly (é, ñ, ü, etc.)
- Works with UTF-8 encoded strings

The normalization happens before the query is sent to Solr, ensuring reliable search behavior across different Solr configurations.

#### Ordering and Sorting

Objects can be ordered by metadata fields using the '_order' parameter:

**Sortable Fields:**
- '@self.name' - Alphabetical by object name (uses 'self_name_s' Solr field)
- '@self.published' - Chronological by published date
- '@self.created' - Chronological by creation date
- '@self.updated' - Chronological by update date

**Sorting Directions:**
- 'asc' - Ascending order (A→Z, oldest→newest)
- 'desc' - Descending order (Z→A, newest→oldest)

**Examples:**
```
# Alphabetical order (A→Z)
GET /api/objects?_source=index&_order[@self.name]=asc

# Reverse alphabetical (Z→A)
GET /api/objects?_source=index&_order[@self.name]=desc

# Newest first
GET /api/objects?_source=index&_order[@self.published]=desc

# Oldest first
GET /api/objects?_source=index&_order[@self.created]=asc
```

**Technical Implementation:**
- Text fields use sortable string variants (with '_s' suffix)
- Solr schema includes sortable copies of searchable fields
- Date fields use native Solr date sorting
- Field mapping handled by 'translateSortableField()' method

#### Search Performance

**Weighted Field Search:**
The search engine uses weighted field boosting to prioritize matches in certain fields:

```php
// Example weights
'self_name^10'        // Name field has highest priority
'self_title^8'        // Title field is second priority
'self_description^5'  // Description has medium priority
'self_summary^3'      // Summary has lower priority
```

This ensures that matches in object names are ranked higher than matches in descriptions.

**Field Types in Solr:**
- 'text_general' - Analyzed text fields (tokenized, lowercased, stemmed)
- 'string' - Exact value fields (not tokenized, for sorting/faceting)
- 'date' - ISO 8601 date fields (for range queries and sorting)
- UUID fields - String fields for identifiers

#### Testing Search

The search implementation includes comprehensive integration tests:

**Test Coverage:**
- Case-insensitive search (lowercase, uppercase, mixed case)
- Ordering by name (ascending, descending)
- Ordering by dates (chronological, reverse chronological)
- Combined filters with search
- Pagination with ordering

**Running Tests:**
```bash
# Run all search tests
vendor/bin/phpunit tests/Integration/CoreIntegrationTest.php --filter "testCaseInsensitive|testOrdering"

# Run specific test
vendor/bin/phpunit tests/Integration/CoreIntegrationTest.php --filter testCaseInsensitiveSearchLowercase
```

See 'SOLR_TESTING_GUIDE.md' in the repository for detailed testing procedures.

## Search Trails

Search trails provide comprehensive logging and analytics for search operations within OpenRegister. This feature allows administrators to track, analyze, and optimize search performance across all registers and schemas.

### Overview

Search trails automatically capture detailed information about every search operation performed in the system, including:

- **Search Terms**: What users are searching for
- **Performance Metrics**: Execution time, result counts, and success rates
- **User Context**: User ID, session ID, IP address, and user agent
- **Search Context**: Register, schema, filters, and parameters
- **Request Metadata**: HTTP method, URI, and timestamps

### Key Features

#### Automatic Search Logging

Every search operation is automatically logged with:

- **Search Context**: Register, schema, and search terms
- **Performance Metrics**: Execution time, result count, and success status
- **User Information**: User ID, session ID, and IP address
- **Request Details**: User agent, request method, and timestamp
- **Parameters**: Complete search parameters excluding sensitive information

#### Analytics Dashboard

The search trails interface provides:

- **Comprehensive Statistics**: Total searches, results, success rates, and performance metrics
- **Popular Search Terms**: Most frequently used search terms with usage counts
- **Register/Schema Usage**: Search activity breakdown by register and schema
- **User Agent Statistics**: Browser and client distribution
- **Activity Patterns**: Search activity over time (hourly, daily, weekly, monthly)

#### Query Complexity Analysis

Search trails categorize queries by complexity:

- **Simple**: Basic term searches with minimal parameters (≤3 parameters)
- **Medium**: Multi-parameter searches with basic filters (4-10 parameters)
- **Complex**: Advanced searches with multiple filters and facets (>10 parameters)

### Search Trail Creation Flow

```mermaid
sequenceDiagram
    participant User
    participant ObjectsCtrl as Objects Controller
    participant ObjectService
    participant SolrService as Solr Service
    participant TrailService as SearchTrail Service
    participant TrailMapper as SearchTrail Mapper
    participant DB as Database
    
    User->>ObjectsCtrl: GET /api/objects?_search=term
    ObjectsCtrl->>ObjectService: Find Objects
    
    Note over ObjectsCtrl: Capture Request Start Time
    
    ObjectService->>SolrService: Search Query
    SolrService-->>ObjectService: Search Results
    ObjectService-->>ObjectsCtrl: Objects + Result Count
    
    Note over ObjectsCtrl: Calculate Response Time
    
    ObjectsCtrl->>TrailService: Create Search Trail
    
    TrailService->>TrailService: Extract Query Parameters
    Note over TrailService: Filter System Parameters (_*)
    Note over TrailService: Extract Search Term
    Note over TrailService: Extract Filters, Sort, Pagination
    
    TrailService->>TrailService: Get Request Context
    Note over TrailService: User ID, Session, IP, User Agent
    
    TrailService->>TrailMapper: Create Trail Entity
    
    TrailMapper->>TrailMapper: Set Expiry Date
    Note over TrailMapper: created + retention_days
    
    TrailMapper->>DB: INSERT search_trail
    DB-->>TrailMapper: Trail ID
    
    TrailMapper-->>TrailService: SearchTrail Entity
    TrailService-->>ObjectsCtrl: Trail Created
    
    ObjectsCtrl-->>User: Search Results
```

### API Endpoints

#### GET /api/search-trails

Retrieve paginated search trail entries.

**Parameters:**
- `limit` (integer): Number of results per page (default: 50)
- `offset` (integer): Number of results to skip
- `page` (integer): Page number
- `search` (string): Search term filter
- `register` (string): Filter by register ID
- `schema` (string): Filter by schema ID
- `user` (string): Filter by user
- `success` (boolean): Filter by success status
- `dateFrom` (datetime): Start date filter
- `dateTo` (datetime): End date filter
- `searchTerm` (string): Filter by search term
- `executionTimeFrom` (integer): Minimum execution time (ms)
- `executionTimeTo` (integer): Maximum execution time (ms)
- `resultCountFrom` (integer): Minimum result count
- `resultCountTo` (integer): Maximum result count

**Example Request:**
```bash
GET /api/search-trails?limit=20&register=users&success=true
```

**Example Response:**
```json
{
  'results': [
    {
      'id': 1,
      'searchTerm': 'user search',
      'register': 'users',
      'schema': 'person',
      'parameters': {
        'limit': 20,
        'filters': {
          'status': 'active'
        }
      },
      'resultCount': 15,
      'totalResults': 150,
      'responseTime': 150,
      'success': true,
      'user': 'admin',
      'userName': 'Administrator',
      'userAgent': 'Mozilla/5.0...',
      'ipAddress': '192.168.1.100',
      'session': 'sess_abc123',
      'created': '2024-01-15T10:30:00Z'
    }
  ],
  'total': 1,
  'page': 1,
  'pages': 1,
  'limit': 20,
  'offset': 0
}
```

#### GET /api/search-trails/statistics

Retrieve comprehensive search statistics.

**Example Response:**
```json
{
  'total': 1000,
  'totalResults': 15000,
  'averageResultsPerSearch': 15,
  'averageExecutionTime': 180,
  'successRate': 0.95,
  'uniqueSearchTerms': 250,
  'uniqueUsers': 50,
  'uniqueOrganizations': 10,
  'queryComplexity': {
    'simple': 600,
    'medium': 300,
    'complex': 100
  }
}
```

#### GET /api/search-trails/popular-terms

Retrieve popular search terms.

**Parameters:**
- `limit` (integer): Number of terms to return (default: 10)

**Example Response:**
```json
[
  {
    'term': 'user',
    'count': 150,
    'percentage': 15.0
  },
  {
    'term': 'active',
    'count': 120,
    'percentage': 12.0
  }
]
```

#### GET /api/search-trails/activity

Retrieve search activity data.

**Parameters:**
- `period` (string): Period type (hourly, daily, weekly, monthly)
- `limit` (integer): Number of periods to return

**Example Response:**
```json
[
  {
    'period': '2024-01-15',
    'searches': 50,
    'results': 750,
    'averageExecutionTime': 175,
    'successRate': 0.96
  }
]
```

#### POST /api/search-trails/cleanup

Clean up old search trail entries.

**Request Body:**
```json
{
  'days': 30
}
```

**Example Response:**
```json
{
  'success': true,
  'message': 'Cleanup completed successfully',
  'deletedCount': 100
}
```

### Database Schema

**Search Trail Table: `oc_openregister_search_trail`**

| Column | Type | Description |
|--------|------|-------------|
| `id` | INTEGER | Primary key |
| `uuid` | VARCHAR(36) | Unique identifier |
| `search_term` | TEXT | Search term from `_search` parameter |
| `query_parameters` | JSON | Full query parameters (excluding system params) |
| `result_count` | INTEGER | Number of results returned (current page) |
| `total_results` | INTEGER | Total matching results (before pagination) |
| `register` | INTEGER | Register ID (foreign key) |
| `schema` | INTEGER | Schema ID (foreign key) |
| `register_uuid` | VARCHAR(36) | Register UUID |
| `schema_uuid` | VARCHAR(36) | Schema UUID |
| `user` | VARCHAR(255) | User ID |
| `user_name` | VARCHAR(255) | User display name |
| `register_name` | VARCHAR(255) | Register name |
| `schema_name` | VARCHAR(255) | Schema name |
| `session` | VARCHAR(255) | Session ID |
| `ip_address` | VARCHAR(45) | IP address (IPv4/IPv6) |
| `user_agent` | TEXT | User agent string |
| `request_uri` | TEXT | Full request URI |
| `http_method` | VARCHAR(10) | HTTP method (GET, POST) |
| `response_time` | INTEGER | Response time in milliseconds |
| `page` | INTEGER | Page number |
| `limit` | INTEGER | Limit parameter |
| `offset` | INTEGER | Offset parameter |
| `facets_requested` | BOOLEAN | Whether facets were requested |
| `facetable_requested` | BOOLEAN | Whether facetable discovery was requested |
| `filters` | JSON | Applied filters |
| `sort_parameters` | JSON | Sort parameters |
| `published_only` | BOOLEAN | Whether only published objects were queried |
| `execution_type` | VARCHAR(20) | Execution type: `sync` or `async` |
| `created` | DATETIME | Creation timestamp |
| `organisation_id` | VARCHAR(255) | Organisation identifier |
| `organisation_id_type` | VARCHAR(50) | Type of organisation identifier |
| `expires` | DATETIME | Expiration date (created + retention_days) |
| `size` | INTEGER | Size of entry in bytes |

**Indexes:**
- PRIMARY KEY (`id`)
- INDEX (`uuid`)
- INDEX (`search_term`)
- INDEX (`register`, `schema`)
- INDEX (`user`)
- INDEX (`created`)
- INDEX (`expires`) -- For efficient cleanup
- INDEX (`organisation_id`)

### Configuration

#### Retention Policy

Configure search trail retention:

```php
// In app configuration
'search_trail_retention_days' => 90,
'search_trail_cleanup_enabled' => true,
'search_trail_cleanup_schedule' => 'daily',
```

#### Privacy Settings

Control what information is logged:

```php
// Privacy configuration
'search_trail_log_ip_addresses' => true,
'search_trail_log_user_agents' => true,
'search_trail_log_session_ids' => true,
'search_trail_anonymize_users' => false,
```

#### Performance Settings

Configure performance thresholds:

```php
// Performance monitoring
'search_trail_slow_query_threshold' => 1000, // milliseconds
'search_trail_complexity_thresholds' => [
    'simple' => 3,   // parameters
    'medium' => 10,  // parameters
    'complex' => 20, // parameters
],
```

### Use Cases

#### Performance Monitoring

Track slow queries and optimize search performance:

```php
// Get slow queries (> 1000ms)
$slowQueries = $searchTrailService->getSlowQueries(1000);

// Alert on performance issues
if (count($slowQueries) > 10) {
    $this->sendPerformanceAlert($slowQueries);
}
```

#### Search Analytics

Analyze search patterns to improve user experience:

```php
// Get popular search terms
$popularTerms = $searchTrailService->getPopularSearchTerms(10);

// Get search activity over time
$activity = $searchTrailService->getSearchActivityByTime('daily', 30);
```

#### User Behavior Analysis

Understand how users search:

```php
// Get user-specific search patterns
$userSearches = $searchTrailService->getUserSearchTrails($userId);

// Analyze search complexity distribution
$complexity = $searchTrailService->getQueryComplexityDistribution();
```

### Best Practices

#### Performance Optimization

1. **Regular Cleanup**: Implement automated cleanup of old search trails
2. **Index Management**: Ensure proper database indexes for search performance
3. **Batch Processing**: Use batch operations for large data sets
4. **Monitoring**: Monitor search trail table size and performance

#### Privacy and Security

1. **Data Retention**: Implement appropriate retention policies
2. **Access Control**: Restrict access to search trail data
3. **Anonymization**: Consider anonymizing personal information
4. **Audit Logging**: Log access to search trail data

#### Analytics and Reporting

1. **Regular Reviews**: Regularly review search patterns and performance
2. **Trend Analysis**: Identify trends in search behavior
3. **Performance Monitoring**: Monitor search performance over time
4. **User Behavior**: Analyze user search patterns for optimization

### Troubleshooting

#### High Storage Usage

**Problem**: Search trail table growing too large.

**Solutions**:
1. Implement regular cleanup and archiving
2. Reduce retention period
3. Archive old data instead of deleting
4. Monitor table size regularly

#### Slow Queries

**Problem**: Search trail queries are slow.

**Solutions**:
1. Add database indexes
2. Optimize query patterns
3. Use pagination for large result sets
4. Monitor database performance

#### Missing Data

**Problem**: Search trails not being created.

**Solutions**:
1. Check search trail logging configuration
2. Verify SearchTrailService is being called
3. Review error logs for failures
4. Check database connection

### Architecture Overview

```mermaid
graph TB
    subgraph "Search Flow"
        USER[User/API Request]
        CTRL[ObjectsController]
        SEARCH[Search Service]
        SOLR[Solr Service]
    end
    
    subgraph "Search Trail Logging"
        TRAIL[SearchTrailService]
        MAPPER[SearchTrailMapper]
        DB[(Database)]
    end
    
    subgraph "Analytics & Reporting"
        STATS[Statistics Service]
        POPULAR[Popular Terms]
        ACTIVITY[Activity Tracking]
        REGSTATS[Register/Schema Stats]
        UAGENT[User Agent Stats]
    end
    
    subgraph "Management"
        CLEANUP[Cleanup Service]
        EXPIRY[Expiry Check]
        RETENTION[Retention Policy]
    end
    
    USER --> CTRL
    CTRL --> SEARCH
    SEARCH --> SOLR
    
    SOLR --> TRAIL
    TRAIL --> MAPPER
    MAPPER --> DB
    
    DB --> STATS
    DB --> POPULAR
    DB --> ACTIVITY
    DB --> REGSTATS
    DB --> UAGENT
    
    CLEANUP --> EXPIRY
    EXPIRY --> DB
    RETENTION --> CLEANUP
    
    style TRAIL fill:#4A90E2
    style DB fill:#50E3C2
    style CLEANUP fill:#F5A623
```

### Search Trail Data Capture

```mermaid
graph TB
    subgraph "Request Data"
        REQ_URI[Request URI]
        REQ_METHOD[HTTP Method]
        REQ_PARAMS[Query Parameters]
    end
    
    subgraph "Search Context"
        SEARCH_TERM[Search Term]
        FILTERS[Filters]
        SORT[Sort Parameters]
        FACETS[Facet Requests]
    end
    
    subgraph "User Context"
        USER_ID[User ID]
        SESSION[Session ID]
        IP[IP Address]
        UAGENT[User Agent]
    end
    
    subgraph "Results Data"
        RESULT_COUNT[Result Count]
        RESPONSE_TIME[Response Time]
        EXEC_TYPE[Execution Type]
    end
    
    REQ_URI --> TRAIL[Search Trail Entity]
    SEARCH_TERM --> TRAIL
    FILTERS --> TRAIL
    USER_ID --> TRAIL
    RESULT_COUNT --> TRAIL
    
    style TRAIL fill:#4A90E2
```

### Statistics Aggregation

```mermaid
sequenceDiagram
    participant API
    participant TrailService as SearchTrail Service
    participant TrailMapper as SearchTrail Mapper
    participant DB as Database
    
    API->>TrailService: Get Statistics
    TrailService->>TrailMapper: Get Total Count
    TrailMapper->>DB: COUNT(*)
    DB-->>TrailMapper: Total Trails
    
    TrailService->>TrailMapper: Get Average Response Time
    TrailMapper->>DB: AVG(response_time)
    DB-->>TrailMapper: Avg Response Time
    
    TrailService->>TrailMapper: Get Success Rate
    TrailMapper->>DB: COUNT(result_count > 0) / COUNT(*)
    DB-->>TrailMapper: Success Rate
    
    TrailMapper-->>TrailService: Aggregated Statistics
    TrailService-->>API: Statistics Response
```

### Code Examples

#### Creating a Search Trail

```php
use OCA\OpenRegister\Service\SearchTrailService;

// In ObjectsController after search execution
$responseTime = round((microtime(true) - $startTime) * 1000, 2); // ms

$trail = $this->searchTrailService->createSearchTrail(
    query: $query,
    resultCount: count($results),
    totalResults: $totalResults,
    responseTime: $responseTime,
    executionType: 'sync'
);
```

#### Retrieving Statistics

```php
// Get comprehensive statistics
$stats = $this->searchTrailService->getSearchStatistics([
    'register' => 5,
    'dateFrom' => '2024-01-01',
    'dateTo' => '2024-12-31'
]);
```

### Performance Optimizations

1. **Batch Inserts**: Search trails are inserted asynchronously to avoid blocking search responses
2. **Indexed Queries**: Database indexes on common filter fields (`user`, `register`, `schema`, `created`)
3. **JSON Field Optimization**: `query_parameters`, `filters`, and `sort_parameters` stored as JSON
4. **Retention Policy**: Automatic expiry calculation: `expires = created + retention_days`
5. **Caching Statistics**: Frequently accessed statistics can be cached

### Monitoring & Debugging

#### Query Search Trails

```bash
# Get recent search trails
docker exec -it master-database-mysql-1 mysql -u nextcloud -pnextcloud nextcloud -e "
SELECT search_term, result_count, response_time, created 
FROM oc_openregister_search_trail 
ORDER BY created DESC 
LIMIT 10;
"

# Get slow queries
docker exec -it master-database-mysql-1 mysql -u nextcloud -pnextcloud nextcloud -e "
SELECT search_term, response_time, created 
FROM oc_openregister_search_trail 
WHERE response_time > 1000 
ORDER BY response_time DESC 
LIMIT 20;
"
```

### Security Considerations

1. **Access Control**: Search trail data should be restricted to administrators
2. **Data Privacy**: IP addresses and session IDs are personal data - implement GDPR-compliant retention policies
3. **SQL Injection**: All queries use parameterized statements
4. **XSS Prevention**: User agent and search terms are escaped in frontend display

## Error Handling

### Invalid Field Names
```json
{
  "error": "Invalid field name: @self[invalid_field]",
  "code": 400
}
```

### Invalid Operators
```json
{
  "error": "Invalid operator: @self[created][invalid_op]",
  "code": 400
}
```

### Invalid Date Formats
```json
{
  "error": "Invalid date format. Expected: YYYY-MM-DDTHH:MM:SS",
  "code": 400
}
```

---

## Technical Implementation

This section provides detailed technical information about how search is implemented in OpenRegister.

### Architecture Overview

OpenRegister uses Apache Solr as its search engine, providing powerful full-text search, faceting, and filtering capabilities:

```mermaid
graph TB
    A[API Request] --> B[ObjectService]
    B --> C{Check _source param}
    C -->|database| D[ObjectEntityMapper]
    C -->|index| E[GuzzleSolrService]
    
    D --> F[(MySQL Database)]
    F --> G[SQL Query]
    G --> H[ResultSet]
    
    E --> I{SOLR Available?}
    I -->|No| J[Return Empty Results]
    I -->|Yes| K[Build SOLR Query]
    K --> L[Apply Filters]
    L --> M[Add Facets]
    M --> N[Execute Search]
    N --> O[(Apache Solr)]
    O --> P[Process Results]
    P --> Q[Reconstruct Objects]
    
    H --> R[ObjectEntity Array]
    Q --> R
    R --> S[Apply Extensions]
    S --> T[Return Results]
    
    style E fill:#e1f5ff
    style O fill:#e1ffe1
    style F fill:#fff4e1
```

**Key Components:**
- **ObjectService**: High-level orchestration, determines search mode
- **GuzzleSolrService**: Main service for Solr integration
- **ObjectEntityMapper**: Database access for non-Solr searches
- **Query Builder**: Translates OpenRegister queries to Solr queries
- **Filter Processor**: Handles metadata and property filters
- **Facet Engine**: Generates facet aggregations

### Search Request Flow

```mermaid
sequenceDiagram
    participant Client
    participant API
    participant ObjectService
    participant SolrService
    participant Solr
    
    Client->>API: GET /api/objects?_source=index&_search=budget
    API->>ObjectService: findObjects(_search=budget)
    
    Note over ObjectService: Check _source parameter
    ObjectService->>ObjectService: _source=index? Use Solr
    
    ObjectService->>SolrService: searchObjects(query)
    
    Note over SolrService: 1. Validate SOLR available
    SolrService->>SolrService: isAvailable()
    SolrService->>SolrService: getActiveCollectionName()
    
    Note over SolrService: 2. Build SOLR query
    SolrService->>SolrService: buildSolrQuery(searchParams)
    SolrService->>SolrService: buildWeightedSearchQuery('budget')
    SolrService->>SolrService: applyFilters()
    SolrService->>SolrService: applyFacets()
    
    Note over SolrService: 3. Execute search
    SolrService->>Solr: GET /collection/select?q=...
    Solr-->>SolrService: SOLR Response
    
    Note over SolrService: 4. Process results
    SolrService->>SolrService: processSearchResults()
    SolrService->>SolrService: reconstructObjects()
    
    SolrService-->>ObjectService: Results array
    ObjectService-->>API: JSON response
    API-->>Client: Search results
```

**Search Flow Steps:**

1. **Request Validation**: Check for '_source=index' parameter to enable Solr search
2. **SOLR Availability**: Verify Solr service is available and collection exists
3. **Query Building**: Convert OpenRegister query to Solr query syntax
4. **Filter Application**: Apply metadata filters, RBAC, multi-tenancy
5. **Facet Generation**: Add facet aggregations if requested
6. **Search Execution**: Send query to Solr and retrieve results
7. **Result Processing**: Convert Solr documents to OpenRegister format
8. **Object Reconstruction**: Hydrate full object data from 'self_object' field

### Query Building Process

```mermaid
graph TD
    A[OpenRegister Query] --> B[buildSolrQuery]
    B --> C[Parse _search parameter]
    C --> D[buildWeightedSearchQuery]
    D --> E[Add field boosting]
    
    B --> F[Parse filters]
    F --> G[@self metadata filters]
    F --> H[Property filters]
    G --> I[buildFilterQuery]
    H --> I
    I --> J[Apply operators]
    
    B --> K[Parse sorting]
    K --> L[translateSortField]
    L --> M[Map to Solr fields]
    
    B --> N[Parse pagination]
    N --> O[Calculate start/rows]
    
    B --> P[Parse facets]
    P --> Q[Get facetable fields]
    Q --> R[Add facet.field params]
    
    E --> S[Complete SOLR Query]
    J --> S
    M --> S
    O --> S
    R --> S
    
    style B fill:#e1f5ff
    style S fill:#e1ffe1
```

**Query Translation Example:**

```php
// OpenRegister Query
{
  "_search": "annual report",
  "_limit": 20,
  "_page": 1,
  "_order": {"@self.created": "desc"},
  "@self": {
    "register": "5",
    "created": {"gte": "2025-01-01T00:00:00"}
  },
  "status": "published",
  "_facetable": true
}

// Translated SOLR Query
{
  "q": "(self_name:(annual report)^10 OR self_summary:(annual report)^5 OR self_description:(annual report)^2)",
  "fq": [
    "self_register:5",
    "self_created:[2025-01-01T00:00:00Z TO *]",
    "status_s:published"
  ],
  "start": 0,
  "rows": 20,
  "sort": "self_created desc",
  "facet": "true",
  "facet.field": ["status_s", "self_register_i", "category_s"]
}
```

### Weighted Search Implementation

OpenRegister implements intelligent field weighting to improve search relevance:

```php
private function buildWeightedSearchQuery(string $searchTerm): string
{
    $cleanTerm = $this->escapeSolrValue($searchTerm);
    
    // Wildcard support for partial matching
    $wildcardTerm = '*' . $cleanTerm . '*';
    
    // Build query with field-specific boosting
    $queryParts = [
        "self_name:($wildcardTerm)^10",      // Highest priority
        "self_summary:($wildcardTerm)^5",    // Medium-high priority
        "self_description:($wildcardTerm)^2" // Medium priority
    ];
    
    return '(' . implode(' OR ', $queryParts) . ')';
}
```

**Field Weight Rationale:**
- **Name (10x)**: Most important identifier, should rank highest
- **Summary (5x)**: Key information, deserves strong ranking
- **Description (2x)**: Detailed content, moderate ranking boost
- **Other fields (1x)**: Standard relevance scoring

### Filter Operators

OpenRegister supports comprehensive filter operators that are translated to Solr query syntax:

| OpenRegister | Solr Syntax | Example |
|--------------|-------------|---------|
| '=' (equals) | ':value' | 'status_s:active' |
| 'ne' (not equals) | '-field:value' | '-status_s:inactive' |
| 'gt' (greater than) | ':\{value TO \*\}' | 'age_i:\{30 TO \*\}' |
| 'gte' (greater or equal) | ':[value TO \*]' | 'age_i:[30 TO \*]' |
| 'lt' (less than) | ':\{\* TO value\}' | 'age_i:\{\* TO 30\}' |
| 'lte' (less or equal) | ':[\* TO value]' | 'age_i:[\* TO 30]' |
| '~' (contains) | ':\*value\*' | 'name_s:\*john\*' |
| '^' (starts with) | ':value*' | 'name_s:annual*' |
| '$' (ends with) | ':*value' | 'name_s:*2025' |
| 'exists' | 'field:[* TO *]' | 'published:[* TO *]' |
| 'null' | '-field:[* TO *]' | '-published:[* TO *]' |

### Performance Optimizations

#### 1. Schema Analysis Caching

```php
// Pre-compute facet configuration when schema is saved
$schema->setFacets($this->analyzeFacetableFields($schema));
```

**Benefits:**
- Eliminates runtime schema analysis
- Reduces search request latency
- Improves scalability

#### 2. Query Result Caching

Search results are cached to improve performance for repeated queries:

**Configuration:**
- TTL: 300 seconds (5 minutes)
- Storage: PSR-6 compliant cache
- Invalidation: On object updates

#### 3. Bulk Indexing

```php
// Index multiple objects in single request
$this->bulkIndex($documents, $commit = false);
```

**Optimization:**
- Batch size: 1000 documents
- Deferred commits
- Optimized for bulk imports

#### 4. Selective Field Loading

```php
// Load only required fields from SOLR
$solrQuery['fl'] = 'id,self_uuid,self_name,self_created';
```

**Benefits:**
- Reduced network transfer
- Faster deserialization
- Lower memory usage

### Monitoring and Debugging

#### Query Logging

```php
$this->logger->debug('Executing SOLR search', [
    'original_query' => $query,
    'solr_query' => $solrQuery,
    'collection' => $collectionName,
    'execution_time_ms' => $executionTime
]);
```

#### Performance Metrics

```php
$metrics = [
    'searches' => $this->stats['searches'],
    'indexes' => $this->stats['indexes'],
    'search_time' => $this->stats['search_time'],
    'index_time' => $this->stats['index_time'],
    'errors' => $this->stats['errors']
];
```

#### Health Checks

```bash
# Check SOLR availability
GET /api/solr/health

# Response
{
  "available": true,
  "collection": "openregister_nextcloud_core",
  "document_count": 1234,
  "index_size_mb": 45.6
}
```

### Error Handling Flow

```mermaid
graph TD
    A[Search Request] --> B{SOLR Available?}
    B -->|No| C[Return Empty Results]
    B -->|Yes| D{Collection Exists?}
    D -->|No| E[Create Collection]
    D -->|Yes| F[Execute Query]
    E --> F
    F --> G{Query Success?}
    G -->|No| H[Log Error]
    G -->|Yes| I[Process Results]
    H --> C
    I --> J[Return Results]
    
    style C fill:#ffe1e1
    style J fill:#e1ffe1
```

**Error Scenarios:**

1. **SOLR Unavailable**: Return empty results with warning
2. **Collection Missing**: Auto-create collection, retry query
3. **Query Syntax Error**: Log error, return validation message
4. **Timeout**: Reduce query complexity, retry with simplified query
5. **Result Too Large**: Apply pagination, warn about result size

### Code Examples

#### Building a Search Query

```php
use OCA\OpenRegister\Service\GuzzleSolrService;

// Execute search with filters
$results = $solrService->searchObjects([
    '_search' => 'annual report',
    '_limit' => 20,
    '_page' => 1,
    '_order' => ['@self.created' => 'desc'],
    '@self' => [
        'register' => '5',
        'created' => ['gte' => '2025-01-01T00:00:00']
    ],
    'status' => 'published',
    '_facetable' => true
]);

// Access results
$objects = $results['data'];
$total = $results['total'];
$facets = $results['facets'];
```

#### Custom Filter Processing

```php
// Build custom filter query
$filters = [];

// Add metadata filters
if (isset($query['@self'])) {
    foreach ($query['@self'] as $field => $value) {
        $filters[] = $this->buildMetadataFilter($field, $value);
    }
}

// Add property filters
foreach ($query as $key => $value) {
    if ($key[0] !== '_' && $key !== '@self') {
        $filters[] = $this->buildPropertyFilter($key, $value);
    }
}

$solrQuery['fq'] = $filters;
```

### Testing

```bash
# Run all search tests
vendor/bin/phpunit tests/Service/GuzzleSolrServiceTest.php

# Test specific scenarios
vendor/bin/phpunit --filter testWeightedSearch
vendor/bin/phpunit --filter testFacetedSearch
vendor/bin/phpunit --filter testFilterOperators

# Integration tests
vendor/bin/phpunit tests/Integration/SearchIntegrationTest.php
```

**Test Coverage:**
- Query building and translation
- Filter operator processing
- Facet generation
- Result processing
- Error handling
- Performance benchmarks
- Case-insensitive search
- Ordering and sorting
