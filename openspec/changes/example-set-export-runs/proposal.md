# Proposal: example-set-export-runs

## Why

dossiq's search page (DqZoekresultaten) has a "Your exports" card that reads OpenRegister's export runs for the reader, each with a downloadable file. The Zuiddrecht boards draw Pieter's two exports, but an example set could not make any: an export run is a row naming a real file the register produced, and no fragment part wrote either.

## What changes

A fragment MAY declare `exportRuns`: per row the set's account, a plain file name, the format, the file's content (text, or `contentBase64` for a binary file such as a spreadsheet, at most 64 kB), and optionally row count, register and schema names, the moment it was made and how often it was downloaded. Install writes the file into the account's `Exports` folder and records the export run through `ExportRunRecorder` (source `example-set`), dated at the declared moment with the usual retention from there. The proof names a run or file that is gone; remove deletes both; `--refresh` writes them again so the dates follow the install day.
