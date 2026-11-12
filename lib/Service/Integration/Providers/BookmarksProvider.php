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

use OCA\OpenRegister\Db\BookmarkLink;
use OCA\OpenRegister\Db\BookmarkLinkMapperInterface;
use OCA\OpenRegister\Service\Integration\AbstractIntegrationProvider;
use OCP\App\IAppManager;
use OCP\IL10N;

class BookmarksProvider extends AbstractIntegrationProvider {

	use NcAppLinkRowsProvider;

	private const REQUIRED_APP = 'bookmarks';

	/**
	 * NC app manager.
	 *
	 * @var IAppManager
	 */
	private IAppManager $appManager;

	/**
	 * Localisation.
	 *
	 * @var IL10N
	 */
	private IL10N $l10n;

	/**
	 * Constructor.
	 *
	 * @param AppProviderDescription      $description        The app manager and localisation.
	 * @param BookmarkLinkMapperInterface $bookmarkLinkMapper Bookmark-link mapper (Tier-2 link table).
	 */
	public function __construct(
		AppProviderDescription $description,
		private BookmarkLinkMapperInterface $bookmarkLinkMapper,
	) {
		$this->appManager = $description->appManager;
		$this->l10n = $description->l10n;
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

	/**
	 * The link mapper this provider reads.
	 *
	 * @return BookmarkLinkMapperInterface
	 */
	protected function linkMapper(): BookmarkLinkMapperInterface {
		return $this->bookmarkLinkMapper;
	}//end linkMapper()

	/**
	 * One bookmark link in the leaf-row shape consumed by CnBookmarksTab and
	 * CnBookmarksCard.
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
	 * @param BookmarkLink $link Link row from the mapper.
	 *
	 * @return array<string,mixed>
	 *
	 * @spec openspec/specs/integration-bookmarks/spec.md
	 */
	protected function rowFromLink(BookmarkLink $link): array {
		$bookmarkId = (int)$link->getBookmarkId();
		return [
			'id' => (string)$bookmarkId,
			'bookmarkId' => $bookmarkId,
			'title' => (string)($link->getTitle() ?? ($link->getUrl() ?? '')),
			'description' => (string)($link->getDescription() ?? ''),
			'url' => (string)($link->getUrl() ?? ''),
			'tags' => ($link->getTags() ?? []),
			'added' => $link->getAddedAt()?->getTimestamp(),
			'linkId' => $link->getId(),
		];
	}//end rowFromLink()

	/**
	 * Provider health descriptor (enabled/disabled echo).
	 *
	 * @return array<string,mixed>
	 *
	 * @spec exclude Static enabled/disabled descriptor echoing IAppManager::isInstalled — no standalone health
	 *              behaviour; the health/OCS contract is owned by pluggable-integration-registry task-2.
	 */
	public function health(): array {
		return $this->installedHealth(available: $this->appManager->isInstalled(self::REQUIRED_APP) === true, missing: 'NC Bookmarks app is not installed');
	}//end health()
}//end class
