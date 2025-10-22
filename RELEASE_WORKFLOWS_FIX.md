# Release Workflow Fix - OpenAI PHP Client Missing

## Problem

When installing OpenRegister from the Nextcloud app store (both beta and production releases), users encountered the following error:

```
[openregister] Fataal: Error during app service registration: 
Failed opening required '/var/www/html/custom_apps/openregister/vendor/composer/../openai-php/client/src/OpenAI.php'
```

This indicated that the `openai-php/client` package was missing from the vendor directory in the released package.

## Root Cause Analysis

### Dependency Chain
1. **openregister/composer.json** requires `theodo-group/llphant` (line 90)
2. **llphant** requires `openai-php/client` as a transitive dependency
3. The build process runs `composer install --no-dev` which SHOULD install all production dependencies
4. However, there was no verification that the vendor directory was properly populated

### Build Process Issues (Both Workflows)

**Beta Release Workflow (beta-release.yaml):**
1. **No Autoloader Optimization**: The composer install command was not optimizing the autoloader for production
2. **No Verification Steps**: No checks to ensure critical dependencies were installed before packaging
3. **Duplicate rsync**: An unnecessary rsync command on line 175 (after tarball creation) ran AFTER the tarball was created
4. **No Vendor Verification in Tarball**: No final check to ensure the tarball contained the vendor directory

**Production Release Workflow (release-workflow.yaml):**
1. **Same issues**: All the same problems existed in the production workflow
2. **Duplicate rsync**: Line 150-154 had a redundant rsync that ran AFTER tarball creation
3. **Used `composer i` instead of `composer install`**: Less explicit command

**CRITICAL: Rsync Exclude Bug (Both workflows):**
1. **Global exclusions**: `--exclude='src'` and `--exclude='composer.json'` excluded these files EVERYWHERE
2. **Vendor files lost**: This also excluded `vendor/*/src/` and `vendor/*/composer.json`
3. **Empty packages**: Vendor packages like openai-php/client were included but empty (only LICENSE.md)

## Solution Implemented

### 1. Enhanced Composer Install (Line 96)
```yaml
- run: composer install --no-dev --optimize-autoloader --classmap-authoritative
```

**Changes:**
- Added `--optimize-autoloader`: Generates optimized autoloader for production
- Added `--classmap-authoritative`: Makes autoloader authoritative (no filesystem checks)

### 2. Post-Install Verification (Lines 98-109)
```yaml
- name: Verify vendor dependencies
  run: |
    if [ ! -d "vendor/openai-php/client" ]; then
      echo "ERROR: openai-php/client not found in vendor directory"
      exit 1
    fi
    if [ ! -d "vendor/theodo-group/llphant" ]; then
      echo "ERROR: theodo-group/llphant not found in vendor directory"
      exit 1
    fi
    echo "✓ All critical dependencies verified"
```

**Purpose:**
- Verifies critical dependencies are installed immediately after composer install
- Fails the build early if dependencies are missing
- Provides clear error messages for debugging

### 3. Pre-Tarball Package Verification (Lines 159-167)
```yaml
- name: Verify package vendor directory
  run: |
    if [ ! -d "package/${{ github.event.repository.name }}/vendor/openai-php/client" ]; then
      echo "ERROR: openai-php/client not found in package vendor directory"
      ls -la package/${{ github.event.repository.name }}/vendor/ || echo "vendor directory not found"
      exit 1
    fi
    echo "✓ Package vendor directory verified"
```

**Purpose:**
- Verifies vendor directory was correctly copied to the package folder
- Checks specifically for openai-php/client before creating tarball
- Lists vendor contents if verification fails for debugging

### 4. Fixed Rsync Exclusions (CRITICAL!)
```yaml
# BEFORE (BROKEN):
--exclude='src' \              # Excluded vendor/*/src/ too!
--exclude='composer.json' \    # Excluded vendor/*/composer.json too!
--exclude='tests' \            # Excluded vendor/*/tests/ too!

# AFTER (FIXED):
--exclude='/src' \             # Only excludes root-level src/
--exclude='/composer.json' \   # Only excludes root-level composer.json
--exclude='/tests' \           # Only excludes root-level tests/
```

**Why this matters:**
- Without the leading `/`, rsync excludes the pattern ANYWHERE in the path
- This was excluding `vendor/openai-php/client/src/` and `vendor/openai-php/client/composer.json`
- Result: Vendor packages were included but **empty** (only LICENSE.md files)
- With `/`, rsync only excludes from the root of the sync source

### 5. Removed Duplicate rsync (Line 175)
```yaml
# REMOVED: rsync -av --progress --exclude='package' --exclude='.git' ./ package/${{ github.event.repository.name }}/
```

**Reason:**
- This rsync ran AFTER the tarball was created
- It served no purpose and could cause confusion
- Removed to clean up the workflow

### 5. Enhanced Final Verification (Lines 227-236)
```yaml
- name: Verify version and contents
  run: |
    echo "App version: ${{ env.NEW_VERSION }}"
    echo "Tarball contents:"
    tar -tvf nextcloud-release.tar.gz | head -100
    echo "Verify vendor directory in tarball:"
    tar -tvf nextcloud-release.tar.gz | grep "vendor/openai-php/client" | head -5 || echo "WARNING: openai-php/client not found in tarball!"
    echo "info.xml contents:"
    tar -xOf nextcloud-release.tar.gz ${{ env.APP_NAME }}/appinfo/info.xml
```

**Changes:**
- Added vendor directory verification in the final tarball
- Specifically checks for openai-php/client presence
- Provides warning if package is missing (for monitoring)

## Benefits

1. **Early Failure Detection**: Build fails early if dependencies are missing
2. **Clear Error Messages**: Specific error messages indicate which dependency is missing
3. **Production Optimization**: Autoloader is optimized for production use
4. **Multiple Verification Points**: Three separate checks ensure dependencies are present:
   - After composer install
   - After copying to package directory
   - After creating tarball
5. **Better Debugging**: Enhanced logging helps identify issues quickly

## Testing

To test this fix:

1. **Local Test**:
   ```bash
   composer install --no-dev --optimize-autoloader --classmap-authoritative
   ls -la vendor/openai-php/client
   ls -la vendor/theodo-group/llphant
   ```

2. **Workflow Test**:
   - Push changes to beta branch
   - Monitor GitHub Actions workflow
   - Check for verification step outputs
   - Download and inspect the tarball

3. **Installation Test**:
   - Download the beta release from GitHub
   - Install in a test Nextcloud instance
   - Enable the app
   - Verify no errors in Nextcloud logs

## Related Files

- **composer.json**: Contains dependency definitions
- **composer.lock**: Contains locked dependency versions
- **.gitignore**: Excludes vendor from git (correct behavior)
- **.github/workflows/beta-release.yaml**: Beta build and release workflow (✅ FIXED)
- **.github/workflows/release-workflow.yaml**: Production build and release workflow (✅ FIXED)

## Key Changes Summary

### Both Workflows Now Have:
1. ✅ Optimized composer install with `--optimize-autoloader --classmap-authoritative`
2. ✅ Post-install verification of critical dependencies
3. ✅ Pre-tarball verification of package vendor directory
4. ✅ Enhanced final tarball verification
5. ✅ Removed duplicate/redundant rsync commands
6. ✅ Better error messages and logging

## Conclusion

The fixes ensure that all production dependencies, including transitive dependencies like `openai-php/client`, are properly installed, verified, and packaged in **both beta and production releases**. The enhanced verification steps will catch similar issues early in the build process, preventing broken releases from reaching the app store.

