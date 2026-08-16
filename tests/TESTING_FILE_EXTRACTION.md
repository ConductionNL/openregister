# Testing File Text Extraction Background Job

This document describes how to test the asynchronous file text extraction feature that was implemented to fix race condition errors during file uploads.

## What Was Fixed

Previously, text extraction ran synchronously during file uploads, causing:
- "file_get_contents() failed to open stream" errors
- "Could not get local file path for extraction" errors
- "Text extraction failed" warnings
- Slow file uploads (users had to wait for processing)

The fix implements asynchronous background job processing, ensuring:
- File uploads complete instantly
- Text extraction happens in the background
- No race conditions (files are fully written before processing)
- Automatic retries for failed jobs

## Quick Test (Manual)

### 1. Run the Automated Test Script

```bash
cd openregister/tests
./manual-file-extraction-test.sh master-nextcloud-1
```

This script will:
- Check that OpenRegister is enabled
- Verify background job system is working
- Monitor logs for job activity
- Check for race condition errors

### 2. Upload a Test File

Through the OpenRegister UI or API:
- Upload a text file, PDF, or Word document
- The upload should complete instantly
- Check logs to see the background job queued

### 3. Monitor Background Jobs

```bash
# Watch for background job activity
docker logs -f master-nextcloud-1 | grep FileTextExtractionJob

# List queued jobs
docker exec -u 33 master-nextcloud-1 php occ background-job:list | grep FileTextExtractionJob

# Execute background jobs immediately (for testing)
docker exec -u 33 master-nextcloud-1 php occ background-job:execute
```

### 4. Check for Errors

```bash
# Should NOT see these errors anymore
docker logs --tail 200 master-nextcloud-1 | grep "file_get_contents.*Failed to open stream"
docker logs --tail 200 master-nextcloud-1 | grep "Could not get local file path"

# Should see these success messages
docker logs --tail 200 master-nextcloud-1 | grep "Text extraction completed successfully"
docker logs --tail 200 master-nextcloud-1 | grep "Queueing text extraction job"
```

## Unit Tests

Run the PHPUnit tests:

```bash
# Run all unit tests
cd openregister
./vendor/bin/phpunit tests/Unit/BackgroundJob/FileTextExtractionJobTest.php

# Run with verbose output
./vendor/bin/phpunit --verbose tests/Unit/BackgroundJob/FileTextExtractionJobTest.php

# Run specific test
./vendor/bin/phpunit --filter testSuccessfulTextExtraction tests/Unit/BackgroundJob/FileTextExtractionJobTest.php
```

## Integration Tests

Run the integration tests (requires database):

```bash
cd openregister
./vendor/bin/phpunit tests/Integration/FileTextExtractionIntegrationTest.php
```

## What to Verify

### ✅ Success Indicators

1. **Fast uploads**: File uploads complete in < 1 second
2. **Jobs queued**: Background jobs appear in job list
3. **Jobs execute**: Jobs run automatically or can be manually triggered
4. **Success logs**: See "Text extraction completed successfully" in logs
5. **No errors**: No race condition errors in recent logs

### ❌ Failure Indicators

1. **Slow uploads**: Uploads take several seconds (indicates synchronous processing)
2. **No jobs queued**: FileTextExtractionJob not appearing in job list
3. **Race condition errors**: Still seeing file path or stream errors
4. **Failed extractions**: Jobs executing but failing with errors

## Troubleshooting

### Background Jobs Not Running

Check background job configuration:
```bash
docker exec -u 33 master-nextcloud-1 php occ config:app:get core backgroundjobs_mode
```

If it's set to 'ajax', background jobs only run when users are active. Change to 'cron':
```bash
docker exec -u 33 master-nextcloud-1 php occ config:app:set core backgroundjobs_mode --value="cron"
```

### Jobs Queued But Not Executing

Manually trigger execution:
```bash
# Execute all pending jobs
docker exec -u 33 master-nextcloud-1 php occ background-job:execute

# Run cron (if cron mode is enabled)
docker exec -u 33 master-nextcloud-1 php -f /var/www/html/cron.php
```

### Jobs Failing

Check detailed logs:
```bash
# Watch logs in real-time
docker logs -f master-nextcloud-1 | grep -E "(FileTextExtractionJob|FileTextService|FileChangeListener)"

# Check last 100 error entries
docker logs --tail 100 master-nextcloud-1 | grep -E "error|Error|ERROR"
```

### FileChangeListener Not Triggering

Verify event listener is registered:
```bash
docker exec -u 33 master-nextcloud-1 php occ app:list | grep openregister
```

If not enabled:
```bash
docker exec -u 33 master-nextcloud-1 php occ app:enable openregister
```

## Performance Testing

### Test with Multiple Files

Upload 10 files simultaneously and verify:
- All uploads complete quickly
- 10 background jobs are queued
- Jobs execute successfully
- No race conditions

### Test with Large Files

Upload a large PDF (10+ MB) and verify:
- Upload completes immediately
- Background job processes it successfully
- Extraction takes longer but doesn't block uploads

### Test with Various File Types

Test with:
- Text files (.txt)
- PDFs (.pdf)
- Word documents (.docx)
- Spreadsheets (.xlsx)
- Unsupported formats (should skip gracefully)

## Expected Timings

### File Upload (User-Facing)
- **Before fix**: 2-10 seconds (blocked by extraction)
- **After fix**: < 1 second (immediate)

### Background Job Execution
- **Text files**: < 1 second
- **Simple PDFs**: 2-5 seconds
- **Complex PDFs/OCR**: 10-60 seconds
- **Word/Excel**: 2-10 seconds

### Job Queue Delay
- **Ajax mode**: When next user is active
- **Cron mode**: Within 5 minutes
- **Manual trigger**: Immediate

## Monitoring Commands

```bash
# Watch background job activity
watch -n 2 'docker exec -u 33 master-nextcloud-1 php occ background-job:list | grep FileTextExtractionJob | wc -l'

# Monitor logs continuously
docker logs -f master-nextcloud-1 2>&1 | grep --line-buffered -E "(FileTextExtractionJob|Queueing text extraction|extraction completed)"

# Check error rate
docker logs --since 1h master-nextcloud-1 2>&1 | grep -c "file_get_contents.*Failed to open stream"
```

## Success Criteria

The fix is working correctly if:
1. ✅ File uploads complete in < 1 second
2. ✅ Background jobs are queued automatically
3. ✅ Jobs execute successfully (check logs)
4. ✅ Zero race condition errors in recent logs
5. ✅ Text is extracted and stored in database
6. ✅ Users can search extracted text

## Additional Resources

- Background Job Documentation: `lib/BackgroundJob/FileTextExtractionJob.php`
- Event Listener: `lib/Listener/FileChangeListener.php`
- Text Service: `lib/Service/FileTextService.php`
- Unit Tests: `tests/Unit/BackgroundJob/FileTextExtractionJobTest.php`
- Integration Tests: `tests/Integration/FileTextExtractionIntegrationTest.php`

