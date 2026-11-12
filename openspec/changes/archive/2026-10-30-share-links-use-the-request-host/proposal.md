---
kind: code
---

# Proposal: share-links-use-the-request-host

## Summary

A public document link points at the host the visitor used. Today every
download on the Zuiddrecht portal points at `http://nextcloud:8080/index.php/s/<token>/download`,
a Docker service name no browser can resolve, while the same file answers on
`http://localhost:8080`. The cause is a substitution in `FileService` and
`FileSharingHandler`: the base URL had `localhost` replaced by
`trusted_domains[1]`. On an instance that answers on several hosts
(cloud.zuiddrecht.nl, zuiddrecht.nl) no single trusted domain is right.

## Why

The substitution came in with commit 312b59d68 (December 2024, "Use trusted
domains when running cronjobs"). From cron or occ there is no request, the
request host falls back to `localhost`, and links built there pointed at
localhost. Picking the second trusted domain was a guess at the public host.
It fixed one instance and broke every other: the list says which hosts are
allowed, not which one a visitor reaches, and its order means nothing. It also
fired during web requests, so a visitor on localhost got the service name.

Nextcloud already has the right answer for both cases. During a request
`IURLGenerator::getAbsoluteURL()` uses the request host and honours
`overwritehost`, `overwriteprotocol` and trusted proxies. Without one the
administrator declares the address in `overwrite.cli.url`.

## What changes

- New `ShareUrlBuilder` builds every absolute file address: the public share
  link, the authenticated preview and the authenticated download.
- During a request it uses `getAbsoluteURL()`; without one (cli, phpdbg) it
  uses `overwrite.cli.url`, falling back to the generator's base when that is
  empty. It never reads `trusted_domains`.
- `FileService::getShareLink`, `FileSharingHandler::getShareLink` and the three
  URL helpers in `FileMapper` use it. The two `getCurrentDomain()` methods go.

## Links already stored

File properties store file ids, and `FileMapper` builds `accessUrl` and
`downloadUrl` from the share token at read time, so listed and rendered files
pick up the fix with no migration. Two places keep an absolute link:

- the object `image` column, set from a file's `downloadUrl` on save
  (`SaveObject`) and reset on render when the schema configures
  `objectImageField` (`RenderObject`); a re-save refreshes it;
- opencatalogi's legacy attachment upload (`FileService::addFileInfoToData`)
  copies the link into attachment data, but that path has no caller on
  `development`.

Reading the token and building the link when serving stays the rule; this
change keeps it and adds no new stored link.
