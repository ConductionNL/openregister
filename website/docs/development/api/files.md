# File Upload API

## Multipart File Upload Endpoint

The frontend now uses the '/filesMultipart' endpoint for uploading files to objects. This change ensures compatibility with the backend's 'createMultipart' method in the FilesController, which is designed to handle multipart file uploads.

### Endpoint

- POST `/index.php/apps/openregister/api/objects/{register}/{schema}/{objectId}/filesMultipart`

### Required Parameters
- 'register': Register ID (string or number)
- 'schema': Schema ID (string or number)
- 'objectId': Object ID (string or number)
- 'files': Array of File objects (multipart form-data)
- 'tags': Optional, comma-separated string of tags
- 'share': Optional, boolean (true/false)

### Usage Example (Frontend)

Use FormData to append files, tags, and share flag, then POST to the endpoint. The frontend store's 'uploadFiles' action handles this automatically.

### Why This Change?

The previous endpoint ('/files') did not support multipart file uploads as required by the backend. The '/filesMultipart' endpoint is mapped to the 'createMultipart' method in the FilesController, which processes uploaded files correctly.

### Expected Behavior
- Files are uploaded and attached to the specified object.
- Tags and share flag are processed if provided.
- The backend returns a JSON response with the upload result.

### Error Handling

The file upload endpoint includes comprehensive error handling to prevent data corruption and provide clear feedback:

#### Upload Validation
Before processing any file, the system validates:
1. **Upload Errors**: Checks for PHP upload errors (size limits, partial uploads, etc.)
2. **File Existence**: Verifies the temporary file exists and is readable
3. **Content Reading**: Ensures file content can be read successfully

#### Error Messages
If an error occurs during upload, you'll receive a detailed error message:
- 'File upload error for {filename}: The uploaded file exceeds the upload_max_filesize directive in php.ini'
- 'Temporary file not found or not readable for: {filename}'
- 'Failed to read uploaded file content for: {filename}'

These validations prevent issues such as:
- Database constraint violations in the versioning system
- Corruption from partial file uploads
- Silent failures when temporary files are inaccessible

#### Best Practices
- Always check the response for error messages
- Handle errors gracefully in your frontend application
- Consider implementing retry logic for transient failures
- Monitor server logs for patterns in upload failures

---

*This documentation was last updated to reflect improved error handling in the file upload process.* 