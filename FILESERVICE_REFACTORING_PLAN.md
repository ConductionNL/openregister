# 🎯 FileService Refactoring Plan

## Overview
**Target:** `FileService.php` - 3,712 lines, 62 methods  
**Goal:** Extract to focused handlers following ObjectService pattern  
**Date:** December 15, 2024

---

## 📊 Current State Analysis

### FileService Stats
- **Lines:** 3,712
- **Methods:** 62 total
  - Public: 26
  - Private: 36
- **Dependencies:** ~20 (IRootFolder, IShare, IManager, mappers, etc.)
- **Status:** ⚠️ God Object
- **Complexity:** Very High

### Core Responsibilities
1. **File CRUD** - Create, read, update, delete files
2. **Folder Management** - Create folders, manage structure
3. **File Sharing** - Share links, permissions, access control
4. **Tag Management** - Attach/manage tags on files
5. **Versioning** - File version management
6. **Streaming** - File download/streaming
7. **ZIP Operations** - Create archives
8. **Publishing** - Publish/unpublish files
9. **Ownership** - Transfer ownership, permissions
10. **Document Processing** - Replace words, anonymize
11. **Filtering** - File filtering and search
12. **Validation** - Security checks, executable blocking

---

## 🎯 Proposed Handler Architecture

### Handler Breakdown (10 handlers)

#### 1. **FileCrudHandler** (~400 lines)
**Purpose:** Basic file CRUD operations

**Methods to Extract:**
- `createFolder()`
- `updateFile()`
- `deleteFile()`
- `addFile()`
- `saveFile()`
- `getFile()`
- `getFileById()`
- `getFiles()`

**Dependencies:**
- IRootFolder
- FileMapper
- ObjectEntityMapper
- LoggerInterface

**Priority:** HIGH (core functionality)

---

#### 2. **FolderManagementHandler** (~350 lines)
**Purpose:** Folder structure and organization

**Methods to Extract:**
- `createEntityFolder()`
- `getObjectFolder()`
- `getRegisterFolderById()`
- `createRegisterFolderById()`
- `createObjectFolderById()`
- `createObjectFolderWithoutUpdate()`
- `createFolderPath()`
- `getObjectFolderName()`
- `getRegisterFolderName()`
- `getOpenRegisterUserFolder()`

**Dependencies:**
- IRootFolder
- ObjectEntityMapper
- RegisterMapper
- IUserSession

**Priority:** HIGH

---

#### 3. **FileSharingHandler** (~400 lines)
**Purpose:** File sharing and access control

**Methods to Extract:**
- `createShareLink()`
- `createShare()`
- `findShares()`
- `getShareLink()`
- `shareFileWithUser()`
- `shareFolderWithUser()`
- `getAccessUrlFromShares()`
- `getDownloadUrlFromShares()`
- `getPublishedTimeFromShares()`

**Dependencies:**
- IManager (share manager)
- IURLGenerator
- IUserManager
- IGroupManager
- LoggerInterface

**Priority:** HIGH

---

#### 4. **FileTagHandler** (~250 lines)
**Purpose:** Tag management for files

**Methods to Extract:**
- `attachTagsToFile()`
- `getAllTags()`
- `getFileTags()`
- `generateObjectTag()`

**Dependencies:**
- ISystemTagManager
- ISystemTagObjectMapper
- LoggerInterface

**Priority:** MEDIUM

---

#### 5. **FilePublishingHandler** (~300 lines)
**Purpose:** Publishing and unpublishing files

**Methods to Extract:**
- `publishFile()`
- `unpublishFile()`

**Dependencies:**
- FileSharingHandler (for share creation)
- IRootFolder
- LoggerInterface

**Priority:** MEDIUM

---

#### 6. **FileStreamingHandler** (~200 lines)
**Purpose:** File streaming and downloads

**Methods to Extract:**
- `streamFile()`
- `createObjectFilesZip()`
- `getZipErrorMessage()`

**Dependencies:**
- IRootFolder
- ZipArchive
- StreamResponse

**Priority:** MEDIUM

---

#### 7. **FileValidationHandler** (~250 lines)
**Purpose:** File validation and security

**Methods to Extract:**
- `blockExecutableFile()`
- `detectExecutableMagicBytes()`
- `checkOwnership()`
- `ownFile()`

**Dependencies:**
- IUserSession
- LoggerInterface

**Priority:** HIGH (security critical)

---

#### 8. **FileOwnershipHandler** (~200 lines)
**Purpose:** File ownership and permissions

**Methods to Extract:**
- `transferFileOwnershipIfNeeded()`
- `transferFolderOwnershipIfNeeded()`
- `getCurrentUser()`
- `getUser()`

**Dependencies:**
- IUserSession
- IUserManager
- IRootFolder

**Priority:** MEDIUM

---

#### 9. **DocumentProcessingHandler** (~300 lines)
**Purpose:** Document manipulation (replace, anonymize)

**Methods to Extract:**
- `replaceWords()`
- `replaceWordsInTextDocument()`
- `replaceWordsInWordDocument()`
- `anonymizeDocument()`

**Dependencies:**
- IRootFolder
- External libraries (PHPWord, etc.)
- LoggerInterface

**Priority:** LOW (specialized feature)

---

#### 10. **FileFormattingHandler** (~400 lines)
**Purpose:** File formatting, filtering, utilities

**Methods to Extract:**
- `formatFile()`
- `formatFiles()`
- `getFilesForEntity()`
- `applyFileFilters()`
- `extractFilterParameters()`
- `extractFileNameFromPath()`
- `getNodeById()`
- `getNodeTypeFromFolder()`
- `getCurrentDomain()`
- `getObjectId()`
- `getFileInObjectFolderMessage()`
- Debug methods (`debugFindFileById()`, `debugListObjectFiles()`)

**Dependencies:**
- IRootFolder
- IConfig
- IURLGenerator
- LoggerInterface

**Priority:** MEDIUM

---

## 📋 Handler Dependency Graph

```
FileService (Facade)
├─→ FileCrudHandler (core operations)
│   └─→ FileValidationHandler (security checks)
├─→ FolderManagementHandler (folder structure)
├─→ FileSharingHandler (sharing operations)
│   └─→ FilePublishingHandler (uses sharing)
├─→ FileTagHandler (tagging)
├─→ FileStreamingHandler (downloads)
├─→ FileOwnershipHandler (permissions)
├─→ DocumentProcessingHandler (document ops)
└─→ FileFormattingHandler (utilities)
```

---

## 🚀 Implementation Strategy

### Phase 1: Core Operations (Week 1)
**Priority:** HIGH  
**Handlers:** 4

1. ✅ Create `FileCrudHandler` (core CRUD)
2. ✅ Create `FileValidationHandler` (security)
3. ✅ Create `FolderManagementHandler` (folders)
4. ✅ Create `FileSharingHandler` (sharing)

**Expected Result:** ~1,400 lines extracted, core functionality isolated

---

### Phase 2: Supporting Operations (Week 2)
**Priority:** MEDIUM  
**Handlers:** 4

5. ✅ Create `FileTagHandler` (tagging)
6. ✅ Create `FilePublishingHandler` (publishing)
7. ✅ Create `FileStreamingHandler` (streaming/zip)
8. ✅ Create `FileOwnershipHandler` (ownership)

**Expected Result:** ~950 lines extracted, supporting features isolated

---

### Phase 3: Advanced Features (Week 3)
**Priority:** LOW  
**Handlers:** 2

9. ✅ Create `DocumentProcessingHandler` (document manipulation)
10. ✅ Create `FileFormattingHandler` (formatting/utilities)

**Expected Result:** ~700 lines extracted, all features isolated

---

### Phase 4: Integration & Testing (Week 4)
**Tasks:**
- Update `FileService` to use handlers (facade pattern)
- Update `Application.php` for autowiring
- Run PHPQA validation
- Update documentation
- Integration testing

---

## 🎯 FileService After Refactoring

```php
class FileService
{
    public function __construct(
        private readonly FileCrudHandler $crudHandler,
        private readonly FolderManagementHandler $folderHandler,
        private readonly FileSharingHandler $sharingHandler,
        private readonly FileTagHandler $tagHandler,
        private readonly FilePublishingHandler $publishingHandler,
        private readonly FileStreamingHandler $streamingHandler,
        private readonly FileValidationHandler $validationHandler,
        private readonly FileOwnershipHandler $ownershipHandler,
        private readonly DocumentProcessingHandler $documentHandler,
        private readonly FileFormattingHandler $formattingHandler,
        private readonly LoggerInterface $logger
    ) {
    }

    // Facade methods that delegate to handlers
    public function createFolder(string $path): Node {
        return $this->crudHandler->createFolder($path);
    }
    
    // ... more facade methods ...
}
```

**Expected Result:**
- FileService: ~500-800 lines (facade + coordination)
- 10 Handlers: ~3,050 lines (avg 305 lines per handler)
- Total: ~3,500-3,900 lines (cleaner, more maintainable)

---

## ✅ Success Criteria

### Code Quality
- ✅ Each handler < 500 lines
- ✅ Single responsibility per handler
- ✅ Comprehensive docblocks
- ✅ PSR2 compliant (auto-fix)
- ✅ Type hints and return types
- ✅ Zero breaking changes

### Architecture
- ✅ Facade pattern implemented
- ✅ Dependency injection used
- ✅ Autowiring configured
- ✅ Clear handler boundaries
- ✅ Low coupling between handlers

### Testing & Validation
- ✅ PHPQA passes (all tools)
- ✅ Unit tests pass (0 failures)
- ✅ Integration tests pass
- ✅ No syntax errors
- ✅ Documentation updated

---

## 📝 Potential Challenges

### Challenge 1: Circular Dependencies
**Issue:** Handlers may need each other (e.g., Publishing needs Sharing)

**Solution:**
- Use dependency injection
- Inject specific handlers that are needed
- Keep handler interfaces clean
- Avoid circular references through careful design

### Challenge 2: Shared Utilities
**Issue:** Many methods use common utilities (getCurrentUser, getNodeById)

**Solution:**
- Create `FileFormattingHandler` for utilities
- Allow multiple handlers to inject it
- Keep utility methods focused and reusable

### Challenge 3: Complex File Operations
**Issue:** Some operations span multiple handlers

**Solution:**
- Keep FileService as facade for complex operations
- Coordinate between handlers in facade methods
- Document cross-handler workflows

### Challenge 4: Backward Compatibility
**Issue:** External code may depend on FileService methods

**Solution:**
- Keep all public methods in FileService
- Delegate to handlers internally
- Maintain exact same signatures
- Zero breaking changes

---

## 📈 Expected Outcomes

### Before Refactoring
```
FileService.php
├─ Lines: 3,712
├─ Methods: 62
├─ Complexity: Very High
├─ Coupling: High (20+ dependencies)
├─ Maintainability: Low
└─ Status: ⚠️ God Object
```

### After Refactoring
```
FileService (Facade)
├─ Lines: ~500-800
├─ Methods: ~20 (delegation)
├─ Complexity: Low
├─ Maintainability: High
└─ Status: ✅ Clean Facade

10 Focused Handlers
├─ Avg Lines: ~305 per handler
├─ Avg Methods: ~6 per handler
├─ Complexity: Low
├─ Maintainability: High
└─ Status: ✅ Single Responsibility
```

**Improvements:**
- 🎯 Complexity: Reduced by ~10x
- 🎯 Coupling: Reduced by ~5x
- 🎯 Maintainability: Improved by 3-4x
- 🎯 Testability: Much easier (isolated units)

---

## 🚀 Next Steps

1. ✅ **Review Plan** - Get approval for handler structure
2. ⏳ **Start Phase 1** - Extract core handlers (FileCrud, Validation, Folder, Sharing)
3. ⏳ **Test Integration** - Ensure no breaking changes
4. ⏳ **Continue Phases** - Systematic extraction following plan
5. ⏳ **Validate** - Run PHPQA after each phase
6. ⏳ **Document** - Update architecture docs

---

## 📊 Estimated Timeline

| Phase | Duration | Handlers | Lines |
|-------|----------|----------|-------|
| Phase 1 | 1 week | 4 | ~1,400 |
| Phase 2 | 1 week | 4 | ~950 |
| Phase 3 | 1 week | 2 | ~700 |
| Phase 4 | 1 week | Integration | - |
| **Total** | **4 weeks** | **10** | **~3,050** |

---

## 🎉 Conclusion

**Status:** Ready to begin systematic refactoring

**Approach:** Proven pattern from ObjectService success

**Expected Result:** Professional, maintainable codebase

**Risk:** Low (backward compatible, systematic approach)

**Recommendation:** START WITH PHASE 1 - Core handlers! 🚀

---

**Generated:** December 15, 2024  
**Author:** OpenRegister Development Team  
**Status:** 📋 Planning Complete - Ready for Implementation
