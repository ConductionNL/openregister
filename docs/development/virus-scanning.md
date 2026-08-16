---
title: Virus Scanning Setup
sidebar_position: 46
---

# File Security & Virus Scanning in OpenRegister

**Version:** 1.0  
**Date:** October 2025  
**Status:** 📋 Planning Document

## Overview

This document outlines options for implementing virus scanning and malicious content detection for file uploads in OpenRegister.

## Current Security Measures

✅ **Already Implemented:**
- MIME type validation against schema configuration
- File size limits
- Content-type detection (not just extension-based)
- Filename sanitization
- RBAC permissions
- URL validation with timeouts
- Executable file blocking (extension + magic bytes)

❌ **Not Yet Implemented:**
- Virus scanning
- Malware detection
- Content inspection beyond MIME type

## Virus Scanning Options

### Option 1: Nextcloud Built-in Antivirus App ⭐ **RECOMMENDED**

**Nextcloud Antivirus for files** - Official Nextcloud app

**Description:**
Nextcloud has an official **Antivirus for files** app that uses ClamAV to scan files on upload.

**Advantages:**
- ✅ Native Nextcloud integration
- ✅ No extra PHP code needed
- ✅ Works automatically for all file uploads
- ✅ Supported by Nextcloud community
- ✅ Scans files asynchronously (background jobs)
- ✅ Configurable via admin panel

**Implementation:**
```bash
# 1. Install ClamAV in Docker environment
docker exec master-nextcloud-1 apt-get update
docker exec master-nextcloud-1 apt-get install -y clamav clamav-daemon

# 2. Start ClamAV daemon
docker exec master-nextcloud-1 service clamav-daemon start

# 3. Install Nextcloud Antivirus app
docker exec -u 33 master-nextcloud-1 php occ app:install files_antivirus

# 4. Enable the app
docker exec -u 33 master-nextcloud-1 php occ app:enable files_antivirus

# 5. Configure to use ClamAV daemon
docker exec -u 33 master-nextcloud-1 php occ config:app:set files_antivirus av_mode --value="daemon"
docker exec -u 33 master-nextcloud-1 php occ config:app:set files_antivirus av_socket --value="/var/run/clamav/clamd.ctl"
```

**How it works:**
1. User uploads file via OpenRegister
2. File is stored in Nextcloud
3. Nextcloud Antivirus app detects new file
4. ClamAV scans the file
5. If virus: file is blocked/removed
6. Admin gets notification

**Docker compose configuration:**
```yaml
services:
  nextcloud:
    # ... existing config ...
    
  clamav:
    image: clamav/clamav:latest
    container_name: master-clamav-1
    volumes:
      - clamav-data:/var/lib/clamav
    networks:
      - nextcloud-network
    healthcheck:
      test: ["CMD", "clamdscan", "--ping", "1"]
      interval: 60s
      timeout: 10s
      retries: 3

volumes:
  clamav-data:
```

**Configuration in Nextcloud:**
- Admin Settings → Security → Antivirus Configuration
- Choose: Daemon mode
- Socket: `/var/run/clamav/clamd.ctl` (Unix socket)
- Or: Host: `clamav`, Port: `3310` (TCP)
- Action on virus: Delete file / Only log

### Option 2: PHP ClamAV Library

**Library:** `xenolope/quahog` or `clamav/clamav-php`

**Advantages:**
- ✅ Direct integration in OpenRegister code
- ✅ More control over scanning behavior
- ✅ Can customize error handling

**Disadvantages:**
- ❌ Requires PHP extension or library
- ❌ More code to maintain
- ❌ Need to handle async scanning manually

### Option 3: VirusTotal API

**Service:** VirusTotal Public API

**Advantages:**
- ✅ No local installation needed
- ✅ Comprehensive threat database
- ✅ Multiple antivirus engines

**Disadvantages:**
- ❌ Rate limits (4 requests/minute free tier)
- ❌ Privacy concerns (files sent to third party)
- ❌ Requires API key
- ❌ Cost for high volume

## Recommended Approach

**Use Nextcloud Antivirus App** because:
1. ✅ Native integration - works automatically
2. ✅ No code changes needed in OpenRegister
3. ✅ Well-maintained by Nextcloud community
4. ✅ Background scanning - doesn't block uploads
5. ✅ Configurable via admin UI

## Implementation Steps

1. **Install ClamAV** in Docker environment
2. **Install Nextcloud Antivirus app** via `occ`
3. **Configure** ClamAV daemon connection
4. **Test** with EICAR test file
5. **Monitor** scan results in Nextcloud logs

## Testing

### EICAR Test File

Create a test file with EICAR signature (harmless test virus):

```bash
echo 'X5O!P%@AP[4\PZX54(P^)7CC)7}$EICAR-STANDARD-ANTIVIRUS-TEST-FILE!$H+H*' > eicar.txt
```

Upload via OpenRegister - should be detected and blocked by ClamAV.

## Related Documentation

- [Security Architecture](./security-architecture.md) - Executable file blocking
- [Files](../Features/files.md) - File upload documentation

