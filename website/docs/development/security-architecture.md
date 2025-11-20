---
title: Security Architecture
sidebar_position: 45
---

# Security Architecture

## Executable File Blocking

**Version:** 1.0  
**Date:** October 2025  
**Status:** ✅ Implemented

## Security Architecture

### Defense-in-Depth Approach

Executable file blocking is implemented at **the lowest level** of the file system - in `FileService.php`. This ensures **all file uploads** are protected, regardless of which API endpoint or code path is used.

### Implementation Location

#### ✅ Correct: FileService.php (Generic Layer)

**File:** `lib/Service/FileService.php`

**Methods Protected:**
1. `addFile()` - Called when creating new files (line 2354)
2. `updateFile()` - Called when updating existing files (line 2039)

**Why Here:**
- ✅ **Central choke point** - ALL file operations go through FileService
- ✅ **Defense in depth** - Protection at the lowest level
- ✅ **Consistent security** - No matter which API endpoint is used
- ✅ **Hard to bypass** - Cannot circumvent by using different endpoints

#### ❌ Wrong: SaveObject.php (Specific Feature)

**Why NOT in SaveObject:**
- ❌ Only protects object-integrated uploads
- ❌ Separate file endpoints would be unprotected
- ❌ Synchronization flows could bypass
- ❌ Import operations could bypass

## Security Checks

### 1. Extension Check

**Location:** `FileService::blockExecutableFile()`

**Checks:**
```php
$dangerousExtensions = [
    // Windows
    'exe', 'bat', 'cmd', 'dll', 'msi', 'ps1', ...
    // Unix/Linux
    'sh', 'bash', 'run', 'bin', 'deb', 'rpm', ...
    // Scripts
    'php', 'py', 'pl', 'rb', 'jar', ...
];
```

**Detects:**
- Windows executables (`.exe`, `.bat`, `.cmd`, `.dll`)
- Linux executables (`.sh`, `.bin`, `.elf`)
- Scripts (`.php`, `.py`, `.pl`, `.rb`)
- Packages (`.deb`, `.rpm`, `.apk`, `.jar`)

### 2. Magic Bytes Detection

**Location:** `FileService::detectExecutableMagicBytes()`

**Checks first 1024 bytes for:**
```php
$magicBytes = [
    'MZ' => 'Windows PE/EXE',
    "\x7FELF" => 'Linux ELF executable',
    "#!/bin/sh" => 'Shell script',
    "#!/bin/bash" => 'Bash script',
    "<?php" => 'PHP script',
    "\xCA\xFE\xBA\xBE" => 'Java class file',
    ...
];
```

**Why Magic Bytes:**
- ✅ Detects renamed executables (e.g., `malware.pdf` that's actually an `.exe`)
- ✅ Defense-in-depth beyond extension checking
- ✅ Catches sophisticated attacks

## Security Coverage

### All Upload Methods Protected

| Method | Entry Point | Protected |
|--------|-------------|-----------|
| Object POST (multipart) | ObjectsController | ✅ Yes |
| Object POST (base64) | ObjectsController | ✅ Yes |
| Object POST (URL) | ObjectsController | ✅ Yes |
| Object PUT | ObjectsController | ✅ Yes |
| Separate file upload | FilesController | ✅ Yes |
| File update | FilesController | ✅ Yes |
| Sync operations | SyncService | ✅ Yes |
| Import | ImportService | ✅ Yes |

**Result:** ✅ **100% coverage** - Every file upload is protected!

## Example: Protection in Action

### Attack Scenario 1: Direct Extension

```bash
# Attacker tries to upload malware.exe
curl -X POST /api/objects/.../files \
  -F "file=@malware.exe"
```

**Result:** ❌ Blocked immediately - Extension `.exe` is in dangerous list

### Attack Scenario 2: Renamed Executable

```bash
# Attacker renames malware.exe to document.pdf
curl -X POST /api/objects/.../files \
  -F "file=@document.pdf"  # Actually malware.exe
```

**Result:** ❌ Blocked - Magic bytes detection finds `MZ` header (Windows PE)

### Attack Scenario 3: Base64 Encoded

```json
{
  "attachment": "data:application/pdf;base64,MZ..."
}
```

**Result:** ❌ Blocked - Magic bytes checked after base64 decoding

## Logging

All blocked attempts are logged with:
- Timestamp
- User ID
- File name
- Detection method (extension or magic bytes)
- File size
- MIME type

**Example Log:**
```
[WARNING] Blocked executable file upload: 
  user=admin, 
  filename=malware.exe, 
  method=extension, 
  size=1024000, 
  mime=application/x-msdownload
```

## Related Documentation

- [Files](../Features/files.md) - User-facing file documentation
- [Virus Scanning](./virus-scanning.md) - Virus scanning options

