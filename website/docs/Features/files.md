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