<?php

/**
 * CollectiveLinkServiceInterface: the Collectives link operations of CollectiveLinkService that controllers use.
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
use OCA\OpenRegister\Db\CollectiveLink;

/**
 * The methods of CollectiveLinkService that callers outside the Service component use.
 */
interface CollectiveLinkServiceInterface {

	/**
	 * Whether NC Collectives is installed + enabled for the current user.
	 *
	 * @return bool
	 */
	public function isCollectivesAvailable(): bool;

	/**
	 * Link an existing NC Collectives page to an OR object.
	 *
	 * Idempotent: a duplicate link raises a 409 Exception. Page metadata
	 * (title/slug/emoji/last_modified/url) is cached at link time.
	 *
	 * @param LinkTarget $target The object to link to.
	 * @param int        $pageId NC Collectives page id.
	 *
	 * @return CollectiveLink The persisted link row.
	 *
	 * @throws Exception On missing user (401), missing page (404),
	 *                   duplicate (409), Collectives unavailable (503).
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw2-svc-flat-2/tasks.md#task-2
	 */
	public function linkPage(LinkTarget $target, int $pageId): CollectiveLink;

	/**
	 * Create a new NC Collectives page and link it to an OR object.
	 *
	 * @param LinkTarget $target       The object to link to.
	 * @param int        $collectiveId Target collective id.
	 * @param string     $title        New page title.
	 *
	 * @return CollectiveLink The persisted link row.
	 *
	 * @throws Exception On missing user (401), empty title (400),
	 *                   missing collective (400), Collectives unavailable (503).
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw2-svc-flat-2/tasks.md#task-2
	 */
	public function createAndLinkPage(LinkTarget $target, int $collectiveId, string $title): CollectiveLink;

	/**
	 * Unlink a page from an object.
	 *
	 * Does NOT delete the page itself — it stays in NC Collectives for the
	 * user and for any other linked objects.
	 *
	 * @param string $objectUuid Parent OR object uuid.
	 * @param int $pageId NC Collectives page id.
	 *
	 * @return void
	 *
	 * @throws Exception On missing user (401) or no matching link (404).
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw2-svc-flat-2/tasks.md#task-2
	 */
	public function unlinkPage(string $objectUuid, int $pageId): void;

	/**
	 * Return the linked pages for an object, refreshing the cached
	 * title/slug/emoji/last_modified/url columns when a row is older
	 * than 24h.
	 *
	 * @param string $objectUuid Parent OR object uuid.
	 *
	 * @return array<int,array<string,mixed>>
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw2-svc-flat-2/tasks.md#task-2
	 */
	public function getLinkedPages(string $objectUuid): array;

	/**
	 * Return the current user's collectives (create cascade source).
	 *
	 * Returns an empty array when Collectives is unavailable.
	 *
	 * @return array<int,array<string,mixed>>
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw2-svc-flat-2/tasks.md#task-2
	 */
	public function getAvailableCollectives(): array;

	/**
	 * Return the current user's NC Collectives pages (picker source).
	 *
	 * Walks every collective the user can see and lists its pages,
	 * optionally filtered by a name substring. Returns an empty array
	 * when Collectives is unavailable.
	 *
	 * @param string|null $search Optional title-substring filter.
	 *
	 * @return array<int,array<string,mixed>>
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw2-svc-flat-2/tasks.md#task-2
	 */
	public function getAvailablePages(?string $search = null): array;
}//end interface
