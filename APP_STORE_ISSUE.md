# ⚠️ Nextcloud App Store Installation Issue

## Problem

When installing OpenRegister from the **Nextcloud App Store**, users get an incomplete installation with missing vendor files, even for versions labeled v151+.

### Error Symptoms:
```
Failed opening required '/var/www/html/custom_apps/openregister/vendor/symfony/polyfill-php81/bootstrap.php'
Failed opening required '/var/www/html/custom_apps/openregister/vendor/openai-php/client/src/OpenAI.php'
```

## Root Cause

The **Nextcloud App Store** appears to serve **cached or outdated versions** even when labeled with current version numbers. The actual GitHub releases (v0.2.7-beta.150+) contain the complete fix with all vendor files, but the App Store version does not.

## Solution: Install from GitHub

### ✅ Recommended Installation Method:

```bash
# 1. Download directly from GitHub
cd /var/www/html/custom_apps
wget https://github.com/ConductionNL/openregister/releases/download/v0.2.7-beta.151/openregister-0.2.7-beta.151.tar.gz

# 2. Remove old installation (if exists)
rm -rf openregister

# 3. Extract the tarball
tar -xzf openregister-0.2.7-beta.151.tar.gz

# 4. Set correct permissions
chown -R www-data:www-data openregister

# 5. Clean up
rm openregister-0.2.7-beta.151.tar.gz

# 6. Enable the app
sudo -u www-data php occ app:enable openregister
```

### Verification:

After installation, verify the critical files exist:

```bash
# Should show 738 bytes:
ls -lh openregister/vendor/symfony/polyfill-php81/bootstrap.php

# Should show 707 bytes:
ls -lh openregister/vendor/openai-php/client/src/OpenAI.php
```

If both files exist with these sizes, the installation is correct!

## What Was Fixed (v150+)

1. **Fixed rsync exclusion patterns** - Changed 44+ patterns from global to root-level
   - `--exclude='src'` → `--exclude='/src'`
   - Prevents vendor/*/src/ from being excluded

2. **Updated GitHub Actions** - actions/upload-artifact v3 → v4

3. **Enhanced verification** - Now checks for src/ subdirectories

4. **Added artifacts** - Tarball available in Actions tab for debugging

## Status

- ✅ **GitHub Releases**: Fixed in v0.2.7-beta.150+ (complete vendor packages)
- ❌ **Nextcloud App Store**: May serve outdated versions (incomplete vendor)
- 🔄 **Workaround**: Install directly from GitHub until App Store is refreshed

## For Nextcloud App Store Maintainers

The App Store needs to:
1. Clear cached releases for OpenRegister
2. Re-fetch versions v0.2.7-beta.150+ from GitHub
3. Ensure the tarball served matches the GitHub release tarball

## References

- GitHub Releases: https://github.com/ConductionNL/openregister/releases
- Fixed version: v0.2.7-beta.150 and later
- Workflow fixes: `.github/workflows/beta-release.yaml` and `release-workflow.yaml`

## Date

Issue discovered: October 23, 2025
Fix implemented: October 22, 2025 (v0.2.7-beta.150)
App Store issue identified: October 23, 2025

