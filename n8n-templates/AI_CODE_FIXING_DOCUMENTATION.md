# AI-Powered Code Fixing with Ollama & n8n

An automated workflow that uses CodeLlama AI to fix PHP code style issues detected by PHPCS.

## Overview

This system combines:
- **PHPCS** - Detects code style issues
- **Ollama + CodeLlama** - AI model that understands and fixes code
- **n8n** - Orchestrates the workflow
- **Container API** - Executes commands and manages files

##Workflow

```
┌──────────────┐
│  Trigger     │ POST /webhook/ai-code-fix
│  Webhook     │
└──────┬───────┘
       │
       ↓
┌──────────────┐
│  Get PHPCS   │ Analyze code, get issues per file
│  Issues      │
└──────┬───────┘
       │
       ↓
┌──────────────┐
│  Prepare     │ Extract files needing fixes
│  Files       │
└──────┬───────┘
       │
       ↓ (Loop through each file)
┌──────────────┐
│  Read File   │ Get current file content
│  Content     │
└──────┬───────┘
       │
       ↓
┌──────────────┐
│  Backup      │ Create backup before changes
│  File        │
└──────┬───────┘
       │
       ↓
┌──────────────┐
│  AI Fix      │ Send to Ollama CodeLlama
│  with LLM    │ Get fixed code
└──────┬───────┘
       │
       ↓
┌──────────────┐
│  Write Fixed │ Save the corrected code
│  Code        │
└──────┬───────┘
       │
       ↓
┌──────────────┐
│  Aggregate   │ Return summary of all fixes
│  Results     │
└──────────────┘
```

## Components

### 1. Container API (Port 9090)

**File:** `scripts/container-api-ai.py`

**Endpoints:**
- `POST /phpcs-detailed` - Get detailed PHPCS issues
- `POST /read-file` - Read a file from container
- `POST /write-file` - Write content to a file
- `POST /backup-file` - Create backup before fixing
- `POST /ai-fix-code` - Send code to Ollama for fixing

### 2. Ollama (Port 11434)

**Container:** `openregister-ollama`

**Model:** `codellama:7b-instruct`

**Purpose:** Understands code context and fixes PSR-12 violations

### 3. n8n Workflow

**Webhook:** `POST /webhook/ai-code-fix`

**ID:** aBWGzyu4xZf1CvFV

**Status:** ✅ Active

## How It Works

### Step-by-Step Process

1. **PHPCS Analysis**
   - Runs PHPCS with JSON output
   - Extracts issues per file with line numbers
   - Groups issues by file and line

2. **File Preparation**
   - Filters files with errors/warnings
   - Prepares data structure for processing
   - Returns list of files to fix

3. **For Each File:**
   
   a. **Read Content**
      - Fetches current file content from container
      - Preserves original formatting
   
   b. **Backup**
      - Creates timestamped backup
      - Format: `filename.php.backup_YYYYMMDD_HHMMSS`
   
   c. **AI Fixing**
      - Builds prompt with issues and code
      - Sends to CodeLlama via Ollama
      - LLM analyzes and fixes the code
      - Extracts fixed code from response
   
   d. **Write Fixed Code**
      - Replaces original file with fixed version
      - Preserves file permissions

4. **Aggregate Results**
   - Collects results from all files
   - Returns summary with statistics

## API Endpoints Detail

### Get PHPCS Issues

```bash
curl -X POST http://localhost:9090/phpcs-detailed
```

**Response:**
```json
{
  "command": "phpcs-detailed",
  "status": "completed_with_errors",
  "phpcs_issues": [
    {
      "file": "lib/Service/MyService.php",
      "errors": 5,
      "warnings": 2,
      "issues_by_line": {
        "15": [
          {
            "column": 10,
            "type": "ERROR",
            "message": "Expected 1 space after IF keyword",
            "source": "PSR12.ControlStructures.ControlStructureSpacing"
          }
        ]
      }
    }
  ],
  "totals": {
    "files_with_issues": 3,
    "total_errors": 15,
    "total_warnings": 5
  }
}
```

### Read File

```bash
curl -X POST http://localhost:9090/read-file \
  -H "Content-Type: application/json" \
  -d '{"file": "lib/Service/MyService.php"}'
```

**Response:**
```json
{
  "timestamp": "2025-12-29T21:00:00.000Z",
  "operation": "read-file",
  "file": "lib/Service/MyService.php",
  "content": "<?php\n\nnamespace...",
  "size": 5432,
  "lines": 150
}
```

### Backup File

```bash
curl -X POST http://localhost:9090/backup-file \
  -H "Content-Type: application/json" \
  -d '{"file": "lib/Service/MyService.php"}'
```

**Response:**
```json
{
  "timestamp": "2025-12-29T21:00:00.000Z",
  "operation": "backup-file",
  "original": "lib/Service/MyService.php",
  "backup": "lib/Service/MyService.php.backup_20251229_210000",
  "timestamp": "20251229_210000"
}
```

### AI Fix Code

```bash
curl -X POST http://localhost:9090/ai-fix-code \
  -H "Content-Type: application/json" \
  -d '{
    "file": "lib/Service/MyService.php",
    "issues": {"15": [{"message": "Expected 1 space after IF"}]},
    "content": "<?php\n\nif($x) {...}"
  }'
```

**Response:**
```json
{
  "file": "lib/Service/MyService.php",
  "original_size": 5432,
  "fixed_size": 5435,
  "fixed_code": "<?php\n\nif ($x) {...}",
  "status": "success"
}
```

### Write File

```bash
curl -X POST http://localhost:9090/write-file \
  -H "Content-Type: application/json" \
  -d '{
    "file": "lib/Service/MyService.php",
    "content": "<?php\n\nnamespace..."
  }'
```

**Response:**
```json
{
  "timestamp": "2025-12-29T21:00:00.000Z",
  "operation": "write-file",
  "file": "lib/Service/MyService.php",
  "bytes_written": 5435,
  "status": "success"
}
```

## Usage

### Trigger the Workflow

```bash
curl -X POST http://localhost:5678/webhook/ai-code-fix
```

### Expected Response

```json
{
  "timestamp": "2025-12-29T21:00:00.000Z",
  "workflow": "AI Code Fixer",
  "total_files_processed": 3,
  "files_fixed": 3,
  "files": [
    {
      "file": "lib/Service/MyService.php",
      "status": "success",
      "bytes_written": 5435
    },
    {
      "file": "lib/Controller/MyController.php",
      "status": "success",
      "bytes_written": 3210
    }
  ]
}
```

### Typical Duration

- PHPCS Analysis: ~10-20 seconds
- Per File:
  - Read: ~1 second
  - Backup: ~1 second
  - AI Fix: ~15-30 seconds (depends on file size and complexity)
  - Write: ~1 second
- **Total:** ~2-5 minutes for 3-5 files

## AI Prompt Engineering

The system sends this prompt to CodeLlama:

```
Fix this PHP code according to PSR-12 standards.

Issues found:
{
  "15": [{
    "column": 10,
    "type": "ERROR",
    "message": "Expected 1 space after IF keyword"
  }]
}

Current code:
```php
<?php

if($x) {
    echo "hello";
}
```

Provide ONLY the fixed PHP code, no explanations.
```

CodeLlama returns:

```php
<?php

if ($x) {
    echo "hello";
}
```

## Safety Features

1. **Automatic Backups**: Every file is backed up before modification
2. **Path Validation**: Prevents path traversal attacks (`..` blocked)
3. **Read-Only PHPCS**: Analysis doesn't modify files
4. **Atomic Writes**: Files are written completely or not at all
5. **Error Handling**: Failures don't affect other files

## Restoring from Backup

If AI fixes cause issues:

```bash
# List backups
docker exec master-nextcloud-1 find /var/www/html/apps-extra/openregister -name "*.backup_*"

# Restore a file
docker exec master-nextcloud-1 bash -c \
  "cp /var/www/html/apps-extra/openregister/lib/Service/MyService.php.backup_20251229_210000 \
      /var/www/html/apps-extra/openregister/lib/Service/MyService.php"
```

## Limitations

1. **CodeLlama is not perfect**: May occasionally introduce bugs
2. **Context window**: Very large files (>4000 lines) may be truncated
3. **Complex refactoring**: Works best for style fixes, not logic changes
4. **Performance**: AI processing takes ~15-30 seconds per file

## Best Practices

1. **Review AI Changes**: Always review before committing
2. **Test After Fixes**: Run PHPUnit tests to ensure functionality
3. **Incremental**: Fix a few files at a time, not the entire codebase
4. **Version Control**: Commit before running AI fixes
5. **Manual Review**: Use AI as a first pass, review manually

## Monitoring

### Check API Status

```bash
curl http://localhost:9090/
```

### Check Ollama

```bash
curl http://localhost:11434/api/tags | jq '.models[] | select(.name | contains("codellama"))'
```

### View Workflow Executions

```bash
curl -H "X-N8N-API-KEY: $API_KEY" \
  "http://localhost:5678/api/v1/executions?workflowId=aBWGzyu4xZf1CvFV&limit=5"
```

## Troubleshooting

### Ollama Not Responding

```bash
docker logs openregister-ollama
docker restart openregister-ollama
```

### API Server Down

```bash
cd /home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/scripts
pkill -f container-api-ai
python3 container-api-ai.py > /tmp/container-api-ai.log 2>&1 &
```

### Workflow Not Triggering

```bash
# Check if active
curl -H "X-N8N-API-KEY: $API_KEY" \
  "http://localhost:5678/api/v1/workflows/aBWGzyu4xZf1CvFV" | jq '.active'

# Activate
curl -X POST -H "X-N8N-API-KEY: $API_KEY" \
  "http://localhost:5678/api/v1/workflows/aBWGzyu4xZf1CvFV/activate"
```

## Files

- **API Server:** `scripts/container-api-ai.py`
- **n8n Workflow:** `n8n-templates/ai-code-fixer-workflow.json`
- **Documentation:** `n8n-templates/AI_CODE_FIXING_DOCUMENTATION.md`

## Future Enhancements

- [ ] Support for other code quality tools (PHPStan, Psalm)
- [ ] Batch processing (process multiple files in parallel)
- [ ] Confidence scores from AI
- [ ] Automatic PR creation with fixes
- [ ] Support for other languages (JavaScript, TypeScript)
- [ ] Custom AI prompts per file type
- [ ] Integration with Git for automatic commits

## Security Considerations

- API runs on localhost only
- Ollama runs in isolated container
- File operations limited to app directory
- No arbitrary command execution
- All operations logged

---

**Status:** ✅ Operational  
**Last Updated:** 2025-12-29  
**Maintainer:** OpenRegister Team



