# Proposal: file-download-audit-names-the-file

## Why

A case history can show who looked at which document. Board DqSociaalDomein reads "Inzage medisch verslag · Fatma Kaya". OpenRegister writes a `file.downloaded` audit row when a file is opened or downloaded, but the row held only the file id, so no history could name the document.

## What changes

The download audit row's data carries `fileName` beside `fileId`: `changed.data.fileName`. Both download routes pass the file's name.

## Scope

`lib/Controller/FilesController.php`.
