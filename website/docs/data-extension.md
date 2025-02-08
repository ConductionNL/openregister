# Data Extension (_extend)

Data Extension allows you to automatically include related entities in API responses, reducing the need for multiple API calls and providing complete context in a single request. This is useful when you need to retrieve related data for a specific object or collection an lowers the number of API calls needed therby reducing the load on the server and improving performence client side.

The extend patern is based was orginally developed for the [Open Catalogi](https://opencatalogi.org) project and is now available in the ObjectStore API. Its baed on the extend functionality of [Zaak gericht werken]  but brought in line with p[NLGov REST API Design Rules](https://logius-standaarden.github.io/API-Design-Rules/) by adding a _ prefix to the parameter


## Overview

The extension system provides:
- Automatic inclusion of related objects
- Nested object resolution
- Circular reference protection
- Depth limiting
- Performance optimization

## Extension Patterns

### Basic Extension
Extend a single property:
- `?_extend=author` - Include full author object
- `?_extend=category` - Include full category object
- `?_extend=files` - Include file metadata

### Nested Extension
Extend nested properties:
- `?_extend=author.organization` - Include author with their organization
- `?_extend=department.employees` - Include department with all employees
- `?_extend=project.tasks.assignee` - Include project with tasks and their assignees

### Multiple Extensions
Combine multiple extensions:
- `?_extend=author,category,comments` - Include multiple related objects
- `?_extend=files,metadata,relations` - Include all related data
- `?_extend=all` - Include all possible relations on the root object

## Extension Logic

1. **Depth Control**
   - Maximum depth of 3 levels
   - Prevents infinite recursion
   - Optimizes response size
   - Manages performance

2. **Circular Detection**
   - Identifies circular references
   - Prevents infinite loops
   - Maintains data integrity
   - Optimizes response

3. **Type Handling**
   - Single object relations
   - Array of objects
   - File references
   - External references

## Performance Considerations

1. **Query Optimization**
   - Efficient database queries
   - Batch loading
   - Cache utilization
   - Resource management

2. **Response Size**
   - Selective extension
   - Depth limiting
   - Data filtering
   - Compression

3. **Caching**
   - Response caching
   - Relation caching
   - Cache invalidation
   - Cache optimization

## Best Practices

1. **Extension Selection**
   - Request only needed relations
   - Consider response size
   - Use appropriate depth
   - Plan for performance

2. **Query Design**
   - Use specific extensions
   - Combine related requests
   - Optimize query patterns
   - Consider caching

3. **Error Handling**
   - Handle missing relations
   - Validate extension paths
   - Manage timeouts
   - Provide fallbacks

## Related Features

- [Object Relations](object-relations.md) - Base for extensions
- [Data Selection](data-selection.md) - Combine with property selection
- [Content Search](content-search.md) - Search within extended data

## Extension Types

### Direct Relations
- Single object relations
- Collection relations
- External references
- URI resolutions

### Nested Relations
- Multi-level extensions
- Depth control
- Circular detection
- Performance optimization

### Reference Types
- Internal IDs
- External URLs
- URNs
- Custom identifiers

## Key Benefits

1. **Performance**
   - Reduce API calls
   - Optimize data retrieval
   - Efficient response handling
   - Bandwidth optimization

2. **Data Completeness**
   - Get full context
   - Include related data
   - Resolve references
   - Complete object graphs

3. **Flexibility**
   - Client-driven inclusion
   - Dynamic data loading
   - Customizable depth
   - Selective extension

## Integration with Privacy

- Respects access controls on related objects
- Honors data minimalization principles
- Supports GDPR compliance
- Provides audit trail integration

## Using the objects api

### Single Object Extension

Consider a Dog object with a reference to its Breed:

Request:
````
GET /api/dogs/123
````

Response:
````json
{
    "id": "550e8400-e29b-41d4-a716-446655440000",
    "name": "Max",
    "breed": "https://api.petstore.com/breeds/german-shepherd",
    "age": 5
}
````

With data extension enabled:

Request:
````
GET /api/dogs/123?extend=breed
````

Response:
````json
{
    "id": "550e8400-e29b-41d4-a716-446655440000",
    "name": "Max",
    "breed": {
        "id": "german-shepherd",
        "name": "German Shepherd",
        "origin": "Germany",
        "size": "Large"
    },
    "age": 5
}
````

### Collection Extension

The same principle works when retrieving collections:

Request:
````
GET /api/dogs?extend=breed
````

Response:
````json
{
    "results": [
        {
            "id": "550e8400-e29b-41d4-a716-446655440000",
            "name": "Max",
            "breed": {
                "id": "german-shepherd",
                "name": "German Shepherd",
                "origin": "Germany",
                "size": "Large"
            },
            "age": 5
        },
        {
            "id": "550e8400-e29b-41d4-a716-446655440001",
            "name": "Bella",
            "breed": {
                "id": "golden-retriever",
                "name": "Golden Retriever",
                "origin": "Scotland",
                "size": "Large"
            },
            "age": 3
        }
    ],
    "total": 2
}
````

### Extending Multiple Properties

You can extend multiple properties in two ways:

1. Using a comma-separated list:

Request:
````GET /api/dogs?extend=breed,owner````

2. Using an array format:

Request: 
````GET /api/dogs?extend[]=breed&extend[]=owner````

Both approaches will produce the same result, extending multiple properties in a single request.

### Extending Nested Properties

You can extend properties of already extended objects using dot notation. This allows you to access nested data structures.

For example, to extend the breed's parent breed information:

Request:
````GET /api/dogs?extend=breed.parent_breed````

Response:
````json
{
    "results": [
        {
            "id": "550e8400-e29b-41d4-a716-446655440000",
            "name": "Max",
            "breed": {
                "id": "german-shepherd",
                "name": "German Shepherd",
                "origin": "Germany",
                "size": "Large",
                "parent_breed": {
                    "id": "herding-dog",
                    "name": "Herding Dog",
                    "category": "Working Dog"
                }
            },
            "age": 5
        }
    ]
}
````

You can combine nested property extension with multiple property extension:

Using comma separation:
````GET /api/dogs?extend=breed.parent_breed,owner.address````

Using array notation:
````GET /api/dogs?extend[]=breed.parent_breed&extend[]=owner.address````

Or combining both approaches:
````GET /api/dogs?extend[]=breed.parent_breed,owner.contact&extend=owner.address````

This gives you powerful flexibility to fetch exactly the nested data you need in a single request.


### Using the object service

The ObjectService provides a PHP interface to use data extension programmatically:

// Extend a single property
````php
$dogs = $objectService->find('dogs', ['extend' => 'breed']);
````

// Extend multiple properties using array
````php
$dogs = $objectService->find('dogs', [
    'extend' => ['breed', 'owner']
]);
````

// Extend nested properties
````php
$dogs = $objectService->find('dogs', [
    'extend' => ['breed.parent_breed', 'owner.address']
]);
````

// Combine with other query parameters
````php
$dogs = $objectService->find('dogs', [
    'extend' => ['breed', 'owner'],
    'filter' => ['age' => 5],
    'limit' => 10
]);


## Related Features

- [Object Relations](object-relations.md) - Base functionality for relations
- [Data Filtering](data-filtering.md) - Combine with filtering for precise data selection
- [Access Control](access-control.md) - Security for extended data 