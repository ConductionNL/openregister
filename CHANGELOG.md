# Version: 0.1.2

* [#1](https://github.com/ConductionNL/openregister/pull/1): Create openregister.csr

# Changelog

## 0.1.57 – 2025-01-16
### Fixed
- SOLR setup now uses tenant-specific configSets and collections instead of base resources
- Enhanced SOLR setup error reporting with detailed progress tracking and troubleshooting steps
- Fixed dashboard stats availability inconsistency with connection test
- Centralized all SOLR operations through GuzzleSolrService for consistency
- Improved multi-tenant isolation in SOLR infrastructure

### Added
- Visual progress bar in SOLR setup dialog showing completed vs total steps
- Detailed error information display with primary error, error type, and operation context
- Configuration details shown during failed SOLR setup attempts
- Numbered troubleshooting steps section with actionable items
- Enhanced step timeline with individual step status and timestamps
- Color-coded progress indicators for better user experience

## 0.1.5 – 2024-09-07
### Added
- First version for the Nextcloud store

### Changed
- Changes in existing functionality for this release:

### Fixed
- Bug fixes for this release:

### Added
- Initial release

