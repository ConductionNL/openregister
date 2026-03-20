# Faceting Configuration

## Problem
Provides a comprehensive, backend-agnostic faceting system for OpenRegister that enables per-property facet definition on schema properties, supports multiple facet types (terms, date histogram, range), and delivers configurable facet metadata (title, description, order, aggregation control) through the REST and GraphQL APIs. The system is designed to solve the fundamental conflict between pagination and facet computation by calculating facets on the full filtered dataset independently of pagination, while maintaining backward compatibility with the legacy boolean `facetable` flag and offering intelligent caching at multiple layers (in-memory, APCu/distributed, and database-persistent) to ensure sub-200ms facet response times even on large datasets.

## Proposed Solution
Implement Faceting Configuration following the detailed specification. Key requirements include:
- Requirement: Facetable config object support with backward compatibility
- Requirement: Facet type auto-detection from property definitions
- Requirement: Non-aggregated facet isolation
- Requirement: Schema ID in non-aggregated facet response
- Requirement: Custom facet title, description, and order in response

## Scope
This change covers all requirements defined in the faceting-configuration specification.

## Success Criteria
- Property with boolean facetable (backward compatibility)
- Property with facetable config object including type override
- Property with facetable config object without type (auto-detection)
- Property with partial config object uses sensible defaults
- Property with facetable false is excluded
