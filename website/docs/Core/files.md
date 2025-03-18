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

## File Structure

A file in Open Register consists of the following key components:

| Property | Description |
|----------|-------------|
| `id` | Unique identifier for the file |
| `name` | Original filename |
| `contentType` | MIME type of the file |
| `size` | File size in bytes |
| `url` | URL to access the file |
| `objectId` | ID of the object this file is attached to |
| `created` | Timestamp of creation |
| `updated` | Timestamp of last update |
| `version` | Version identifier |
| `metadata` | Additional metadata about the file (optional) |

## Example File Metadata

```json
{
  "id": "file-12345",
  "name": "contract.pdf",
  "contentType": "application/pdf",
  "size": 1245678,
  "url": "/api/files/file-12345",
  "objectId": "agreement-78901",
  "created": "2023-03-15T09:45:00Z",
  "updated": "2023-03-15T09:45:00Z",
  "version": "1.0",
  "metadata": {
    "author": "Legal Department",
    "securityLevel": "confidential",
    "expiryDate": "2024-03-15"
  }
}
```

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

## Best Practices

1. **Define File Types**: Establish clear guidelines for what file types are allowed
2. **Set Size Limits**: Define appropriate size limits for different file types
3. **Use Metadata**: Add relevant metadata to improve searchability and context
4. **Consider Storage**: Choose appropriate storage backends based on file types and access patterns
5. **Implement Retention Policies**: Define how long files should be kept
6. **Plan for Backup**: Ensure files are included in backup strategies
7. **Consider Performance**: Optimize file storage for your access patterns

## Conclusion

Files in Open Register bridge the gap between structured data and unstructured content, providing a comprehensive solution for managing all types of information in your application. By integrating files with objects, schemas, and registers, Open Register creates a unified system where all your data—structured and unstructured—works together seamlessly. 