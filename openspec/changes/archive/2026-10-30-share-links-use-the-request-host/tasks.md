# Tasks: share-links-use-the-request-host

## 1. Builder

- [x] 1.1 `lib/Service/File/ShareUrlBuilder.php`: `shareLink(token)` and `absolute(path)`; request host during a request, `overwrite.cli.url` without one, never `trusted_domains`. Verify: `tests/Unit/Service/File/ShareUrlBuilderTest.php`.

## 2. Callers

- [x] 2.1 `FileService::getShareLink` and `FileSharingHandler::getShareLink` use the builder; both `getCurrentDomain()` methods removed. Verify: the handler scenarios in `ShareUrlBuilderTest`.
- [x] 2.2 `FileMapper::generateShareUrl`, `generateAuthenticatedAccessUrl` and `generateAuthenticatedDownloadUrl` use the builder. Verify: `testFileMapperLinksFollowTheSameRule`.

## 3. Proof

- [x] 3.1 The scenarios run against the old code fail: a localhost request gets `http://nextcloud:8080`, cron gets `http://nextcloud`, and the host moves with the order of `trusted_domains`.
