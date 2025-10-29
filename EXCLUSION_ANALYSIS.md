# Rsync Exclusion Analysis

## Files/Folders in Root Directory

### Should be INCLUDED in release:
- ✅ `appinfo/` - Nextcloud app metadata
- ✅ `lib/` - PHP backend code
- ✅ `js/` - Built frontend (from src/)
- ✅ `css/` - Stylesheets  
- ✅ `img/` - Images
- ✅ `templates/` - PHP templates
- ✅ `vendor/` - Composer dependencies
- ✅ `LICENSE` - License file
- ✅ `README.md` - Main readme
- ✅ `CHANGELOG.md` - Changelog
- ✅ `CONTRIBUTING.md` - Contributing guide
- ❓ `openapi.json` - API specification (useful for developers)

### Should be EXCLUDED (development only):

**IDE & Editor configs:**
- ❌ `.cursor/` - Cursor IDE
- ❌ `.vscode/` - VS Code  
- ❌ `.nextcloud/` - Nextcloud dev files

**Version Control:**
- ❌ `.git/` - Git repository
- ❌ `.github/` - GitHub workflows
- ❌ `.gitattributes` - Git attributes
- ❌ `.gitignore` - Git ignore rules

**Docker:**
- ❌ `docker/` - Docker files
- ❌ `docker-compose.yml` - Docker compose

**Documentation Sources:**
- ❌ `docs/` - Documentation (might conflict with vendor)
- ❌ `website/` - Docusaurus site

**Build & Dependencies:**
- ❌ `node_modules/` - npm dependencies (rebuilt)
- ❌ `package.json` - npm config
- ❌ `package-lock.json` - npm lock

**Source Files (built to js/):**
- ❌ `src/` - Vue source files

**Testing:**
- ❌ `tests/` - Test files
- ❌ `test/` - Alternative test location
- ❌ `phpunit.xml` - PHPUnit config
- ❌ `.phpunit.result.cache` - PHPUnit cache
- ❌ `.phpunit.cache/` - PHPUnit cache directory
- ❌ `jest.config.js` - Jest config
- ❌ `coverage.txt` - Coverage report

**Code Quality:**
- ❌ `phpcs-custom-sniffs/` - Custom PHP CodeSniffer
- ❌ `phpcs.xml` - PHP CodeSniffer config
- ❌ `phpmd.xml` - PHP Mess Detector
- ❌ `psalm.xml` - Psalm static analysis
- ❌ `.php-cs-fixer.dist.php` - PHP CS Fixer

**Linting & Formatting:**
- ❌ `.eslintrc.js` - ESLint
- ❌ `.prettierrc` - Prettier
- ❌ `stylelint.config.js` - Stylelint
- ❌ `.spectral.yml` - OpenAPI linting

**Build Tools:**
- ❌ `webpack.config.js` - Webpack
- ❌ `tsconfig.json` - TypeScript
- ❌ `.babelrc` - Babel

**Composer:**
- ❌ `composer.json` - Composer config (vendor needs theirs!)
- ❌ `composer.lock` - Composer lock
- ❌ `composer-setup.php` - Composer installer

**Other Configs:**
- ❌ `.nvmrc` - Node version
- ❌ `changelog-ci-config.json` - Changelog CI

**Build Artifacts (created during build):**
- ❌ `package/` - Build directory
- ❌ `signing-key.key` - Signing key (security!)
- ❌ `signing-cert.crt` - Signing cert (security!)

**Development Resources:**
- ❌ `resources/` - Development resources (might conflict with vendor)
- ❌ `path/` - Unknown (should check what this is)

**Documentation Files (our analysis):**
- ❌ `BETA_RELEASE_FIX.md` - Our analysis docs
- ❌ `CHANGES_SUMMARY.md` - Our analysis docs
- ❌ `CRITICAL_FIX_RSYNC_EXCLUSIONS.md` - Our analysis docs
- ❌ `RELEASE_WORKFLOWS_FIX.md` - Our analysis docs
- ❌ `RELEASE_WORKFLOWS_SUMMARY.md` - Our analysis docs
- ❌ `WORKFLOW_ARTIFACTS_GUIDE.md` - Our analysis docs
- ❌ `EXCLUSION_ANALYSIS.md` - This file

## Exclusion Strategy

### Root-level only (use leading `/`):
Files/folders that ONLY exist at root and should not affect vendor:
- Package management: `/package.json`, `/composer.json`, `/package-lock.json`, `/composer.lock`
- Source code: `/src`, `/tests`
- Build output: `/package`
- Config files: Most config files listed above
- Security: `/signing-key.key`, `/signing-cert.crt`

### Any level (no leading `/`):
Patterns that should be excluded everywhere:
- `.phpunit.result.cache` - Can appear in any directory
- `test/` - Generic test folders (but we use `/tests` specifically)
- `.git/` - Should never exist in vendor anyway
- Cache files and temporary files

### Problematic exclusions:
- `docs` - Vendor packages often have docs/ folders - USE `/docs`
- `resources` - Vendor packages may have resources/ - USE `/resources`  
- `node_modules` - Usually root only - USE `/node_modules`

## Recommended Exclusions

All root-level development files should use `/` prefix!

