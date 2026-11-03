<?php

/**
 * BookmarksProvider — exposes NC Bookmarks linked to an OpenRegister
 * object via the IntegrationProvider contract.
 *
 * Tier-2: backed by the `openregister_bookmark_links` table via
 * {@see BookmarkLinkMapper}. Replaces the original tag-marker convention
 * (`or:{objectUuid}` tag on the bookmark in NC Bookmarks) with a proper
 * persistence layer so links survive Bookmarks tag edits and don't
 * pollute Bookmarks' UX.
 *
 * Each link row caches title/url/description/tags/added so the sidebar
 * tab can render without a per-bookmark roundtrip to NC Bookmarks; the
 * wrapping `BookmarkLinkService` refreshes the cache lazily. Returns an
 * empty list when Bookmarks is uninstalled.
 *
 * `link-table` storage strategy — the link lives in OR's own table.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Integration\Providers
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/integration-bookmarks/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Integration\Providers;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- self-documenting IntegrationProvider metadata getters mirror the contract in the interface.

use DateTime;
use OCA\OpenRegister\Db\BookmarkLinkMapper;
use OCA\OpenRegister\Service\Integration\AbstractIntegrationProvider;
use OCP\App\IAppManager;
use OCP\IL10N;

class BookmarksProvider extends AbstractIntegrationProvider {

	private const REQUIRED_APP = 'bookmarks';

	public function __construct(
		private BookmarkLinkMapper $bookmarkLinkMapper,
		private IAppManager $appManager,
		private IL10N $l10n,
	) {
	}//end __construct()

	public function getId(): string {
		return 'bookmarks';
	}//end getId()

	public function getLabel(): string {
		return $this->l10n->t('Bookmarks');
	}//end getLabel()

	public function getIcon(): string {
		return 'Bookmark';
	}//end getIcon()

	public function getGroup(): ?string {
		return 'docs';
	}//end getGroup()

	public function getRequiredApp(): ?string {
		return self::REQUIRED_APP;
	}//end getRequiredApp()

	public function getStorageStrategy(): string {
		return 'link-table';
	}//end getStorageStrategy()

	public function isEnabled(): bool {
		return $this->appManager->isInstalled(self::REQUIRED_APP);
	}//end isEnabled()

	/**
	 * List bookmarks linked to an OR object.
	 *
	 * Reads link rows from `openregister_bookmark_links` and normalises
	 * each into the registry leaf row shape consumed by CnBookmarksTab +
	 * CnBookmarksCard. Returns an empty array when Bookmarks is
	 * uninstalled.
	 *
	 * Payload contract per row:
	 *   id          : string — NC Bookmarks numeric id, cast to string
	 *   bookmarkId  : int    — NC Bookmarks numeric id
	 *   title       : string — bookmark title (falls back to url)
	 *   description : string — user-entered description
	 *   url         : string — canonical URL
	 *   tags        : string[] — Bookmarks-side tags (`or:*` stripped)
	 *   added       : int|null — unix timestamp the bookmark was saved
	 *   linkId      : int    — OR link-row id
	 *
	 * @param string $register Register slug or numeric id (unused).
	 * @param string $schema Schema slug or numeric id (unused).
	 * @param string $objectId Object uuid.
	 * @param array<string,mixed> $filters Optional filters (unused).
	 *
	 * @return array<int,array<string,mixed>>
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) register/schema/filters
	 *     are part of the IntegrationProvider::list() contract.
	 *
	 * @spec openspec/specs/integration-bookmarks/spec.md
	 */
	public function list(string $register, string $schema, string $objectId, array $filters = []): array {
		if ($this->isEnabled() === false) {
			return [];
		}

		$links = $this->bookmarkLinkMapper->findByObjectUuid($objectId);
		if ($links === []) {
			return [];
		}

		$out = [];
		foreach ($links as $link) {
			$bookmarkId = (int)$link->getBookmarkId();
			$addedAt = $link->getAddedAt();

			$addedTimestamp = null;
			if ($addedAt instanceof DateTime) {
				$addedTimestamp = $addedAt->getTimestamp();
			}

			$out[] = [
				'id' => (string)$bookmarkId,
				'bookmarkId' => $bookmarkId,
				'title' => (string)($link->getTitle() ?? ($link->getUrl() ?? '')),
				'description' => (string)($link->getDescription() ?? ''),
				'url' => (string)($link->getUrl() ?? ''),
				'tags' => ($link->getTags() ?? []),
				'added' => $addedTimestamp,
				'linkId' => $link->getId(),
			];
		}

		return $out;
	}//end list()

	/**
	 * Provider health descriptor (enabled/disabled echo).
	 *
	 * @return array<string,mixed>
	 *
	 * @spec exclude Static enabled/disabled descriptor echoing IAppManager::isInstalled — no standalone health
	 *              behaviour; the health/OCS contract is owned by pluggable-integration-registry task-2.
	 */
	public function health(): array {
		$installed = $this->appManager->isInstalled(self::REQUIRED_APP);

		$status = 'unavailable';
		if ($installed === true) {
			$status = 'ok';
		}

		$message = 'NC Bookmarks app is not installed';
		if ($installed === true) {
			$message = null;
		}

		return [
			'status' => $status,
			'authStatus' => 'configured',
			'message' => $message,
		];
	}//end health()
}//end class
