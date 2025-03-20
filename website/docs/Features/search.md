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
Automatic Facets provide dynamic filtering options based on object properties and metadata.

## Content Search

Content Search provides powerful search capabilities across objects and their associated files.

## Elasticsearch Integration

Elasticsearch Integration provides advanced search and analytics capabilities for objects and their content.

## Overview

The search system enables:
- Full-text search
- Metadata search
- File content search
- Advanced query options

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

The faceting system:
- Generates filters automatically
- Updates in real-time
- Supports multiple filter types
- Provides count information

## Key Benefits

1. **User Experience**
   - Intuitive filtering
   - Dynamic updates
   - Clear navigation

2. **Data Discovery**
   - Quick filtering
   - Pattern recognition
   - Data exploration

3. **Integration**
   - Automatic generation
   - Custom configuration
   - Search integration

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

| Operator | Description | Example |
|----------|-------------|---------|
| `=` | Equals (case insensitive) | `name=nemo` |
| `!=` | Not equals (case insensitive) | `name[!=]=nemo` |
| `>` | Greater than | `age[>]=5` |
| `<` | Less than | `weight[<]=10` |
| `>=` | Greater than or equal | `age[>=]=2` |
| `<=` | Less than or equal | `age[<=]=10` |
| `~` | Contains (case insensitive) | `name[~]=ne` |
| `^` | Starts with (case insensitive) | `name[^]=ne` |
| `$` | Ends with (case insensitive) | `name[$]=mo` |
| `===` | Equals (case sensitive) | `name[===]=Nemo` |
| `exists` | Property exists check | `microchip[exists]=true` |
| `empty` | Empty value check | `notes[empty]=true` |
| `null` | Null value check | `owner[null]=true` |

## Full Text Search

The '_search' parameter allows searching across all text properties of objects in a case-insensitive way:

```
GET /api/pets?_search=nemo
```

This searches for "nemo" in all text fields like name, description, notes etc.

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

### Not Equals `!=`
```
GET /api/pets?name[!=]=nemo
```
Returns all pets NOT named "nemo" (case insensitive)

### Greater Than `>`
```
GET /api/pets?age[>]=5
```
Returns pets older than 5 years

### Less Than `<`
```
GET /api/pets?weight[<]=10
```
Returns pets weighing less than 10kg

### Greater Than or Equal `>=`
```
GET /api/pets?age[>=]=2
```
Returns pets 2 years or older

### Less Than or Equal `<=`
```
GET /api/pets?age[<=]=10
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
GET /api/pets?age[>=]=2&age[<=]=5
```
Returns pets between 2 and 5 years old (inclusive)

```
GET /api/pets?age[>]=2&age[<]=5
``` 
Returns pets between 2 and 5 years old (exclusive)

## Searching Nested Properties

```
GET /api/pets?owner.city=Amsterdam
```
Returns pets whose owners live in Amsterdam (case insensitive)

```
GET /api/pets?vaccinations.date[>]=2023-01-01
```
Returns pets with vaccinations after January 1st, 2023

## Best Practices

1. Use URL encoding for special characters
2. Keep queries focused and specific
3. Use pagination for large result sets
4. Consider URL length limitations
5. Break complex queries into multiple requests if needed

## Related Features

- [Automatic Facets](automatic-facets.md) - Combine with faceted search
- [Elasticsearch](elasticsearch.md) - Advanced search capabilities 
- [Content Search](content-search.md) - Full-text search integration
- [Access Control](access-control.md) - Security for search results