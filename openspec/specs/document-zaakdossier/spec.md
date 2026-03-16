# document-zaakdossier Specification

## Purpose
Integrate document management with register objects to create case dossiers (zaakdossiers). Documents stored in Nextcloud Files MUST be linkable to register objects with metadata, versioning, and folder structure. The system MUST support document type classification, drag-and-drop upload, and structured dossier views showing all documents belonging to a case.

**Tender demand**: 80% of analyzed government tenders require document management in case dossiers.

## ADDED Requirements

### Requirement: Register objects MUST support linked documents
Objects MUST be able to reference one or more documents stored in Nextcloud Files, with metadata per link.

#### Scenario: Link a document to an object
- GIVEN an object `vergunning-1` in schema `vergunningen`
- WHEN the user uploads a document `aanvraagformulier.pdf` to the object
- THEN the document MUST be stored in Nextcloud Files at a structured path: `/{register}/{schema}/{objectId}/aanvraagformulier.pdf`
- AND a document link MUST be created with metadata:
  - `documentType`: `aanvraag`
  - `confidentiality`: `openbaar`
  - `uploadDate`: current timestamp
  - `uploadedBy`: current user

#### Scenario: Link multiple documents
- GIVEN object `vergunning-1` already has `aanvraagformulier.pdf`
- WHEN the user uploads `situatietekening.pdf` and `foto-locatie.jpg`
- THEN all three documents MUST appear in the object's dossier view
- AND the dossier MUST display document type, upload date, and file size for each

### Requirement: The system MUST provide a structured dossier view
Each object MUST have a dossier tab showing all linked documents organized by document type.

#### Scenario: Display dossier for a vergunning
- GIVEN vergunning `vergunning-1` has 8 linked documents across types: aanvraag (2), advies (3), besluit (1), correspondentie (2)
- WHEN the user opens the dossier tab
- THEN documents MUST be grouped by document type
- AND each document MUST show: filename, type, upload date, uploaded by, file size
- AND each document MUST be clickable to view/download
- AND a document count badge MUST be shown on the dossier tab

#### Scenario: Empty dossier
- GIVEN a new object with no linked documents
- WHEN the user opens the dossier tab
- THEN a helpful empty state MUST be shown with instructions to upload documents

### Requirement: Documents MUST support versioning
Document versions MUST be tracked via Nextcloud Files versioning, with version history visible in the dossier view.

#### Scenario: Upload new version of a document
- GIVEN document `besluit.pdf` version 1 is linked to `vergunning-1`
- WHEN the user uploads an updated `besluit.pdf`
- THEN the system MUST create a new version in Nextcloud Files
- AND the dossier MUST show `besluit.pdf (v2)` with access to version history
- AND version 1 MUST remain accessible via the version history

#### Scenario: View document version history
- GIVEN `besluit.pdf` has 3 versions
- WHEN the user clicks "Version history" on the document
- THEN a panel MUST show all versions with: version number, date, uploaded by
- AND each version MUST be downloadable

### Requirement: The system MUST support document type classification
Each document in a dossier MUST have a configurable document type for organization and compliance.

#### Scenario: Configure document types per schema
- GIVEN schema `vergunningen`
- WHEN the admin configures document types: `aanvraag`, `advies`, `besluit`, `correspondentie`, `bijlage`
- THEN the upload dialog MUST require selecting a document type
- AND the selected type MUST be stored as metadata on the document link

### Requirement: The system MUST support drag-and-drop upload
Documents MUST be uploadable via drag-and-drop onto the dossier view.

#### Scenario: Drag-and-drop upload
- GIVEN the user is viewing the dossier tab of `vergunning-1`
- WHEN they drag a file from their desktop onto the dossier area
- THEN the system MUST display a drop zone indicator
- AND upon dropping, the upload dialog MUST appear to select document type
- AND after confirmation, the document MUST be uploaded and linked

### Requirement: Documents MUST be searchable within dossiers
The dossier view MUST support searching across document filenames and metadata.

#### Scenario: Search documents in dossier
- GIVEN a dossier with 25 documents
- WHEN the user types `advies` in the dossier search bar
- THEN only documents with `advies` in the filename or document type MUST be shown

### Requirement: Bulk document operations MUST be supported
Users MUST be able to download all dossier documents as a ZIP archive.

#### Scenario: Download complete dossier as ZIP
- GIVEN a dossier with 8 documents
- WHEN the user clicks "Download dossier"
- THEN the system MUST generate a ZIP archive containing all 8 documents
- AND the ZIP MUST preserve the document type folder structure

### Current Implementation Status
- **Partial:**
  - `FileService` (`lib/Service/FileService.php`) provides file operations including upload, download, and management
  - `FolderManagementHandler` (`lib/Service/File/FolderManagementHandler.php`) manages folder structures for objects in Nextcloud Files
  - `FilePublishingHandler` (`lib/Service/File/FilePublishingHandler.php`) handles file publication workflows
  - `ReadFileHandler` (`lib/Service/File/ReadFileHandler.php`) and `CreateFileHandler` (`lib/Service/File/CreateFileHandler.php`) for file CRUD
  - Frontend file views exist at `src/views/files/`
  - Objects can have associated files stored in Nextcloud Files
  - File text extraction available via `TextExtractionService` (`lib/Service/TextExtractionService.php`)
  - Vectorization of file content via `VectorizationHandler` (`lib/Service/Object/VectorizationHandler.php`)
- **NOT implemented:**
  - Structured dossier view with documents grouped by document type
  - Document type classification configuration per schema
  - Document type metadata on file links
  - Drag-and-drop upload with document type selection dialog
  - Document version history display in dossier view (Nextcloud Files versioning exists but is not exposed in OpenRegister UI)
  - Document search within dossiers
  - Bulk download as ZIP archive with folder structure
  - Confidentiality metadata on document links
  - Document count badge on dossier tab
  - Empty state with upload instructions
- **Partial:**
  - File upload and linking to objects works at a basic level
  - Folder structure in Nextcloud Files exists (`/{register}/{schema}/{objectId}/`) but without document type sub-folders
  - Nextcloud's native file versioning is available but not surfaced in OpenRegister's UI

### Standards & References
- **ZGW DRC (Documenten Registratie Component)** — API standard for document registration in Dutch government
- **ZGW ZTC** — Document type definitions (informatieobjecttypen) in the catalog
- **CMIS (Content Management Interoperability Services)** — Standard for document management
- **MDTO** — Archival metadata for documents
- **Nextcloud Files API (WebDAV)** — Underlying storage and versioning
- **Nextcloud OCS File API** — File sharing and metadata
- **WCAG 2.1 AA** — Accessibility for file upload and document views

### Specificity Assessment
- The spec provides clear scenarios for the dossier workflow including upload, viewing, versioning, and search.
- Missing: API endpoints for dossier operations; how document type configuration is stored (schema property? admin setting?); how document metadata is linked to files (separate table? extended attributes?).
- Ambiguous: whether "linked documents" means Nextcloud Files references stored on the object or a separate join table; how document versioning interacts with object versioning (audit trail).
- Open questions:
  - Should document types be schema-specific (configured per schema) or global?
  - How does the dossier view integrate with Nextcloud's native Files app — can users browse the same files in both places?
  - Should the ZIP download include document metadata (CSV manifest) alongside the files?
  - How large can a dossier get before performance becomes a concern?
