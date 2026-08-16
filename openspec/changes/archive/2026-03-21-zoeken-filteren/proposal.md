# Zoeken en Filteren

## Problem
Provide a comprehensive, backend-agnostic search and filtering system for register objects that supports full-text search with relevance ranking, field-level filtering with comparison operators, faceted drill-down navigation, multi-field sorting, cursor and offset pagination, and saved search trails. The system MUST transparently operate against PostgreSQL (with optional pg_trgm fuzzy matching), Apache Solr, or Elasticsearch as interchangeable backends, while exposing a single unified API surface through `ObjectService.searchObjectsPaginated()` and `SearchBackendInterface`.
**Tender demand**: 78% of analyzed government tenders require advanced search and filtering capabilities, including full-text search, faceted navigation, and multi-criteria filtering across structured data.

## Proposed Solution
Implement Zoeken en Filteren following the detailed specification. Key requirements include:
- Requirement: Full-text search across object properties
- Requirement: Field-level filtering with comparison operators
- Requirement: JSON array and object property filtering
- Requirement: Metadata filtering via @self namespace
- Requirement: Fuzzy search with pg_trgm integration

## Scope
This change covers all requirements defined in the zoeken-filteren specification.

## Success Criteria
- Full-text search across all string properties
- Search matches metadata fields
- Case-insensitive search
- Date-formatted string properties excluded from text search
- Search across multiple schemas (UNION query)
