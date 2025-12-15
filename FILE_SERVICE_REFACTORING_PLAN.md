# FileService Refactoring Plan - Single Responsibility Handlers

## 🎯 Goal
Replace FileCrudHandler (monolithic) with focused single-responsibility handlers, then delegate FileService to them.

## 📋 Current State

### FileCrudHandler (322 lines)
Currently a **placeholder** with TODOs. Contains:
- `createFolder()` - should be in FolderManagementHandler ✅ (already exists)
- `addFile()` - should be in **CreateFileHandler** (NEW)
- `updateFile()` - should be in **UpdateFileHandler** (NEW)
- `deleteFile()` - should be in **DeleteFileHandler** (NEW)
- `getFile()` - should be in **ReadFileHandler** (NEW)
- `getFileById()` - should be in **ReadFileHandler** (NEW)
- `getFiles()` - should be in **ReadFileHandler** (NEW)
- `saveFile()` - should be in **CreateFileHandler** (NEW)

## 🏗️ New Handler Architecture

### 1. CreateFileHandler
**Responsibility**: Create and add new files
- `addFile()` - Add file with content
- `saveFile()` - Upsert operation
- Handle tags, sharing, ownership during creation

### 2. ReadFileHandler (or GetFileHandler)
**Responsibility**: Retrieve files
- `getFile()` - Get file by name/path
- `getFileById()` - Get file by ID
- `getFiles()` - Get all files for object
- `findFile()` - Search for files

### 3. UpdateFileHandler
**Responsibility**: Update existing files
- `updateFile()` - Update file content
- `updateFileMetadata()` - Update file metadata
- `updateFileTags()` - Update tags
- Handle ownership transfer if needed

### 4. DeleteFileHandler
**Responsibility**: Delete files
- `deleteFile()` - Delete single file
- `deleteFiles()` - Delete multiple files
- Handle cleanup (shares, tags, etc.)

### 5. FolderManagementHandler
**Already exists** - handles folder operations ✅

### 6. FileSharingHandler
**Already exists** - handles sharing ✅

### 7. FileOwnershipHandler
**Already exists** - handles ownership ✅

### 8. FileValidationHandler
**Already exists** - handles validation ✅

## 📊 FileService Methods to Delegate

### To CreateFileHandler:
- `addFile()` (72 lines) → CreateFileHandler::addFile()
- `saveFile()` (42 lines) → CreateFileHandler::saveFile()

### To ReadFileHandler:
- `getFile()` (?) → ReadFileHandler::getFile()
- `getFiles()` (?) → ReadFileHandler::getFiles()
- `getAllFiles()` (?) → ReadFileHandler::getAllFiles()

### To UpdateFileHandler:
- `updateFile()` (175 lines!) → UpdateFileHandler::updateFile()

### To DeleteFileHandler:
- `deleteFile()` (?) → DeleteFileHandler::deleteFile()

### Already delegating to FolderManagementHandler:
- `createObjectFolderById()` (92 lines) - needs delegation
- `createRegisterFolderById()` (54 lines) - needs delegation
- `createFolderPath()` (51 lines) - needs delegation
- `createFolder()` (49 lines) - needs delegation

## 🔄 Refactoring Steps

### Phase 1: Create New Handlers (1-4 hours)
1. ✅ Create CreateFileHandler.php
2. ✅ Create ReadFileHandler.php
3. ✅ Create UpdateFileHandler.php
4. ✅ Create DeleteFileHandler.php

### Phase 2: Extract Methods (2-3 hours)
5. ✅ Move addFile() logic from FileService to CreateFileHandler
6. ✅ Move updateFile() logic from FileService to UpdateFileHandler
7. ✅ Move deleteFile() logic from FileService to DeleteFileHandler
8. ✅ Move getFile/getFiles() logic from FileService to ReadFileHandler

### Phase 3: Update FileService (1 hour)
9. ✅ Inject new handlers into FileService
10. ✅ Replace method bodies with delegations
11. ✅ Update folder method delegations

### Phase 4: Clean Up (30 min)
12. ✅ Remove FileCrudHandler.php
13. ✅ Update DI container (Application.php)
14. ✅ Run linters

## 📈 Expected Results

### Size Reduction:
- FileService: 1,583 → ~880 lines (44% reduction)
- FileCrudHandler: REMOVED (322 lines)
- New handlers: ~600 lines total (spread across 4 files)

### Architecture Benefits:
- ✅ Single Responsibility - each handler has ONE job
- ✅ Better testability - test each operation independently
- ✅ Easier maintenance - smaller, focused files
- ✅ Clear separation - no confusion about where code lives

## 🎯 Success Criteria

- ✅ FileService < 1,000 lines
- ✅ All file operations delegated to handlers
- ✅ Zero linting errors
- ✅ No breaking changes to public API
- ✅ All tests pass (if applicable)

---

**Ready to implement!** 🚀

