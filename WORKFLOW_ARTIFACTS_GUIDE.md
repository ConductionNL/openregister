# How to Access Workflow Artifacts

## Overview

Starting now, every release build uploads the tarball to **TWO locations** for your convenience:

```
┌─────────────────────────────────────────────────────────┐
│                    Build Process                        │
├─────────────────────────────────────────────────────────┤
│  1. composer install + verify                           │
│  2. Build frontend                                      │
│  3. Create package                                      │
│  4. Create tarball                                      │
│  5. Sign tarball                                        │
│  6. ✨ Upload as Workflow Artifact (NEW!)              │
│  7. Create GitHub Release                               │
│  8. Attach to Release                                   │
│  9. Upload to Nextcloud App Store                       │
└─────────────────────────────────────────────────────────┘
```

## Access Methods

### Method 1: Quick Access via Workflow Artifacts ⚡

**Best for**: Quick inspection, debugging, verification

**Steps:**
1. Go to **Actions** tab: https://github.com/ConductionNL/openregister/actions
2. Click on the latest workflow run (e.g., "Beta Release")
3. Scroll down to the **"Artifacts"** section
4. Click on `nextcloud-release-X.Y.Z-beta.N` to download

**What you get:**
- `nextcloud-release.tar.gz` (the app package)
- `nextcloud-release.signature` (OpenSSL signature)

**Retention:** 30 days

**Screenshot locations:**
```
GitHub.com
  └─ ConductionNL/openregister
     └─ Actions tab
        └─ Select workflow run
           └─ Scroll to bottom
              └─ "Artifacts" section  ← HERE!
```

### Method 2: Permanent Access via Releases 📦

**Best for**: Distribution, production installation, archival

**Steps:**
1. Go to **Releases** page: https://github.com/ConductionNL/openregister/releases
2. Find the release (e.g., "Beta Release 0.2.7-beta.149")
3. Expand the **"Assets"** section
4. Download `openregister-X.Y.Z-beta.N.tar.gz`

**What you get:**
- Same tarball as the artifact
- Permanent storage (never expires)

**Retention:** Forever

## Comparison

| Feature | Workflow Artifacts | Release Assets |
|---------|-------------------|----------------|
| **Access** | Actions tab → Run → Artifacts | Releases page → Assets |
| **Speed** | Immediate after build | After release creation |
| **Retention** | 30 days | Permanent |
| **Purpose** | Quick inspection/debug | Distribution |
| **Includes** | Tarball + Signature | Tarball (in Assets) |
| **Best for** | Developers | End users |

## Example Usage

### For Developers (Quick Check)

```bash
# 1. Go to Actions tab and download artifact
# 2. Extract and verify:

unzip nextcloud-release-0.2.7-beta.149.zip
tar -xzf nextcloud-release.tar.gz
ls -la openregister/vendor/openai-php/client/

# ✓ Quick verification without navigating to Releases!
```

### For Production (Permanent Download)

```bash
# Download from Releases page
wget https://github.com/ConductionNL/openregister/releases/download/v0.2.7-beta.149/openregister-0.2.7-beta.149.tar.gz

# Extract and install
tar -xzf openregister-0.2.7-beta.149.tar.gz
# ... proceed with installation
```

## Visual Guide

### Workflow Artifacts Location

```
┌──────────────────────────────────────────────────────┐
│  GitHub Actions Run                                  │
├──────────────────────────────────────────────────────┤
│                                                      │
│  ✓ All steps completed successfully                 │
│                                                      │
│  ┌────────────────────────────────────────────┐     │
│  │ Artifacts                             ↓    │     │
│  ├────────────────────────────────────────────┤     │
│  │ nextcloud-release-0.2.7-beta.149           │     │
│  │ Uploaded 2 minutes ago   •   10.8 MB      │     │
│  └────────────────────────────────────────────┘     │
│                                                      │
└──────────────────────────────────────────────────────┘
         ↑
    Click here to download!
```

### Release Assets Location

```
┌──────────────────────────────────────────────────────┐
│  Beta Release 0.2.7-beta.149        [Pre-release]   │
├──────────────────────────────────────────────────────┤
│  Bump version to 0.2.6                               │
│  [skip ci]                                           │
│                                                      │
│  ▼ Assets (3)                                        │
│  ┌────────────────────────────────────────────┐     │
│  │ 📦 openregister-0.2.7-beta.149.tar.gz      │     │
│  │    10.8 MB                                 │     │
│  │                                            │     │
│  │ 📄 Source code (zip)                       │     │
│  │                                            │     │
│  │ 📄 Source code (tar.gz)                    │     │
│  └────────────────────────────────────────────┘     │
└──────────────────────────────────────────────────────┘
         ↑
    Download the tarball here!
```

## Benefits

✅ **Fast Debugging**: Download directly from Actions run without navigating to Releases
✅ **Verification**: Quickly inspect the build output before it reaches production
✅ **Backup**: Both locations have the same file, redundancy
✅ **Convenient**: Choose the method that fits your workflow

## Notes

- Artifacts are automatically deleted after 30 days to save storage
- Release assets are permanent and used by the Nextcloud App Store
- Both contain the exact same tarball
- The signature file is included in artifacts for verification
- Artifacts are downloaded as a ZIP containing the tarball and signature

## Troubleshooting

### "No artifacts found"
- Check if the workflow completed successfully
- Look at the "Upload tarball as artifact" step in the logs
- Artifacts appear only after this step completes

### "Artifact expired"
- Artifacts expire after 30 days
- Use Release Assets for older versions
- Re-run the workflow if you need a fresh artifact

### "Different file size"
- Artifact ZIP contains multiple files (tarball + signature)
- Unzip first, then extract the tarball
- Release asset is the raw tarball (no ZIP wrapper)

