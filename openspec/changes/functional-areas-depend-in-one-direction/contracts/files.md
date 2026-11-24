# Contract design: the Files area

Wave 5 design package D06 of the change `functional-areas-depend-in-one-direction` (hydra ADR-119,
Decisions 8, 9, 13 and 14). This file is a design. It changes nothing in `lib/`.

Measured on `origin/main` `911c2702` with the module graph that `modularity_check.py` reads. Lines are
code lines as that tool counts them (no comments, no blank lines).

## Summary

| | Today (`911c2702`) | After the open PRs | After this design |
|---|---|---|---|
| Exposed lines of Files | 3,689 | 1,560 | 170, or 87 with a thin `FilesRegistrar` |
| Exposed files of Files | 38 | 28 | 10 |
| References against the order into Files | 66 | 39 | 0 |
| References against the order out of Files | 6 | 5 | 0 |
| Lines that count to Files | 18,752 | 18,802 | 25,415 |
| References against the order, whole app | 727 | 675 | 623 |
| Exposed lines, whole app | 155,651 | 153,352 | 148,550 |

Three columns, because Files is the area with the most work in flight. Fourteen open PRs change the
Core → Files direction (P06, P07, P12, P24, P30, P34 follow-ups, P40, P41, P42, P45). The middle column
is a simulation: those PRs merged onto `911c2702` in a scratch tree. Where two of them conflict in a
registrar or a `Files` interface, the later one's text was kept, and the csv was rebuilt from each
branch's own row changes. This approximation only touches edges that point down, so it does not change
the counts against the order. The "after" column applies every move in this design to that tree and
then runs the exposure and order arithmetic of `modularity_check.py` again. A new port's line count is
estimated from the existing ports in `lib/Kernel/Contracts/Core/`, which have 6 to 11 lines for 1 to 4
one-line methods.

The real Files work in this design is not in `lib/Files/`. It is in `lib/Service/File/` and
`lib/Service/FileService.php`: 76 files and 5,675 lines of file-storage work that count to Core today.
All but three of the 39 references against the order that are left after the open PRs start there. The
decision "Files: first a Core-owned port for what Core needs, then rows may follow" is the order of this
design. The higher areas then reach Files through a small set of Files-owned ports. Where a Core port
already answers their question, they use that port instead, which costs Files nothing.

## 1. The exposed files of Files today

38 files, 3,689 lines. "Callers" lists the other area's files that reference the file. The last column
says which open PR already removes the reference, if any.

### Anonymisation pipeline facts (6 files, 1,756 lines; Compliance)

| File | Lines | Callers | Open PR |
|---|---|---|---|
| `lib/Files/Service/File/DocumentProcessingHandler.php` | 679 | Compliance `Service/Anonymisation/PipelineSteps` (`implementedBy` column) | #10844 |
| `lib/Files/Service/File/Sanitizer/DocxSanitizer.php` | 424 | Compliance `PipelineSteps` | #10844 |
| `lib/Files/Service/File/Pdf/PdfTextReplacer.php` | 259 | Compliance `PipelineSteps` | #10844 |
| `lib/Files/Service/File/Sanitizer/OdtSanitizer.php` | 170 | Compliance `PipelineSteps` | #10844 |
| `lib/Files/Service/File/Pdf/PdfMetadataSanitizer.php` | 163 | Compliance `PipelineSteps` | #10844 |
| `lib/Files/Service/TextExtraction/PatternSet/NlPatternSet.php` | 61 | Compliance `Service/Anonymisation/DetectorVersionReader` (`VERSION`, `CHANGED_AT`) | #10844 |

### Image redaction and text disclosure (6 files, 975 lines)

| File | Lines | Callers |
|---|---|---|
| `lib/Files/Service/TextExtraction/EntityRecognitionHandler.php` | 810 | Core `Service/File/ImageRedactionService` |
| `lib/Files/Db/AnonymisationLog.php` | 64 | Core `ImageRedactionService` |
| `lib/Files/Db/AnonymisationLogMapper.php` | 46 | Core `ImageRedactionService` |
| `lib/Files/Db/TextDisclosure.php` | 27 | Compliance `Service/Anonymisation/TextDisclosureRecorder` |
| `lib/Files/Db/TextDisclosureMapper.php` | 21 | Compliance `TextDisclosureRecorder` |
| `lib/Files/Db/ChunkSourceReaderInterface.php` | 7 | Core `ImageRedactionService` |

### File-storage handlers (14 files, 312 lines; callers almost all Core `lib/Service/File/*` and `FileService`)

| File | Lines | Callers | Open PR |
|---|---|---|---|
| `lib/Files/Db/File.php` | 56 | Core `FileFormattingHandler`, `UpdateFileHandler`, `FileService` | |
| `lib/Files/Db/RegisterFolderRecorder.php` | 39 | Core `FolderManagementHandler`, `ServiceRegistrar` | #10781 |
| `lib/Service/FileServiceInterface.php` | 36 | Core `ObjectsController`, `TagsControllerServices`, `FileService`; Configuration `ContractBindings` | #10810 (tags) |
| `lib/Files/Db/FileMapperInterface.php` | 34 | Core (6: four file handlers, `ReadFileSources`, `OrganisationUsageService`); Processes `UnreadCountCalculator`; Search `NearDuplicateScope`, `ObjectTextSimilarity` | #10838, #10739 |
| `lib/Service/FileHandlerSupportInterface.php` | 20 | Core (9: the file handlers and `FileService`); Configuration `ContractBindings` | |
| `lib/Service/FileFolderServiceInterface.php` | 17 | Core `ObjectStorageServices`, `ObjectService`, `RegisterService`, `RegisterServiceHelpers`; Integrations `FilesProvider`; Configuration `ContractBindings` | #10838, #10852 |
| `lib/Service/FileWriterInterface.php`, `FileReaderInterface.php` | 16 each | Core `ObjectFilesInterface`; Configuration `SeedRelatedItems`, `ContractBindings`; Processes `PortalTaskService` | #10807 |
| `lib/Files/Service/File/FileAuditHandlerInterface.php` | 14 | Core `FileHistoryHandlers`, `FileService` | |
| `lib/Files/Service/File/FileOwnershipHandlerInterface.php` | 13 | Core (5 file handlers); Processes `FrozenNodeWriteListener` (`APP_USER`) | #10805 (two of the five) |
| `lib/Files/Service/File/FileSharingHandlerInterface.php` | 12 | Core `FileFolderHandlers`, `FileService` | |
| `lib/Files/Service/File/FileRestrictionGuardInterface.php` | 9 | Core `FilePublishingHandler`, `RenderObject`; Integrations `FilesProvider`, `ShareLinkService` | #10759 (publishing) |
| `lib/Files/Service/File/PreviewSizeInterface.php` | 8 | Core `FilePreviewHandler` | #10759 |
| `lib/Files/Service/File/ShareUrlBuilderInterface.php` | 6 | Core `FileServicePlatform`, `FileService` | #10759 |

### File rights and scopes (3 files, 326 lines)

| File | Lines | Callers | Open PR |
|---|---|---|---|
| `lib/Files/Service/File/ObjectFileAccess.php` | 199 | Core `Service/Rbac/SharerFolderAccess` | #10830 |
| `lib/Files/Service/File/FileReadScope.php` | 103 | Core `Service/Search/VectorResultFilter`; Search `NearDuplicateScope` | #10796, #10739 |
| `lib/Files/Service/File/CallerFileAccess.php` | 24 | Processes `Controller/FileEntityRelationsController` (`mayOpen`) | |

### Entity mentions (2 files, 51 lines)

| File | Lines | Callers |
|---|---|---|
| `lib/Files/Db/EntityRelationMapperInterface.php` | 28 | Core `Db/EntityRelationMapper` (implements), `DbRegistrar` (#10788), `ImageRedactionService`; Processes (6: `EntityRelationsController`, `FileEntityRelationsController`, `GdprEntitiesController`, two support bags, `RelationDecisionStores`) |
| `lib/Files/Db/EntityMention.php` | 23 | Core `Db/EntityRelationMapper` |

### Settings, conversion, download, registration (7 files, 285 lines)

| File | Lines | Callers |
|---|---|---|
| `lib/Files/FilesRegistrar.php` | 92 | Platform `AppInfo/Application` |
| `lib/Files/Service/File/MaskForm.php` | 87 | Configuration `Service/Settings/FileSettingsHandler` (`normalise`) |
| `lib/Files/Service/TextExtraction/PatternSet/JurisdictionPatternSets.php` | 75 | Configuration `Service/Settings/EntityPatternSetSetting` (`codes`, `unknown`) |
| `lib/Files/Service/File/BulkDownloadArchive.php` | 11 | Core `ObjectsController` (`downloadFiles`) |
| `lib/Files/Service/File/Pdf/Fallback/NcOfficeConverterInterface.php` | 8 | Configuration `ExportService`; Search `Reporting/PdfReportWriter` (#10843) |
| `lib/Files/Service/RiskLevelServiceInterface.php` | 6 | Platform `Repair/RegisterRiskLevelMetadata` |
| `lib/Files/Service/File/RegisterFolderProvisionerInterface.php` | 6 | Configuration `ConfigurationServiceRegistrar`, `ImportHandler`; Platform `Repair/CreateMissingRegisterFolders` |

### References against the order out of Files (6)

| Reference | Open PR |
|---|---|
| `Files/Db/ChunkMapper` → Integrations `Db/Source` (unused import) | #10847 |
| `Files/Db/EntityRelationMapperInterface` → Processes `Db/DecisionMetadataEdit` | |
| `Files/Service/File/DocumentRedactionServices` → Configuration `Service/Settings/FileSettingsHandler` (`maskForms`) | |
| `Files/Service/TextExtraction/EntityRecognitionHandler` → Configuration `FileSettingsHandler` (`confidenceThreshold`, `DEFAULT_CONFIDENCE_THRESHOLD`) | |
| `Files/Service/NoteService`, `Files/Service/Sharing/AccessLinkReader` → Configuration `Db/StoredEntity` (`StoredEntity::object`) | |

### What the open PRs leave

The open PRs remove 28 of the 72 references: 27 into Files and the unused `Source` import. They also take
Files' exposure from 3,689 to 1,560 lines. This design starts from that state and covers the other 44.
Of those, 36 references into Files start in Core `lib/Service/File/*` and `FileService`.

## 2. What outside callers actually need

| Capability | Who needs it | What they call |
|---|---|---|
| The file store as Core sees it: read, write, folders, rights | Core save, render, merge, delete, sharing; Configuration seeding and example sets; Processes portal; Integrations files provider | Already Core ports after the open PRs: `StoredFileReaderInterface`, `StoredFileWriterInterface` (both via `ObjectFilesInterface`), `ObjectFileRecordsInterface`, `FileNodeLookupInterface`, `ObjectFolderLookupInterface`, `ObjectFolderAccessInterface`, `EntityFolderInterface`, `FileSizeTotalsInterface`, `FileReadRestrictionInterface`, `ShareLinkUrlInterface`, `PreviewDimensionsInterface`, `FileAccountInterface`, `RegisterFolderRecordsInterface`, `ObjectFileRightsInterface`, `ReadableFileHitsInterface`, `FileTagCatalogueInterface` |
| Check a caller-supplied folder on save | Core `SaveObject` | `FolderManagementHandler::assertFolderIsAccessible(FolderAccessCheck)` |
| Find a node in openregister's folder tree | Core `ObjectShareReader`, `ObjectShareSurface` (and `ObjectSharingService` through it) | `getObjectFolder($object)`, `getNodeById($id)` |
| The openregister file account's user id | Compliance `SharerAccessSweepJob`; Integrations `ShareLinkService`; Processes `FrozenNodeWriteListener` | `getOpenRegisterUserFolder()->getOwner()->getUID()`, `lookup('…\FileService')->getUser()->getUID()`, `FileOwnershipHandlerInterface::APP_USER` |
| Download all files of an object | Core `ObjectsController::downloadFiles` | `createObjectFilesZip`, `getFiles`, `getAuditHandler()->logBulkDownload(BulkDownloadArchive)` |
| Image MIME types of the pipeline | Compliance `PipelineSteps` | `ImageRedactionService::STANDALONE_TYPES` |
| An entity's files | Integrations `FilesProvider` | `getFilesForEntity($object)`, then `readableNodes` |
| Entity mentions and their decisions | Processes entity-relation controllers, GDPR entities controller, `RelationDecisionService` | `find`, `findByFileId`, `findByEntityId`, `linkDecision`, `updateDecisionMetadata(DecisionMetadataEdit)` |
| May the caller open this file | Processes `FileEntityRelationsController` | `CallerFileAccess::mayOpen(int)` |
| openregister's managed document store | Processes `FileChangeListener` (`DocumentStore::root()`), `ReviewPolicyFileOwners` (`ManagedFileOwnerLookup::find(Node)`) | the store root and the object that owns a managed file |
| Rules for the file settings Configuration stores | Configuration `FileSettingsHandler`, `EntityPatternSetSetting` | `MaskForm::normalise`, `ArchiveBounds::SETTINGS_KEY`/`DEFAULTS`/`fromSettings()->toArray()`, `JurisdictionPatternSets::codes`/`unknown` |
| The redaction settings Files reads | Files `EntityRecognitionHandler`, `DocumentRedactionServices` (against the order today) | `confidenceThreshold()`, `DEFAULT_CONFIDENCE_THRESHOLD`, `maskForms()` |
| Provision register folders | Configuration import; Platform repair step | `ensureFolders` |
| Office document conversion | Configuration `ExportService` | `isAvailable`, `pdfToOdt`, `odtToPdf` |
| A stored row behind a Kernel contract | Core `AccessLinkController`; Files `NoteService`, `AccessLinkReader`; Configuration import and export | `StoredEntity::object/schema/register` |
| Registration | Platform `Application` | `FilesRegistrar::register`, `FilesMapperRegistrar` (#10788) |

## 3. The proposed contract set

### 3a. Classes that count to the area whose work they do (ADR-119 move 3, csv rows only, no file moves)

Each row change rests on what the class does and who uses it, never on hiding a reference.

| Id | Files (lines) | From → to | Why it is that area's work |
|---|---|---|---|
| A1 | `lib/Service/File/*` except the seven files in A2 (75 files, 4,978) and `lib/Service/FileService.php` (697) | Core → Files | The decided case. The create, read, update, delete, lock, version, publish, preview, folder, URL fetch, antivirus, archive walk and image redaction handlers store and process files. `FileService` is their facade. Their outside users reach them through the Core ports of section 2 once B3 and B4 are done. |
| A1 | `lib/Command/DocumentStoreStatusCommand.php` (41), `lib/BackgroundJob/PublishedDigestVerifyJob.php` (87), `lib/Db/FileDigest.php` (31), `lib/Db/FileDigestMapper.php` (34) | Core → Files | The command describes the document store. The job and its table verify the digests of published files for `PublishedDigestService` (A1). Those are their only users. |
| A2 | stay Core: `FileTarget`, `FileUpload`, `FileOverwrite` (values of `StoredFileWriterInterface`), `FolderAccessCheck` (value of C1), `FilePropertyRules`, `DangerousFileExtensions`, `ExecutableContentDetector` | — | The first four are the vocabulary of Core ports. The last three are the upload rules of a schema file property, which Core's save path (`FileUploadGuard`, `FilePropertyParts`) applies. Files' `FileValidationHandler` and Processes' `FormUploadStore` reuse them, pointing down. |
| A3 | `lib/Db/EntityRelationMapper.php` (515), `lib/Db/DecisionMetadataChange.php` (12) | Core → Files | The store of entity mentions found in files. It implements the Files `EntityRelationMapperInterface`, and only the Files map (#10788) and `MapperWiring` name it. `DecisionMetadataChange` is its own helper. The entity `Db/EntityRelation` stays Core. The Kernel event names it through `EntityRelationInterface` (#10809), and eight Processes classes read it. |
| A3 | `lib/Db/DecisionMetadataEdit.php` (12) | Processes → Files | The parameter object of `EntityRelationMapper::updateDecisionMetadata()` (its own `@spec` says so). As a Processes class it makes the mapper's interface reference up. It becomes the value of the Files port F1. |
| A4 | `lib/Service/Anonymisation/TextDisclosureRecorder.php` (40) | Compliance → Files | Its only caller is Files' `EntityRecognitionBackends`, and it names only Files' `TextDisclosure` and `TextDisclosureMapper`. It writes the disclosure rows of the recognition run. |
| A5 | `lib/Repair/CreateMissingRegisterFolders.php` (47), `lib/Repair/RegisterRiskLevelMetadata.php` (19) | Platform → Files | Repair steps that provision register folders and initialise the file risk-level metadata key. Only `appinfo/info.xml` names them. Their work is the Files capability they call. |
| A6 | `lib/Db/StoredEntity.php` (27) | Configuration → Core | It narrows a Kernel contract to the stored Core row (`Schema`, `Register`, `ObjectEntity`). Its lowest user is Core's `AccessLinkController`. This also removes the two Files → Configuration references and one Core → Configuration reference. |

### 3b. Ports owned by Files: `lib/Kernel/Contracts/Files/`

Higher areas use these. Each port is one capability, and its methods are what the callers call today.
Where the Files interface already exists, it `extends` the port and keeps its name, which is the #10688
pattern.

| Id | Port | Methods (one line each) | Implemented by | Lines |
|---|---|---|---|---|
| F1 | `EntityMentionDecisionsInterface` | `public function find(int $id): EntityRelation;` · `public function findByFileId(int $fileId): array;` · `public function findByEntityId(int $entityId): array;` · `public function linkDecision(EntityRelation $relation, string $decisionUuid, ?string $expectedUuid = null, bool $guarded = false): EntityRelation;` · `public function updateDecisionMetadata(DecisionMetadataEdit $edit): EntityRelation;` | `EntityRelationMapper`, through `EntityRelationMapperInterface extends` the port | 11 |
| F2 | `CallerFileRightsInterface` | `public function mayOpen(int $fileId): bool;` | `Files/Service/File/CallerFileAccess` | 6 |
| F3 | `ManagedFilesInterface` | `public function root(): string;` · `public function ownerOf(Node $file): ?ObjectEntity;` | new `Files/Service/File/ManagedFiles`, on `DocumentStore::root()` and `ManagedFileOwnerLookup::find()` | 7 |
| F4 | `FileSettingRulesInterface` | `public function normaliseMaskForms(array $maskForms): array;` · `public function archiveBounds(array $fileSettings): array;` · `public function archiveDefaults(): array;` · `public function patternSetCodes(): array;` · `public function unknownPatternSets(array $codes): array;` | new `Files/Service/FileSettingRules`, on `MaskForm`, `ArchiveBounds` and `JurisdictionPatternSets` | 9 |
| F5 | `RedactionSettingsInterface` | `public const DEFAULT_CONFIDENCE_THRESHOLD = …;` · `public function confidenceThreshold(): float;` · `public function maskForms(): array;` | Configuration `Service/Settings/FileSettingsHandler`, bound in Configuration's registrar. Its own constant becomes the inherited one, with the same value. | 8 |
| F6 | `RegisterFolderProvisioningInterface` | `public function ensureFolders(…): …;` (the existing declaration, moved up unchanged) | `RegisterFolderProvisioner`, through `RegisterFolderProvisionerInterface extends` the port | 6 |
| F7 | `OfficeDocumentConversionInterface` | `isAvailable`, `pdfToOdt`, `odtToPdf` (the existing declarations, moved up unchanged) | `ConversionManagerConverter`, through `NcOfficeConverterInterface extends` the port | 8 |

F4 and F5 are a pair. Files asks Configuration for its redaction settings (F5), and Configuration asks
Files for the rules those settings follow (F4). Each port belongs to the lower area, Files, so both
directions point down to it. F4 makes three static calls instance calls on an injected port, so
`FileSettingsHandler` gains one constructor parameter. Only the container constructs it.

Ports deliberately not added:
- No Files port for stored-file reads and writes, folders, rights or the account. The Core ports listed in section 2 already answer every higher caller. Configuration, Processes and Integrations use those, so Files pays nothing for them.
- No port for the registrars. `FilesRegistrar` and `FilesMapperRegistrar` stay named by `Application`, as every area's registrar is.
- No port for `RiskLevelServiceInterface`. After A5, its only outside caller is Files' own.

### 3c. Ports owned by Core: `lib/Kernel/Contracts/Core/`

These count to Core's budget (D02), not to Files'. Files implements them and binds them in its own
registrar once the rows of A1 move.

| Id | Port | Change | Implemented by | Lines |
|---|---|---|---|---|
| C1 | `FolderBindingCheckInterface` (new) | `public function assertFolderIsAccessible(FolderAccessCheck $check): Folder;` | `FolderManagementHandler` (its existing `final public` method) | 6 |
| C2 | `ObjectFolderLookupInterface` (#10813) | gains `public function getNodeById(int $nodeId): ?Node;` | `FileService`, which delegates to `FolderManagementHandler::getNodeById` the way its `getObjectFolder` already does | +1 |
| C3 | `EntityFolderInterface` (#10838) | gains `getFilesForEntity`, moved unchanged from `FileFolderServiceInterface` (which keeps extending the port) | `FileService` | +1 |
| C4 | `FileAccountInterface` (#10805) | gains `public const APP_USER = 'openregister';`. `FileOwnershipHandlerInterface` stops declaring it and inherits the same value. | `FileOwnershipHandler` | +1 |

C1 is not folded into `ObjectFolderAccessInterface` (#10852). That port re-validates a folder that
openregister wrote (`assertObjectFolderAccessible`, `FileService`). C1 checks a folder that a caller
supplies on save. `FileService::assertObjectFolderAccessible` deliberately does not call it, as its
comment explains. They are two questions with two implementers.

These need no port:
- **The bulk download endpoint.** `downloadFiles` and its private `auditBulkDownload` move out of `ObjectsController` to a new `lib/Files/Controller/ObjectFilesArchiveController`. This follows the decision on `ObjectsController` endpoints. `appinfo/routes.php` keeps the URL `/api/objects/{register}/{schema}/{id}/files/download` and the verb `GET`. The route name becomes `objectFilesArchive#downloadFiles`. The object lookup and the access check run in the same order.
- **The image types of the pipeline.** `PipelineSteps` (Compliance) already keeps the step vocabulary: ids, labels, MIME types (#10844). The image MIME list moves there as `PipelineSteps::IMAGE_TYPES`, and `ImageRedactionService::STANDALONE_TYPES` is defined as that constant, with the same value. The reference then points down, Files → Compliance.
- **`StoredEntity`.** A6 only.

### 3d. Entities that cross the boundary

| Entity | Today crosses to | After |
|---|---|---|
| `Files\Db\File` | Core file handlers, `FileService` | Internal to Files (A1). |
| `Files\Db\EntityMention`, `AnonymisationLog`, `TextDisclosure` | Core `EntityRelationMapper`, `ImageRedactionService`; Compliance recorder | Internal to Files (A1, A3, A4). |
| `Db\EntityRelation` (Core) | Read by Files and Processes | Stays Core. It is named by the Kernel event through `EntityRelationInterface` (#10809) and by eight Processes classes. Moving it would expose 39 lines to Processes and make Kernel reference Files. F1 names it, pointing down. |
| `Db\DecisionMetadataEdit` | Processes → named by Files | Counts to Files (A3). It is the value of F1, exposed to Processes `RelationDecisionService` (12 lines). |
| `Db\ObjectEntity`, `Register`, `Schema` (Core) | Named by Files ports | Stay. They point down. |

## 4. The budget

| Exposed file after | Lines | Named by |
|---|---|---|
| `lib/Files/FilesRegistrar.php` | 93 (10 once B6 hides its map) | Platform |
| `Db/DecisionMetadataEdit` (value of F1) | 12 | Processes |
| F1 `EntityMentionDecisionsInterface` | 11 | Processes (6 files) |
| `lib/Db/FilesMapperRegistrar.php` (#10788) | 10 | Platform |
| F4 `FileSettingRulesInterface` | 9 | Configuration |
| F5 `RedactionSettingsInterface` | 8 | Configuration (implements) |
| F7 `OfficeDocumentConversionInterface` | 8 | Configuration |
| F3 `ManagedFilesInterface` | 7 | Processes |
| F2 `CallerFileRightsInterface` | 6 | Processes |
| F6 `RegisterFolderProvisioningInterface` | 6 | Configuration |
| **Total** | **170** (10 files), **87** with the thin registrar | |

Before: 3,689 lines in 38 files today, and 1,560 lines in 28 files after the open PRs. After: 170 lines
in 10 files, or 87 with the thin registrar.

`FilesRegistrar` is the largest item. Its `BINDINGS` constant is a 21-entry map, and B6 adds the
`FileService` and handler bindings to it. The brief asks for one hidden map per area per directory and a
thin registrar. B6 therefore moves the map into a new `lib/Files/FilesBindings.php`, which only
`FilesRegistrar` names, as `FilesMapperBindings` already is for `lib/Db`. The registrar keeps
`register()` and the `NoteService` factory, about 10 lines.

Files' share of the target. After A1 to A5, Files is 25,415 of the 449,411 non-Kernel lines (5.7%).
Kernel takes about 9,700 lines, which leaves about 9,200 for the other nine areas at 4.0 and about 2,900
at 4.5. Files' proportional share is therefore about 520 lines at 4.0 and about 165 at 4.5.
- **4.0:** 170 lines fits, and so does 87.
- **4.5:** 87 lines fits. 170 does not fit without the thin registrar, which is why B6 includes it.

Effects on other areas, from the same simulation:
- **Core:** exposed lines go from 65,738 to 62,405. `FileService` (697), `FolderManagementHandler` (655), `EntityRelationMapper` (515), `ImageRedactionService` (253) and 26 smaller file classes are no longer exposed Core files. The Core ports cost 6 new lines and 3 lines on existing ports.
- **Compliance:** 15,738 → 15,698. **Processes:** 32,004 → 31,992. **Configuration:** 6,295 → 6,268 (`StoredEntity` moves to Core's budget).

**Flagged: four Core files become newly exposed.** `modularity_check.py --check` refuses a newly exposed
file unless it is the deliberate contract of the change. These four point down, so the refs are allowed:
- `FolderAccessCheck` (13) is C1's value.
- `StoredEntity` (27) is already exposed today, but on Configuration's budget. After A6 it is on Core's.
- `ExecutableContentDetector` (147) and `DangerousFileExtensions` (63) become exposed because Files' `FileValidationHandler` (A1) uses them. That costs Core 210 lines.

A port cannot replace those two for `FileValidationHandler`, because it calls all six public methods of
the detector. Such a port would copy the class method for method, which is not allowed. The cheaper
alternative is to make the upload safety check one verdict: one method that both `FileUploadGuard` (Core)
and `FileValidationHandler` call. Then the detector could count to Files behind a one-method Core port.
That needs `FileUploadGuard` restructured, and it is D02's call. The B6 PR lists the four files as
deliberate.

Nothing in this design breaks a rule. Every reference that remains points down, every class keeps its
name and directory, and no interface copies a class.

## 5. The build packages

Dependency order. Within a step, packages are independent and touch disjoint files, except where a
package says it is stacked. Every package that touches `scripts/component-map.csv` also regenerates
the generated components yaml and the deptrac `areas.yaml`. Those generated files are listed once here
and not per package. Each package waits for the open PRs it names. They change the same files, and their
ports are what these packages call.

**B1: Files-owned ports and their Files implementations (F1, F2, F3, F4, F6, F7).** No caller changes,
so nothing is newly exposed.
Files:
- new `lib/Kernel/Contracts/Files/EntityMentionDecisionsInterface.php`, `CallerFileRightsInterface.php`, `ManagedFilesInterface.php`, `FileSettingRulesInterface.php`, `RegisterFolderProvisioningInterface.php`, `OfficeDocumentConversionInterface.php`
- `lib/Files/Db/EntityRelationMapperInterface.php` (extends F1)
- `lib/Files/Service/File/CallerFileAccess.php` (implements F2)
- `lib/Files/Service/File/RegisterFolderProvisionerInterface.php`, `lib/Files/Service/File/Pdf/Fallback/NcOfficeConverterInterface.php` (extend F6, F7)
- new `lib/Files/Service/File/ManagedFiles.php`, `lib/Files/Service/FileSettingRules.php`
- `lib/Files/FilesRegistrar.php` (aliases)
- `scripts/component-map.csv` (two new Files rows)
- tests: `FilesRegistrarTest` (binding count), `ManagedFilesTest`, `FileSettingRulesTest` (values identical to the static calls)

`DecisionMetadataEdit` is named by F1 and is Processes today. B1 therefore also moves its row (A3), so
F1 does not add a reference against the order. Waits for #10788, #10738 and the `FilesRegistrar` PRs
(#10739, #10759, #10781, #10796, #10805, #10810, #10830, #10838, #10844).

**B2a: Processes callers.**
Files:
- `lib/Controller/EntityRelationsController.php`, `FileEntityRelationsController.php`, `GdprEntitiesController.php`
- `lib/Controller/Support/EntityRelationsMappers.php`, `GdprEntitiesMappers.php`
- `lib/Service/Decision/RelationDecisionStores.php`, `ReviewPolicyFileOwners.php` (F3)
- `lib/Listener/FileChangeListener.php` (F3)
- `lib/Service/Interaction/UnreadCountCalculator.php` (Core `ObjectFileRecordsInterface`)
- `lib/Listener/FrozenNodeWriteListener.php` and `lib/Kernel/Contracts/Core/FileAccountInterface.php`, `lib/Files/Service/File/FileOwnershipHandlerInterface.php` (C4)
- `lib/Service/Portal/PortalTaskService.php` (Core `StoredFileWriterInterface`)
- their tests, with fake containers keyed by the ports

It removes the exposure of `EntityRelationMapperInterface`, `CallerFileAccess`, `FileMapperInterface`,
`FileOwnershipHandlerInterface` and `FileWriterInterface` to Processes. `FileChangeListener` keeps its
optional, nullable argument. A missing store still yields `null`, which the existing tests pin.

**B2b: Configuration callers and the redaction settings (F5, F4 callers, A6).** Stacked after #10744
and #10797, which change `FileSettingsHandler`.
Files:
- new `lib/Kernel/Contracts/Files/RedactionSettingsInterface.php`
- `lib/Service/Settings/FileSettingsHandler.php` (implements F5, asks F4), `EntityPatternSetSetting.php` (F4)
- Configuration's registrar binding for F5 (`lib/Service/ContractBindings.php`)
- `lib/Files/Service/File/DocumentRedactionServices.php`, `lib/Files/Service/TextExtraction/EntityRecognitionHandler.php` (type F5)
- `lib/Service/Configuration/SeedRelatedItems.php`, `ImportHandler.php`, `ConfigurationServiceRegistrar.php` (Core `StoredFileWriterInterface`, F6). `ImportHandler::setFileService(?FileService)` widens to `?StoredFileWriterInterface`, and `FileService` implements it.
- `lib/Service/ExampleSet/ExampleSetFiles.php` (Core `ObjectFilesInterface` from the container)
- `lib/Service/ExportService.php` (F7)
- `scripts/component-map.csv` (A6 `StoredEntity` → Core)
- tests, with a golden test of the stored file settings written first

It removes the two Files → Configuration references to `FileSettingsHandler`, and A6 removes the two to
`StoredEntity`. The redaction threshold and the mask form per entity type are read from the same handler
instance at the same point.

**B2c: Integrations callers (C3).** Waits for #10738 and #10838.
Files:
- `lib/Kernel/Contracts/Core/EntityFolderInterface.php` (gains `getFilesForEntity`), `lib/Service/FileFolderServiceInterface.php` (the declaration moves up)
- `lib/Service/Integration/BuiltinProviders/FilesProvider.php` (C3 and `FileReadRestrictionInterface`)
- `lib/Service/Integration/BuiltinIntegrationServices.php` (the provider's `fileService` argument resolves the port id, bound to the same `FileService`)
- `lib/Service/ShareLinkService.php` (looks up `FileReadRestrictionInterface::class` and `FileAccountInterface::class`; same instances)
- tests

**B3a: Core folder and sharing callers (C1, C2).**
Files:
- new `lib/Kernel/Contracts/Core/FolderBindingCheckInterface.php`
- `lib/Kernel/Contracts/Core/ObjectFolderLookupInterface.php` (C2)
- `lib/Service/File/FolderManagementHandler.php` (implements C1)
- `lib/Service/FileService.php` (`getNodeById` delegate)
- `lib/Service/Object/SaveObject.php` (C1; same call, same position in the save path)
- `lib/Service/Rbac/ObjectShareReader.php`, `ObjectShareSurface.php`, `ObjectSharingService.php` (C2)
- `lib/Service/ServiceRegistrar.php` (binds C1 to the registered `FolderManagementHandler` instance)
- tests: `SaveObject` folder cases, the share reader and surface tests, the registration test keyed by the port

**B3b: the other Core and Compliance callers.** Waits for #10759 (`FileReadRestrictionInterface`) and
#10852 (`ObjectFolderAccessInterface`).
Files:
- `lib/Service/Object/RenderObject.php` (`FileReadRestrictionInterface`)
- `lib/Service/Object/Collaborators/ObjectStorageServices.php` (`ObjectFolderAccessInterface`)
- `lib/Service/Deletion/DestructionCompanions.php`, `DestructionScopeService.php` (`StoredFileReaderInterface`, `ObjectFolderLookupInterface`)
- `lib/BackgroundJob/SharerAccessSweepJob.php` (Compliance; `FileAccountInterface::getUser()`)
- tests, with a golden test for the sweep first

The sweep reads the owner of openregister's user folder today. The port answers the account user that
`FileOwnershipHandler` resolves. The golden test must show the same UID, including when the folder
cannot be resolved. If it does not, the sweep keeps its current call until B6 and is listed as left.

**B4: the bulk download endpoint to Files.** Stacked after #10853, which also changes `ObjectsController`.
Files:
- new `lib/Files/Controller/ObjectFilesArchiveController.php` (autowired, as the other Files controllers are)
- `lib/Controller/ObjectsController.php` (drop `downloadFiles`, `auditBulkDownload`)
- `appinfo/routes.php` (route name only)
- `scripts/component-map.csv`
- tests: the moved download tests, and the route table test (URL and verb unchanged)

**B5: the image types of the pipeline.** Stacked after #10844.
Files:
- `lib/Service/Anonymisation/PipelineSteps.php` (`IMAGE_TYPES`)
- `lib/Service/File/ImageRedactionService.php` (`STANDALONE_TYPES = PipelineSteps::IMAGE_TYPES`)
- `tests/Unit/Service/Anonymisation/PipelineStepReportTest.php`, the image redaction tests (values pinned)

**B6: the file store counts to Files (A1, A3, A4, A5), wired by Files.** It comes last, after B1 to B5.
Every rule above it must hold first. Otherwise a row turns a reference that points down into one against
the order.
- **PR 1, the entity mention cluster (A3, A4).** Files: `scripts/component-map.csv`; `lib/Db/MapperWiring.php` (drop the `EntityRelationMapper` factory); `lib/Db/FilesMapperRegistrar.php` and `FilesMapperBindings.php` (take it, #10788); and the mapper registration test.
- **PR 2, the file store (A1, A5).**
  - Files: `scripts/component-map.csv`.
  - `lib/Service/ServiceRegistrar.php` loses the `FolderManagementHandler`, `DocumentStore`, `ObjectFileReaderInterface` and C1 registrations. They move to `FilesRegistrar`.
  - `lib/Service/ContractBindings.php` loses the `FileServiceInterface`, `FileHandlerSupportInterface`, `FileFolderServiceInterface`, `FileReaderInterface`, `FileWriterInterface` and the #10813 port bindings. They move to Files' map.
  - New `lib/Files/FilesBindings.php` (the hidden map); `lib/Files/FilesRegistrar.php` (thin).
  - Tests: `FilesRegistrarTest`, `ComponentRegistrarWiringTest`, and every test keyed by a moved binding.
  - The aliases resolve to the same shared instances, so behaviour is identical.
  - The PR body lists `FolderAccessCheck`, `ExecutableContentDetector`, `DangerousFileExtensions` and `StoredEntity` as newly exposed on purpose (section 4).

Not in a Files package:
- `Search/Service/Reporting/PdfReportWriter` → `NcOfficeConverterInterface` is removed by #10843 (Search's `HtmlPdfConverterInterface`).
- `ChunkMapper` → `Db/Source` is removed by #10847.
- Files' own references down to Core (the ports) stay, as they should.

## 6. Fleet impact

Measured with `fleet_checkout.sh` and a class-name scan in the way of `fleet_surface.py`
on the development branches of the 19 fleet apps (2026-11-19). The fleet names these classes of this design:

| Class | Apps | This design |
|---|---|---|
| `Service\FileService` | buildiq, decidiq, dossiq, filinq, humaniq, integriq, learniq, opencatalogi, planninq, portaliq (54 files) | Counts to Files (A1). Name, namespace and methods unchanged. It gains `getNodeById` (C2). |
| `Db\EntityRelationMapper` | filinq, opencatalogi | Counts to Files (A3). Unchanged. |
| `Service\File\FileTarget`, `FileUpload` | 7 references | Stay Core (A2). Unchanged. |
| `Service\File\FileMetadataChange`, `FileTransfer` | opencatalogi, integriq | Count to Files (A1). Unchanged. |
| `Files\Service\TextExtractionService`, `Files\Db\ChunkMapper`, `FileMapper`, `NoteService`, `AccessLinkReader`, `FileRestrictionService`, `DocumentProcessingHandler`, extractors | decidiq, dossiq, filinq, learniq, opencatalogi | Untouched. |

No app names `FolderManagementHandler`, `ObjectShareSurface`, `SaveObject`, `PipelineSteps`,
`ImageRedactionService`, `FileSettingsHandler`, `StoredEntity`, `EntityRelationMapperInterface`,
`FileServiceInterface` or `ObjectFilesInterface`. shillinq mentions `ImportHandler` only in comments.
Nothing other apps use is renamed, moved or removed. The only public signatures that change only widen:
`ImportHandler::setFileService`, and the `FileSettingsHandler` constructor, which only the container
builds.

Candidates for promotion to `lib/Contract` (ADR-119 Decision 14) are named here, not done:
- An object-files contract that combines `StoredFileReaderInterface`, `StoredFileWriterInterface` and `ObjectFolderLookupInterface`. It covers what the 54 fleet files call on `FileService`: `getFiles`, `addFile`, `createFolder`, `getObjectFolder`, `getFileById`, `getFile`, `formatFile(s)`, `saveFile`. `createFolder` and `saveFile` are not in a port yet. `lib/Contract/ObjectFileReaderInterface` already exists for part of the reads.
- F1 `EntityMentionDecisionsInterface`, for filinq's and opencatalogi's use of `EntityRelationMapper`.

No event other apps read changes in this design.

## Notes for the other designs

- **D02 (Core):** C1 to C4 are Core ports, costing 6 new lines and 3 on existing ports. The Core ports from the open Files PRs (sixteen, section 2) are Core's budget. The upload safety verdict (section 4) is D02's call.
- **D05 (Compliance):** A4 takes `TextDisclosureRecorder` out of Compliance. B5 gives `PipelineSteps` the image MIME list. `SharerAccessSweepJob` reads the account through a Core port (B3b).
- **D07 to D10 (Integrations, Processes, Configuration, Platform):** their callers into Files are B2a to B2c and A5. Each uses a Core port where one answers, and otherwise a port in section 3b.
