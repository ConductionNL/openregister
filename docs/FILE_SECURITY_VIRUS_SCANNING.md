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

❌ **Not Yet Implemented:**
- Virus scanning
- Malware detection
- Content inspection beyond MIME type

## Virus Scanning Options

### Option 1: Nextcloud Built-in Antivirus App ⭐ **RECOMMENDED**

**Nextcloud Antivirus for files** - Official Nextcloud app

**Description:**
Nextcloud heeft een officiële **Antivirus for files** app die ClamAV gebruikt om bestanden te scannen bij upload.

**Voordelen:**
- ✅ Native Nextcloud integratie
- ✅ Geen extra PHP code nodig
- ✅ Werkt automatisch voor alle file uploads
- ✅ Ondersteund door Nextcloud community
- ✅ Scant bestanden asynchroon (background jobs)
- ✅ Configureerbaar via admin panel

**Implementatie:**
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

**Hoe het werkt:**
1. Gebruiker upload bestand via OpenRegister
2. Bestand wordt opgeslagen in Nextcloud
3. Nextcloud Antivirus app detecteert nieuw bestand
4. ClamAV scant het bestand
5. Als virus: bestand wordt geblokkeerd/verwijderd
6. Admin krijgt notificatie

**Docker compose aanpassing:**
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

**Links:**
- https://apps.nextcloud.com/apps/files_antivirus
- https://docs.nextcloud.com/server/latest/admin_manual/configuration_server/antivirus_configuration.html

---

### Option 2: PHP ClamAV Library

**xenolope/quahog** - Pure PHP ClamAV client

**Voordelen:**
- ✅ Direct control in PHP code
- ✅ Synchronous scanning
- ✅ Custom error handling

**Nadelen:**
- ❌ Moet zelf in SaveObject geïntegreerd worden
- ❌ Extra dependency
- ❌ Blocking operation (vertraagt uploads)

**Implementatie:**
```bash
composer require xenolope/quahog
```

```php
// In SaveObject.php
use Xenolope\Quahog\Client as ClamAVClient;

private function scanFileForViruses(string $fileContent, string $filename): void
{
    try {
        $clamav = new ClamAVClient('unix:///var/run/clamav/clamd.ctl');
        $clamav->ping(); // Check connection
        
        $result = $clamav->scanStream($fileContent);
        
        if ($result['status'] === 'FOUND') {
            $this->logger->error('Virus detected in upload', [
                'filename' => $filename,
                'virus' => $result['reason']
            ]);
            
            throw new Exception(
                "File contains malicious content: {$result['reason']}"
            );
        }
    } catch (\Exception $e) {
        $this->logger->warning('ClamAV scan failed', [
            'error' => $e->getMessage(),
            'filename' => $filename
        ]);
        // Decision: Fail-open or fail-closed?
        // throw $e; // Fail-closed: reject upload if scan fails
    }
}

// In processStringFileInput() before $this->fileService->addFile():
$this->scanFileForViruses($fileData['content'], $filename);
```

**Links:**
- https://github.com/xenolope/quahog

---

### Option 3: VirusTotal API

**Cloud-based multi-engine scanning**

**Voordelen:**
- ✅ Meerdere antivirus engines (70+)
- ✅ Geen lokale ClamAV installatie
- ✅ Altijd up-to-date signatures

**Nadelen:**
- ❌ External API calls (privacy concern!)
- ❌ Rate limits (gratis: 4 requests/min)
- ❌ Files worden naar VirusTotal gestuurd
- ❌ Niet geschikt voor vertrouwelijke documenten
- ❌ Langzaam (API calls over internet)

**Implementatie:**
```bash
composer require javanile/php-virustotal
```

```php
use Javanile\VirustotalApi\VirustotalApi;

private function scanWithVirusTotal(string $fileContent): void
{
    $vt = new VirustotalApi($_ENV['VIRUSTOTAL_API_KEY']);
    
    $result = $vt->scanFile([
        'file' => $fileContent
    ]);
    
    if ($result['response_code'] === 1) {
        // File was scanned
        if ($result['positives'] > 0) {
            throw new Exception(
                "File flagged by {$result['positives']}/{$result['total']} engines"
            );
        }
    }
}
```

**⚠️ Privacy waarschuwing:**
Bestanden worden naar externe service gestuurd. Niet gebruiken voor:
- Persoonlijke documenten
- Bedrijfsgegevens
- Privacy-gevoelige content

**Links:**
- https://www.virustotal.com/gui/home/upload
- https://developers.virustotal.com/reference/overview

---

### Option 4: Custom File Type Validation

**Enhanced MIME type + magic byte checking**

**Voordelen:**
- ✅ Geen externe dependencies
- ✅ Snel
- ✅ Detecteert file type spoofing

**Nadelen:**
- ❌ Geen echte virus detectie
- ❌ Alleen basis validatie

**Implementatie:**
```php
private function validateFileContent(string $content, string $expectedMime): void
{
    $finfo = new \finfo(FILEINFO_MIME_TYPE);
    $actualMime = $finfo->buffer($content);
    
    // Check magic bytes voor bekende file types
    $magicBytes = [
        'application/pdf' => '%PDF',
        'image/jpeg' => "\xFF\xD8\xFF",
        'image/png' => "\x89PNG",
        'application/zip' => "PK\x03\x04",
    ];
    
    if (isset($magicBytes[$expectedMime])) {
        if (strpos($content, $magicBytes[$expectedMime]) !== 0) {
            throw new Exception(
                "File content doesn't match declared type"
            );
        }
    }
    
    // Additional checks voor executables
    $dangerousPatterns = [
        'MZ' => 'Windows executable',
        "\x7FELF" => 'Linux executable',
        '#!/bin/' => 'Shell script',
        '<script' => 'Embedded script',
    ];
    
    foreach ($dangerousPatterns as $pattern => $type) {
        if (strpos($content, $pattern) !== false) {
            throw new Exception("Dangerous content detected: $type");
        }
    }
}
```

---

## Recommended Implementation Strategy

### Phase 1: Nextcloud Antivirus (Short Term) ⭐

**Beste keuze voor productie:**

1. **Setup ClamAV in Docker:**
   - Add ClamAV container to docker-compose
   - Configure networking
   - Update virus signatures daily

2. **Enable Nextcloud Antivirus app:**
   - Install via app store
   - Configure daemon mode
   - Set action on virus detection

3. **Zero code changes needed in OpenRegister!**
   - Works automatically for all files
   - Background scanning
   - Admin notifications

**Waarom dit het beste is:**
- ✅ Productie-ready
- ✅ Onderhouden door Nextcloud
- ✅ Geen OpenRegister code aanpassingen
- ✅ Werkt voor alle apps
- ✅ Background scanning = geen performance impact

### Phase 2: Enhanced Validation (Medium Term)

Add to SaveObject for pre-upload validation:

```php
// In SaveObject.php, before FileService
private function validateFileBeforeStorage(array $fileData): void
{
    // 1. Magic byte validation
    $this->validateFileContent($fileData['content'], $fileData['mimeType']);
    
    // 2. Size validation (already exists)
    // 3. MIME validation (already exists)
    
    // 4. Additional checks
    $this->checkForDangerousContent($fileData['content']);
}

private function checkForDangerousContent(string $content): void
{
    // Check for embedded scripts
    $patterns = [
        '/<script/i',
        '/<iframe/i',
        '/javascript:/i',
        '/data:text\/html/i',
    ];
    
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $content)) {
            throw new Exception('Potentially dangerous content detected');
        }
    }
}
```

### Phase 3: Optional VirusTotal (Future)

Only for public/non-sensitive files:
- Add checkbox in schema: "Enable VirusTotal scanning"
- Only for public documents
- Clear privacy warning to users

---

## Performance Considerations

### ClamAV (Nextcloud App)
- **Scanning time:** ~100ms per MB
- **Background:** Asynchronous via cron
- **Impact:** Zero impact on upload speed
- **Memory:** ~200MB for ClamAV daemon

### PHP Library (Synchronous)
- **Scanning time:** ~100ms per MB
- **Impact:** Blocks upload during scan
- **For 10MB file:** +1 second upload time
- **Not recommended** for user uploads

### VirusTotal
- **API call:** 2-10 seconds
- **Rate limits:** 4 req/min (free tier)
- **Impact:** Significant delay
- **Not recommended** for production

---

## Configuration Example

**docker-compose.yml:**
```yaml
version: '3.8'

services:
  nextcloud:
    # ... existing config ...
    depends_on:
      - clamav
    
  clamav:
    image: clamav/clamav:latest
    container_name: master-clamav-1
    hostname: clamav
    networks:
      - nextcloud-network
    volumes:
      - clamav-data:/var/lib/clamav
    environment:
      - CLAMAV_NO_FRESHCL AM=false
    ports:
      - "3310:3310"
    healthcheck:
      test: ["CMD", "clamdscan", "--ping", "1"]
      interval: 60s
      timeout: 10s
      retries: 3
    restart: unless-stopped

volumes:
  clamav-data:
    driver: local

networks:
  nextcloud-network:
    driver: bridge
```

**Nextcloud config.php additions:**
```php
'files_antivirus' => [
    'av_mode' => 'daemon',
    'av_socket' => 'clamav:3310',
    'av_stream_max_length' => 26214400, // 25MB
    'av_max_file_size' => -1, // Unlimited
    'av_infected_action' => 'delete', // or 'only_log'
],
```

---

## Testing Virus Detection

**EICAR test file** - Safe virus test file:

```bash
# Create EICAR test file
echo 'X5O!P%@AP[4\PZX54(P^)7CC)7}$EICAR-STANDARD-ANTIVIRUS-TEST-FILE!$H+H*' > eicar.txt

# Try to upload (should be blocked)
curl -X POST 'http://nextcloud/api/registers/test/schemas/doc/objects' \
  -F 'title=Virus Test' \
  -F 'attachment=@eicar.txt'
```

**Expected result:**
- File upload succeeds initially
- Background scan detects EICAR
- File is deleted/quarantined
- Admin gets notification

---

## Conclusion & Recommendation

### ✅ **Use Nextcloud Antivirus App with ClamAV**

**Reasons:**
1. **Zero OpenRegister code changes**
2. **Production-ready solution**
3. **Background scanning (no performance impact)**
4. **Maintained by Nextcloud**
5. **Works for all file uploads system-wide**

**Implementation:**
1. Add ClamAV to docker-compose.yml
2. Install Nextcloud Antivirus app
3. Configure daemon mode
4. Done! 🎉

**Don't:**
- ❌ Use synchronous PHP scanning (blocks uploads)
- ❌ Use VirusTotal for private data
- ❌ Reinvent the wheel

**Do:**
- ✅ Use Nextcloud's built-in solution
- ✅ Keep ClamAV signatures updated
- ✅ Monitor logs for detections
- ✅ Add enhanced validation as extra layer

---

## Resources

- **Nextcloud Antivirus:** https://apps.nextcloud.com/apps/files_antivirus
- **ClamAV Documentation:** https://docs.clamav.net/
- **ClamAV Docker:** https://hub.docker.com/r/clamav/clamav
- **EICAR Test File:** https://www.eicar.org/download-anti-malware-testfile/

