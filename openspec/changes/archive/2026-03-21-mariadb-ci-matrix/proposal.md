# MariaDB Support & Dual-Database CI Matrix

## Problem
OpenRegister SHALL be fully tested on both PostgreSQL and MariaDB through a cost-efficient 2-line CI matrix that piggybacks the database difference onto the PHP version split, ensuring that database-specific code paths (JSONB vs JSON, GIN indexes vs B-tree, pg_trgm vs LIKE, PostgreSQL containment operators vs JSON_CONTAINS) are exercised in CI rather than only discovered in production. Blob storage (Normal mode) is removed — only MagicMapper (dedicated SQL tables per schema) is supported.

## Proposed Solution
Implement MariaDB Support & Dual-Database CI Matrix following the detailed specification. Key requirements include:
- Requirement: 2-Line CI Matrix Covering Both Databases and Nextcloud Versions
- Requirement: PHPUnit Tests Use the Same Database Matrix
- Requirement: Matrix Strategy Configuration in quality.yml
- Requirement: Dynamic Service Containers
- Requirement: Parameterized Nextcloud Installation

## Scope
This change covers all requirements defined in the mariadb-ci-matrix specification.

## Success Criteria
- PostgreSQL job (PHP 8.3, Nextcloud stable32)
- MariaDB job (PHP 8.2, Nextcloud stable31)
- Both jobs MUST pass for merge
- PHPUnit on PostgreSQL (PHP 8.3, stable32)
- PHPUnit on MariaDB (PHP 8.2, stable31)
