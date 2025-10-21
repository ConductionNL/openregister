---
title: Files
sidebar_position: 6
---

import ApiSchema from '@theme/ApiSchema';
import Tabs from '@theme/Tabs';
import TabItem from '@theme/TabItem';

# Files

## What are Files in Open Register?

In Open Register, **Files** are binary data attachments that can be associated with objects. They extend the system beyond structured data to include documents, images, videos, and other file types that are essential for many applications.

Files in Open Register are:
- Securely stored and managed
- Associated with specific objects
- Versioned alongside their parent objects
- Accessible through a consistent API
- Integrated with Nextcloud's file management capabilities

## Attaching Files to Objects

Files can be attached to objects in several ways:

1. Schema-defined file properties: When a schema includes properties of type 'file', these are automatically handled during object creation or updates
2. Direct API attachment: Files can be added to an object after creation using the file attachment API endpoints
3. Base64 encoded content: Files can be included in object data as base64-encoded strings
4. URL references: External files can be referenced by URL and will be downloaded and stored locally

## File Metadata and Tagging

Each file attachment includes rich metadata:

- Basic properties (name, size, type, extension)
- Creation and modification timestamps
- Access and download URLs
- Checksum for integrity verification
- Custom tags for categorization

### Tagging System

Files can be tagged with both simple labels and key-value pairs:
- Tags with a colon (':') are treated as key-value pairs and can be used for advanced filtering and organization

## Version Control

The system maintains file versions by:

- Tracking file modifications with timestamps
- Preserving checksums to detect changes
- Integrating with the object audit trail system
- Supporting file restoration from previous versions

## Security and Access Control

File attachments inherit the security model of their parent objects:

- Files are stored in NextCloud with appropriate permissions
- Share links can be generated for controlled external access
- Access is managed through the OpenRegister user and group system
- Files are associated with the OpenRegister application user for consistent permissions

## File Operations

The system supports the following operations on file attachments:

- Retrieving Files
- Updating Files
- Deleting Files

## File Preview and Rendering

The system leverages NextCloud's preview capabilities for supported file types:

- Images are displayed as thumbnails
- PDFs can be previewed in-browser
- Office documents can be viewed with compatible apps
- Preview URLs are generated for easy embedding

## Integration with Object Lifecycle

File attachments are fully integrated with the object lifecycle:

- When objects are created, their file folders are automatically provisioned
- When objects are updated, file references are maintained
- When objects are deleted, associated files can be optionally preserved or removed
- File operations are recorded in the object's audit trail

## Technical Implementation

The file attachment system is implemented through two main service classes:

- FileService: Handles low-level file operations, folder management, and NextCloud integration
- ObjectService: Provides high-level methods for attaching, retrieving, and managing files in the context of objects

These services work together to provide a seamless file management experience within the OpenRegister application.

## File Structure

<ApiSchema id="open-register" example   pointer="#/components/schemas/File" />

## How Files are Stored

Open Register provides flexible storage options for files:

### 1. Nextcloud Storage

By default, files are stored in Nextcloud's file system, leveraging its robust file management capabilities, including:
- Access control
- Versioning
- Encryption
- Collaborative editing

### 2. External Storage

For larger deployments or specialized needs, files can be stored in:
- Object storage systems (S3, MinIO)
- Content delivery networks
- Specialized document management systems

### 3. Database Storage

Small files can be stored directly in the database for simplicity and performance.

## File Features

### 1. Versioning

Files maintain version history, allowing you to:
- Track changes over time
- Revert to previous versions
- Compare different versions

### 2. Access Control

Files inherit access control from their parent objects, ensuring consistent security:
- Users who can access an object can access its files
- Additional file-specific permissions can be applied
- Permissions can be audited

### 3. Metadata

Files support rich metadata to provide context and improve searchability:
- Standard metadata (creation date, size, type)
- Custom metadata specific to your application
- Extracted metadata (e.g., EXIF data from images)

### 4. Preview Generation

Open Register can generate previews for common file types:
- Thumbnails for images
- PDF previews
- Document previews

### 5. Content Extraction

For supported file types, content can be extracted for indexing and search:
- Text extraction from documents
- OCR for scanned documents
- Metadata extraction

## Working with Files

### Uploading Files

Files can be uploaded and attached to objects:

```
POST /api/objects/{id}/files
Content-Type: multipart/form-data

file: [binary data]
metadata: {"author": "Legal Department", "securityLevel": "confidential"}
```

### Retrieving Files

You can download a file:

```
GET /api/files/{id}
```

Or get file metadata:

```
GET /api/files/{id}/metadata
```

### Listing Files for an Object

You can retrieve all files associated with an object:

```
GET /api/objects/files/{objectId}
```

### Updating Files

Files can be updated by uploading a new version:

```
PUT /api/files/{id}
Content-Type: multipart/form-data

file: [binary data]
```

### Deleting Files

Files can be deleted when no longer needed:

```
DELETE /api/files/{id}
```

## File Relationships

Files have important relationships with other core concepts:

### Files and Objects

- Files are attached to objects
- An object can have multiple files
- Files inherit permissions from their parent object
- Files are versioned alongside their parent object

### Files and Schemas

- Schemas can define expectations for file attachments
- File validation can be specified in schemas (allowed types, max size)
- Schemas can define required file attachments

### Files and Registers

- Registers can be configured with different file storage options
- File storage policies can be defined at the register level
- Registers can have quotas for file storage

## Use Cases

### 1. Document Management

Attach important documents to business objects:
- Contracts to customer records
- Invoices to order records
- Specifications to product records

### 2. Media Management

Store and manage media assets:
- Product images
- Marketing materials
- Training videos

### 3. Evidence Collection

Maintain evidence for regulatory or legal purposes:
- Compliance documentation
- Audit evidence
- Legal case files

### 4. Technical Documentation

Manage technical documents:
- User manuals
- Technical specifications
- Installation guides

## Advanced File Features

### 1. Auto-Share Configuration

File properties can be configured to automatically share uploaded files publicly. This is useful for assets that need to be accessible without authentication, such as product images or public documents.

#### Configuration via UI

When editing a schema in the OpenRegister UI:
1. Select a property with type 'file' or 'array' with items type 'file'
2. In the property actions menu, expand the 'File Configuration' section
3. Check the 'Auto-Share Files' checkbox
4. Save the schema

Files uploaded to this property will now be automatically publicly shared.

#### Configuration via API

In your schema definition, add the 'autoShare' option to file properties:

```json
{
  'properties': {
    'productImage': {
      'type': 'file',
      'autoShare': true,
      'allowedTypes': ['image/jpeg', 'image/png'],
      'maxSize': 5242880
    }
  }
}
```

When 'autoShare' is set to 'true', files uploaded to this property will automatically:
- Create a public share link
- Set the 'published' timestamp
- Generate a public 'accessUrl' and 'downloadUrl'

#### Example Response

```json
{
  'id': '12345',
  'title': 'Product A',
  'productImage': {
    'id': 789,
    'title': 'product-a.jpg',
    'accessUrl': 'https://your-domain.com/index.php/s/AbCdEfG123',
    'downloadUrl': 'https://your-domain.com/index.php/s/AbCdEfG123/download',
    'published': '2024-01-15T10:30:00+00:00',
    'size': 245678,
    'type': 'image/jpeg'
  }
}
```

### 2. Authenticated File Access

Files that are not publicly shared still have 'accessUrl' and 'downloadUrl' properties, but these URLs require authentication. This allows frontend applications to:
- Display file previews for logged-in users
- Provide download links that work within authenticated sessions
- Maintain security while offering convenient access

#### Authenticated URLs

Non-shared files return URLs with the following format:
- **Access URL**: '/index.php/core/preview?fileId={fileId}&x=1920&y=1080&a=1'
- **Download URL**: '/index.php/apps/openregister/api/files/{fileId}/download'

These URLs require the user to be authenticated to Nextcloud.

#### Example Response (Non-Shared File)

```json
{
  'attachment': {
    'id': 456,
    'title': 'confidential-report.pdf',
    'accessUrl': 'https://your-domain.com/index.php/core/preview?fileId=456&x=1920&y=1080&a=1',
    'downloadUrl': 'https://your-domain.com/index.php/apps/openregister/api/files/456/download',
    'published': null,
    'size': 1234567,
    'type': 'application/pdf'
  }
}
```

### 3. Logo/Image Metadata from File Properties

When a schema is configured to extract metadata fields like 'image' or 'logo' from file properties, the system automatically extracts the public share URL (or authenticated URL if not shared) and stores it in the object metadata.

#### Configuration

```json
{
  'properties': {
    'logo': {
      'type': 'file',
      'allowedTypes': ['image/png', 'image/jpeg'],
      'autoShare': true
    }
  },
  'configuration': {
    'objectImageField': 'logo'
  }
}
```

#### Result

The object's '@self.image' field will contain the share URL:

```json
{
  'id': '12345',
  'title': 'Company A',
  'logo': {
    'id': 789,
    'accessUrl': 'https://your-domain.com/index.php/s/XyZ789',
    'type': 'image/png'
  },
  '@self': {
    'name': 'Company A',
    'image': 'https://your-domain.com/index.php/s/XyZ789'
  }
}
```

This makes it easy to display company logos, product images, or other visual metadata in listings and search results.

### 4. File Deletion via API

Files can be deleted by setting the file property to 'null' (for single file properties) or an empty array (for array file properties).

#### Single File Deletion

```http
PUT /api/objects/{register}/{schema}/{id}
Content-Type: application/json

{
  'title': 'Updated Title',
  'attachment': null
}
```

This will:
- Delete the file from Nextcloud storage
- Remove the file record from the database
- Set the 'attachment' property to 'null' in the object data

#### File Array Deletion

```http
PUT /api/objects/{register}/{schema}/{id}
Content-Type: application/json

{
  'title': 'Updated Gallery',
  'images': []
}
```

This will:
- Delete all files in the array from Nextcloud storage
- Remove all file records from the database
- Set the 'images' property to an empty array in the object data

#### Use Cases

- **Privacy Compliance**: Remove sensitive files upon user request
- **Storage Management**: Clean up unused files
- **Data Lifecycle**: Remove temporary or expired files
- **Error Correction**: Remove incorrectly uploaded files

## Best Practices

1. **Define File Types**: Establish clear guidelines for what file types are allowed
2. **Set Size Limits**: Define appropriate size limits for different file types
3. **Use Metadata**: Add relevant metadata to improve searchability and context
4. **Consider Storage**: Choose appropriate storage backends based on file types and access patterns
5. **Implement Retention Policies**: Define how long files should be kept
6. **Plan for Backup**: Ensure files are included in backup strategies
7. **Consider Performance**: Optimize file storage for your access patterns
8. **Use Auto-Share Wisely**: Only enable 'autoShare' for files that should be publicly accessible
9. **Document File Deletion**: Maintain audit trails when files are deleted for compliance
10. **Handle Authentication**: Use authenticated URLs for sensitive files

## Conclusion

Files in Open Register bridge the gap between structured data and unstructured content, providing a comprehensive solution for managing all types of information in your application. With advanced features like auto-sharing, authenticated access, metadata extraction, and flexible deletion options, Open Register creates a unified system where all your data—structured and unstructured—works together seamlessly. 