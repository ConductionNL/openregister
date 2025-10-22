# Release Workflows Summary

## Overview

OpenRegister has two release workflows that build and publish to the Nextcloud App Store:

1. **beta-release.yaml** - Triggered on pushes to `beta` branch
2. **release-workflow.yaml** - Triggered on pushes to `main`/`master` branch

Both workflows have been updated to fix the missing `openai-php/client` dependency issue.

## Workflow Comparison

| Feature | Beta Release | Production Release |
|---------|--------------|-------------------|
| **Trigger Branch** | `beta` | `main` / `master` |
| **Version Naming** | `X.Y.Z-beta.N` | `X.Y.Z` |
| **GitHub Release Type** | Pre-release | Release |
| **App Store** | Published as beta | Published as stable |
| **Changelog** | No automatic changelog | Automatic changelog via changelog-ci |

## Common Build Steps (Now Identical)

Both workflows now follow the same optimized build process:

### 1. Version Management
- Extract current version from `appinfo/info.xml`
- Increment version appropriately
- Commit version update back to git

### 2. Environment Setup
- Install Node.js 18.x
- Install PHP 8.2 with extensions (zip, gd)
- Prepare signing certificates

### 3. Build Process
```bash
npm ci                                                    # Install npm dependencies
npm run build                                             # Build frontend assets
composer install --no-dev --optimize-autoloader --classmap-authoritative
```

### 4. Verification Steps (NEW!)
```bash
# Verify dependencies installed
✓ Check vendor/openai-php/client exists
✓ Check vendor/theodo-group/llphant exists

# Verify package directory
✓ Check package vendor directory contains dependencies

# Verify final tarball
✓ Check tarball contains vendor dependencies
```

### 5. Package Creation
- Copy files to package directory (excluding dev files)
- Create tarball: `openregister-X.Y.Z.tar.gz`
- Sign tarball with OpenSSL

### 6. Publishing
- Create GitHub release (beta = pre-release, prod = release)
- Attach tarball to GitHub release
- Upload to Nextcloud App Store

## Files Excluded from Package

Both workflows exclude the same development files:

```
package/               # Build artifact directory
.git/                  # Git repository
.github/               # GitHub workflows
.cursor/               # Cursor IDE
.vscode/               # VS Code settings
.nextcloud/            # Nextcloud dev files
docker/                # Docker files
docs/                  # Documentation source
website/               # Docusaurus website
node_modules/          # npm dependencies (rebuilt)
src/                   # Vue source files (built to js/)
phpcs-custom-sniffs/   # PHP CodeSniffer custom rules
resources/             # Development resources
tests/                 # Test files
package-lock.json      # npm lock file
composer.lock          # Composer lock file
composer-setup.php     # Composer installer
composer.json          # Composer config
package.json           # npm config
phpcs.xml              # Code sniffer config
phpmd.xml              # Mess detector config
psalm.xml              # Static analysis config
phpunit.xml            # Testing config
webpack.config.js      # Webpack config
tsconfig.json          # TypeScript config
jest.config.js         # Jest config
stylelint.config.js    # Stylelint config
.eslintrc.js           # ESLint config
.prettierrc            # Prettier config
.babelrc               # Babel config
.nvmrc                 # Node version
.gitignore             # Git ignore
.gitattributes         # Git attributes
.php-cs-fixer.dist.php # PHP CS Fixer config
changelog-ci-config.json # Changelog config
signing-key.key        # Signing key (excluded for security)
signing-cert.crt       # Signing cert (excluded for security)
coverage.txt           # Coverage report
.phpunit.result.cache  # PHPUnit cache
```

## Files Included in Package

```
appinfo/               # Nextcloud app metadata
lib/                   # PHP backend code
js/                    # Built frontend code (from src/)
css/                   # Stylesheets
img/                   # Images
templates/             # PHP templates
vendor/                # Composer dependencies (INCLUDING openai-php/client!)
CHANGELOG.md           # Changelog
CONTRIBUTING.md        # Contributing guide
README.md              # Main readme
LICENSE                # License file
```

## Testing the Releases

### Beta Release Testing
```bash
# Download beta release
wget https://github.com/ConductionNL/openregister/releases/download/v0.2.7-beta.99/openregister-0.2.7-beta.99.tar.gz

# Extract and verify
tar -xzf openregister-0.2.7-beta.99.tar.gz
ls -la openregister/vendor/openai-php/client/
```

### Production Release Testing
```bash
# Download production release
wget https://github.com/ConductionNL/openregister/releases/download/v0.2.6/openregister-0.2.6.tar.gz

# Extract and verify
tar -xzf openregister-0.2.6.tar.gz
ls -la openregister/vendor/openai-php/client/
```

## Troubleshooting

### If Dependencies Are Missing

1. **Check GitHub Actions logs** for verification step outputs
2. **Look for error messages** in the "Verify vendor dependencies" step
3. **Check final verification** to see if vendor was in tarball
4. **Download the tarball** and inspect contents manually

### If Build Fails

The build will now fail early with clear messages:

```
ERROR: openai-php/client not found in vendor directory
```

or

```
ERROR: openai-php/client not found in package vendor directory
```

This is intentional! The build should fail if dependencies are missing, preventing broken releases.

## Version Numbering

### Beta Releases
- Format: `X.Y.Z-beta.N`
- Based on next patch version from main
- Beta counter increments for multiple betas of same patch

Example sequence:
- main: `0.2.6`
- beta: `0.2.7-beta.1`
- beta: `0.2.7-beta.2`
- main: `0.2.7` (after merge)
- beta: `0.2.8-beta.1` (new cycle)

### Production Releases
- Format: `X.Y.Z`
- Increments patch version automatically
- Tagged as `vX.Y.Z` in git

## Monitoring Releases

### GitHub Actions
- Monitor workflow runs: https://github.com/ConductionNL/openregister/actions
- Check for verification step success
- Review build logs for warnings

### GitHub Releases
- Beta releases: https://github.com/ConductionNL/openregister/releases (marked as "Pre-release")
- Production releases: https://github.com/ConductionNL/openregister/releases (marked as "Latest")

### Nextcloud App Store
- Beta releases visible to users who opt-in to beta testing
- Production releases visible to all users
- Monitor for user-reported issues

## Next Steps

1. **Test the fixes**: Push to beta branch and verify the new verification steps work
2. **Monitor the build**: Check GitHub Actions for all verification checkpoints
3. **Test the tarball**: Download and inspect the beta release tarball
4. **Install in test environment**: Deploy to a test Nextcloud instance
5. **Verify functionality**: Ensure OpenRegister loads without errors
6. **Merge to main**: Once beta is confirmed working, merge to main for production release

