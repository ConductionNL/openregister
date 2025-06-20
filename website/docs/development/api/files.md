# File Upload API

## Multipart File Upload Endpoint

The frontend now uses the '/filesMultipart' endpoint for uploading files to objects. This change ensures compatibility with the backend's 'createMultipart' method in the FilesController, which is designed to handle multipart file uploads.

### Endpoint

- POST '/index.php/apps/openregister/api/objects/{register}/{schema}/{objectId}/filesMultipart'

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

---

*This documentation was updated to reflect the change in file upload handling as of version 1.0.0.* 