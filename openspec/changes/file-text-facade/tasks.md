# Tasks: file-text-facade

## 1. Facade

- [x] 1.1 `FileTextFacade::isAvailable()` and `extractText(fileId, userId)`: resolve the file in the user's folder, read it by mime type through `TextExtractionReaders`, sanitise, write nothing. Verify: `tests/Unit/Files/Service/FileTextFacadeTest.php` for a readable PDF, a plain-text file, a file outside the user's folder, a folder node, an unsupported type, a reader that throws, and a mail file read without the header store.

## 2. Contract

- [ ] 2.1 Record the facade as a public contract in `openspec/specs/text-extraction/spec.md` at archive time. Verify: the spec names the class and both signatures.
