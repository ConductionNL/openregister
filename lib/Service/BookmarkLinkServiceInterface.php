<?php

/**
 * BookmarkLinkServiceInterface: the Bookmarks link operations of BookmarkLinkService that controllers use.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use Exception;
use OCA\OpenRegister\Db\BookmarkLink;

/**
 * The methods of BookmarkLinkService that callers outside the Service component use.
 */
interface BookmarkLinkServiceInterface {

	/**
	 * Whether NC Bookmarks is installed + enabled for the current user.
	 *
	 * @return bool
	 */
	public function isBookmarksAvailable(): bool;

	/**
	 * Link an existing NC Bookmarks bookmark to an OR object.
	 *
	 * Idempotent: a duplicate link raises a 409 Exception (callers should
	 * surface as HTTP 409 to the UI).
	 *
	 * @param LinkTarget $target The object to link to.
	 * @param int $bookmarkId NC Bookmarks bookmark id.
	 *
	 * @return BookmarkLink The persisted link row.
	 *
	 * @throws Exception On missing user, missing bookmark (404), duplicate (409).
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw2-svc-flat-2/tasks.md#task-2
	 */
	public function linkBookmark(LinkTarget $target, int $bookmarkId): BookmarkLink;

	/**
	 * Unlink a bookmark from an object.
	 *
	 * @param string $objectUuid Parent OR object uuid.
	 * @param int $bookmarkId NC Bookmarks bookmark id.
	 *
	 * @return void
	 *
	 * @throws Exception When no matching link is found (404).
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw2-svc-flat-2/tasks.md#task-2
	 */
	public function unlinkBookmark(string $objectUuid, int $bookmarkId): void;

	/**
	 * Return the linked bookmarks for an object, refreshing each cached
	 * row from NC Bookmarks when the link row is older than 24h.
	 *
	 * When Bookmarks is unavailable the cached link-row values are
	 * returned unchanged.
	 *
	 * @param string $objectUuid Parent OR object uuid.
	 *
	 * @return array<int,array<string,mixed>>
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw2-svc-flat-2/tasks.md#task-2
	 */
	public function getLinkedBookmarks(string $objectUuid): array;

	/**
	 * Return bookmarks visible to the current user (picker source).
	 *
	 * Optional substring search against title/url and optional tag
	 * filter. Returns an empty list when Bookmarks is unavailable.
	 *
	 * @param string|null $search Optional title/url substring filter.
	 * @param array<int,string>|null $tags Optional tag filter.
	 *
	 * @return array<int,array{id:int,title:string,url:string,description:string,tags:array<int,string>,added:?int}>
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw2-svc-flat-2/tasks.md#task-2
	 */
	public function getAvailableBookmarks(?string $search = null, ?array $tags = null): array;

	/**
	 * Create a new NC Bookmarks bookmark and link it to an OR object in
	 * one operation.
	 *
	 * The new bookmark is owned by the current user. Tags supplied here
	 * are applied to the bookmark via NC Bookmarks' TagMapper.
	 *
	 * @param LinkTarget    $target The object to link to.
	 * @param BookmarkDraft $draft  Title and URL (required), description and tags.
	 *
	 * @return BookmarkLink The persisted link row.
	 *
	 * @throws Exception On missing user, Bookmarks unavailable, or create failure.
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw2-svc-flat-2/tasks.md#task-2
	 */
	public function createAndLinkBookmark(LinkTarget $target, BookmarkDraft $draft): BookmarkLink;
}//end interface
