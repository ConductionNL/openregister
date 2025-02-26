# File Attachments

File Attachments allow objects to include and manage associated files and documents. Open Register leverages Nextcloud's powerful file storage capabilities to provide a robust and secure file management system. By building on top of Nextcloud's proven infrastructure, Open Register inherits all the benefits of Nextcloud's file handling including:

- Secure file storage and encryption
- File versioning and history
- Collaborative features like sharing and commenting
- Preview generation for supported file types
- Automated virus scanning
- Flexible storage backend support

## Overview

When a register is created in Open Register, a share is also automatically created in Nextcloud. Then when a schema is created, a folder is created within that share, and when an object is created, a folder is created (using the UUID of the object) in the folder of the schema. This means that every object has a corresponding folder. Files present in that folder are assumed to be attached to the object. This gives a simple and intuitive system of coupling files to objects.

Alternatively, users can also relate (existing) files to an object by using the Nextcloud file system and tagging the file 'object:[uuid]' where '[uuid]' is the UUID of the object. In neither case is there a relation between the file and a property in the object. The files are however available through the object API because file objects are returned in the object metadata under the files array.

## File Object Structure

The file object contains the following properties:

| Property | Type | Description |
|----------|------|-------------|
| id | integer | Unique identifier of the file in Nextcloud |
| path | string | Full path to the file in Nextcloud |
| title | string | Name of the file |
| accessUrl | string | URL to access the file via share link |
| downloadUrl | string | Direct download URL for the file |
| type | string | MIME type of the file |
| extension | string | File extension |
| size | integer | File size in bytes |
| hash | string | ETag hash for file versioning |
| published | string | ISO 8601 timestamp when file was first shared |
| modified | string | ISO 8601 timestamp of last modification |
| labels | array | Array of tags and key-value pairs |

This hierarchical organization ensures files are properly categorized and easily accessible.

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

## Related Features

- [Content Search](content-search.md) - Search file contents
- [Object Relations](object-relations.md) - Link files to objects
- [Audit Trail](audit-trails.md) - Track file changes over time