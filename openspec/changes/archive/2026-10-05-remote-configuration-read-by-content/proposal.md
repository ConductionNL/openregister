# Proposal: a remote configuration is read by its content, not its Content-Type

## Why

The live pass of 5 Oct (defect O1) could not preview, import or auto-update any configuration whose source is a raw.githubusercontent.com URL or a Nextcloud public link: the preview answered "Failed to parse response body as JSON or YAML" with `Content-Type: text/plain; charset=utf-8`. Both hosts serve every .json and .yaml file as text/plain. `FetchHandler::decode()` only tried JSON when the Content-Type said json or was empty, and YAML only when it said yaml (or json), so text/plain fell through to null.

## What changes

- `FetchHandler::decode()` tries JSON, then YAML, whatever the Content-Type; a yaml/yml Content-Type only puts YAML first. A body that parses to neither array is still refused with 400.

## Impact

- `lib/Service/Configuration/FetchHandler.php` (the fetch behind preview, import and auto-update of github/gitlab/url configurations). `ImportHandler` and `UploadHandler` already decode text/plain this way.
