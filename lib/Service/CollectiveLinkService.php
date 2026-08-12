<?php

/**
 * CollectiveLinkService — Tier-2 collectives (NC Collectives / Knowledge)
 * integration service.
 *
 * Composes the {@see CollectiveLinkMapper} with NC Collectives'
 * `OCA\Collectives\Service\CollectiveService` + `PageService` to expose
 * the Tier-2 surface:
 *
 *   - linkPage(uuid, registerId, schemaId, pageId)
 *       — link an existing page
 *   - createAndLinkPage(uuid, registerId, schemaId, collectiveId, title)
 *       — create a new page in a collective and link it
 *   - unlinkPage(uuid, pageId)
 *       — remove a link (the page itself stays in NC Collectives)
 *   - getLinkedPages(uuid)
 *       — list linked pages, refreshing cached
 *       title/slug/emoji/last_modified/url when the row is older than 24h
 *   - getAvailablePages(?search)
 *       — picker source listing the current user's pages (collective→page)
 *   - getAvailableCollectives()
 *       — collectives for the create cascade
 *
 * NC Collectives exposes its persistence as the `CollectiveService`
 * (collective listing) and `PageService` (page listing + create + deep
 * link). Both are resolved lazily through the server container behind a
 * `class_exists` + `Throwable` guard so this service loads even when the
 * Collectives app is not installed (ADR-019 AD-23 graceful degradation):
 * when Collectives is missing or a call throws, the stored link row is
 * returned as-is so historical references survive.
 *
 * Pages are user-scoped: NC Collectives pages belong to a user (via the
 * collective's circle membership), so every mutating + picker call is
 * scoped to the active session's UID.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 * @link    https://OpenRegister.app
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use DateTime;
use Exception;
use OCA\OpenRegister\Db\CollectiveLink;
use OCA\OpenRegister\Db\CollectiveLinkMapper;
use OCP\App\IAppManager;
use OCP\IURLGenerator;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * CollectiveLinkService.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)   Composes mapper +
 *     NC Collectives CollectiveService + PageService (late-bound) + user
 *     session + app manager + url generator + container + logger. Each
 *     dependency is required for one of the Tier-2 flows (link, create,
 *     unlink, list, picker, cache refresh, graceful degradation).
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 */
class CollectiveLinkService {
	private const REQUIRED_APP = 'collectives';

	private const COLLECTIVE_SERVICE = 'OCA\\Collectives\\Service\\CollectiveService';

	private const PAGE_SERVICE = 'OCA\\Collectives\\Service\\PageService';

	private const STALE_AFTER = 86400;
	// 24 hours in seconds.

	/**
	 * Constructor.
	 *
	 * @param CollectiveLinkMapper $collectiveLinkMapper Persistence for link rows.
	 * @param ContainerInterface $container Container for late-bound Collectives classes.
	 * @param IAppManager $appManager NC app manager.
	 * @param IUserSession $userSession Active session.
	 * @param IURLGenerator $urlGenerator URL generator for deep links.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly CollectiveLinkMapper $collectiveLinkMapper,
		private readonly ContainerInterface $container,
		private readonly IAppManager $appManager,
		private readonly IUserSession $userSession,
		private readonly IURLGenerator $urlGenerator,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Whether NC Collectives is installed + enabled for the current user.
	 *
	 * @return bool
	 */
	public function isCollectivesAvailable(): bool {
		return $this->appManager->isEnabledForUser(self::REQUIRED_APP);
	}//end isCollectivesAvailable()

	/**
	 * Resolve a late-bound NC Collectives service lazily.
	 *
	 * Returns null when Collectives is absent or the class can't be
	 * resolved, so callers degrade gracefully (ADR-019 AD-23).
	 *
	 * @param string $class The fully-qualified Collectives service class.
	 *
	 * @return object|null The service instance or null.
	 */
	private function resolveService(string $class): ?object {
		if ($this->isCollectivesAvailable() === false || class_exists($class) === false) {
			return null;
		}

		try {
			return $this->container->get($class);
		} catch (Throwable $e) {
			$this->logger->debug('CollectiveLinkService: ' . $class . ' unavailable: ' . $e->getMessage());
			return null;
		}
	}//end resolveService()

	/**
	 * Resolve NC Collectives' CollectiveService.
	 *
	 * @return object|null
	 */
	private function getCollectiveService(): ?object {
		return $this->resolveService(class: self::COLLECTIVE_SERVICE);
	}//end getCollectiveService()

	/**
	 * Resolve NC Collectives' PageService.
	 *
	 * @return object|null
	 */
	private function getPageService(): ?object {
		return $this->resolveService(class: self::PAGE_SERVICE);
	}//end getPageService()

	/**
	 * Active session UID, or throw if no user is logged in.
	 *
	 * @return string The user id.
	 *
	 * @throws Exception When there is no active user.
	 */
	private function requireUid(): string {
		$user = $this->userSession->getUser();
		if ($user === null) {
			throw new Exception('No user logged in', 401);
		}

		return $user->getUID();
	}//end requireUid()

	/**
	 * Link an existing NC Collectives page to an OR object.
	 *
	 * Idempotent: a duplicate link raises a 409 Exception. Page metadata
	 * (title/slug/emoji/last_modified/url) is cached at link time.
	 *
	 * @param string $objectUuid Parent OR object uuid.
	 * @param int $registerId OR register id.
	 * @param int $schemaId OR schema id.
	 * @param int $pageId NC Collectives page id.
	 *
	 * @return CollectiveLink The persisted link row.
	 *
	 * @throws Exception On missing user (401), missing page (404),
	 *                   duplicate (409), Collectives unavailable (503).
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw2-svc-flat-2/tasks.md#task-2
	 */
	public function linkPage(string $objectUuid, int $registerId, int $schemaId, int $pageId): CollectiveLink {
		$uid = $this->requireUid();

		if ($this->isCollectivesAvailable() === false) {
			throw new Exception('NC Collectives is not available', 503);
		}

		$existing = $this->collectiveLinkMapper->findByObjectAndPage($objectUuid, $pageId);
		if ($existing !== null) {
			throw new Exception('Page already linked to this object', 409);
		}

		$info = $this->fetchPageInfo(pageId: $pageId, uid: $uid);
		if ($info === null) {
			throw new Exception('Collectives page not found', 404);
		}

		$link = $this->hydrateLink(
			objectUuid: $objectUuid,
			registerId: $registerId,
			schemaId: $schemaId,
			pageId: $pageId,
			info: $info,
			uid: $uid
		);

		return $this->collectiveLinkMapper->insert($link);
	}//end linkPage()

	/**
	 * Create a new NC Collectives page and link it to an OR object.
	 *
	 * @param string $objectUuid Parent OR object uuid.
	 * @param int $registerId OR register id.
	 * @param int $schemaId OR schema id.
	 * @param int $collectiveId Target collective id.
	 * @param string $title New page title.
	 *
	 * @return CollectiveLink The persisted link row.
	 *
	 * @throws Exception On missing user (401), empty title (400),
	 *                   missing collective (400), Collectives unavailable (503).
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw2-svc-flat-2/tasks.md#task-2
	 */
	public function createAndLinkPage(
		string $objectUuid,
		int $registerId,
		int $schemaId,
		int $collectiveId,
		string $title,
	): CollectiveLink {
		$uid = $this->requireUid();

		$title = trim($title);
		if ($title === '') {
			throw new Exception('Page title is required', 400);
		}

		if ($collectiveId === 0) {
			throw new Exception('Collective id is required', 400);
		}

		$pageService = $this->getPageService();
		if ($pageService === null) {
			throw new Exception('NC Collectives is not available', 503);
		}

		try {
			// PageService::create(collectiveId, parentId, title, templateId, userId).
			// parentId 0 places the page at the collective root.
			$pageInfo = $pageService->create($collectiveId, 0, $title, null, $uid);
			$pageId = (int)$pageInfo->getId();
		} catch (Throwable $e) {
			$this->logger->warning('CollectiveLinkService::createAndLinkPage failed: ' . $e->getMessage());
			throw new Exception('Failed to create Collectives page', 500);
		}

		$info = $this->fetchPageInfo(pageId: $pageId, uid: $uid);
		if ($info === null) {
			$info = [
				'title' => $title,
				'collectiveId' => $collectiveId,
				'slug' => null,
				'emoji' => null,
				'lastModified' => new DateTime(),
				'url' => $this->pageDeepLink(pageId: $pageId),
			];
		}

		$link = $this->hydrateLink(
			objectUuid: $objectUuid,
			registerId: $registerId,
			schemaId: $schemaId,
			pageId: $pageId,
			info: $info,
			uid: $uid
		);

		return $this->collectiveLinkMapper->insert($link);
	}//end createAndLinkPage()

	/**
	 * Build a CollectiveLink from normalised page info.
	 *
	 * @param string $objectUuid Parent OR object uuid.
	 * @param int $registerId OR register id.
	 * @param int $schemaId OR schema id.
	 * @param int $pageId NC Collectives page id.
	 * @param array<string,mixed> $info Normalised page info.
	 * @param string $uid The linking user id.
	 *
	 * @return CollectiveLink
	 */
	private function hydrateLink(
		string $objectUuid,
		int $registerId,
		int $schemaId,
		int $pageId,
		array $info,
		string $uid,
	): CollectiveLink {
		$link = new CollectiveLink();
		$link->setObjectUuid($objectUuid);
		$link->setRegisterId($registerId);
		$link->setSchemaId($schemaId);
		$link->setPageId($pageId);
		$link->setCollectiveId((int)($info['collectiveId'] ?? 0));
		$link->setCollectiveName((string)($info['collectiveName'] ?? ''));
		$link->setPageTitle((string)($info['title'] ?? ''));
		$link->setSlug($info['slug'] ?? null);
		$link->setEmoji($info['emoji'] ?? null);
		$link->setLastModified($info['lastModified'] ?? null);
		$link->setUrl($info['url'] ?? $this->pageDeepLink(pageId: $pageId));
		$link->setLinkedBy($uid);
		$link->setLinkedAt(new DateTime());

		return $link;
	}//end hydrateLink()

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
	public function unlinkPage(string $objectUuid, int $pageId): void {
		$this->requireUid();

		$deleted = $this->collectiveLinkMapper->deleteByObjectAndPage($objectUuid, $pageId);
		if ($deleted === 0) {
			throw new Exception('Collective link not found', 404);
		}
	}//end unlinkPage()

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
	public function getLinkedPages(string $objectUuid): array {
		$links = $this->collectiveLinkMapper->findByObjectUuid($objectUuid);
		$available = $this->isCollectivesAvailable();
		$uid = $this->userSession->getUser()?->getUID();

		$results = [];
		foreach ($links as $link) {
			if ($available === true && $uid !== null && $this->isStale(link: $link) === true) {
				$link = $this->refreshLink(link: $link, uid: $uid);
			}

			$results[] = $link->jsonSerialize();
		}

		return $results;
	}//end getLinkedPages()

	/**
	 * Return the current user's collectives (create cascade source).
	 *
	 * Returns an empty array when Collectives is unavailable.
	 *
	 * @return array<int,array<string,mixed>>
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw2-svc-flat-2/tasks.md#task-2
	 */
	public function getAvailableCollectives(): array {
		$service = $this->getCollectiveService();
		$uid = $this->userSession->getUser()?->getUID();
		if ($service === null || $uid === null) {
			return [];
		}

		try {
			$collectives = $service->getCollectives($uid);
		} catch (Throwable $e) {
			$this->logger->warning('CollectiveLinkService::getAvailableCollectives failed: ' . $e->getMessage());
			return [];
		}

		$out = [];
		foreach ($collectives as $collective) {
			try {
				$out[] = [
					'id' => (int)$collective->getId(),
					'name' => (string)$collective->getName(),
					'emoji' => $collective->getEmoji(),
				];
			} catch (Throwable $e) {
				continue;
			}
		}

		return $out;
	}//end getAvailableCollectives()

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
	public function getAvailablePages(?string $search = null): array {
		$collectiveService = $this->getCollectiveService();
		$pageService = $this->getPageService();
		$uid = $this->userSession->getUser()?->getUID();
		if ($collectiveService === null || $pageService === null || $uid === null) {
			return [];
		}

		try {
			$collectives = $collectiveService->getCollectives($uid);
		} catch (Throwable $e) {
			$this->logger->warning('CollectiveLinkService::getAvailablePages failed: ' . $e->getMessage());
			return [];
		}

		$needle = null;
		if ($search !== null && $search !== '') {
			$needle = mb_strtolower($search);
		}

		$out = [];
		foreach ($collectives as $collective) {
			$this->collectPages(
				pageService: $pageService,
				collective: $collective,
				uid: $uid,
				needle: $needle,
				out: $out
			);
		}

		return $out;
	}//end getAvailablePages()

	/**
	 * Append a collective's pages to the picker output.
	 *
	 * @param object $pageService The Collectives PageService.
	 * @param object $collective A Collective entity.
	 * @param string $uid The owning user id.
	 * @param string|null $needle Lower-cased title filter, or null.
	 * @param array<int,mixed> $out Output array (by reference).
	 *
	 * @return void
	 */
	private function collectPages(
		object $pageService,
		object $collective,
		string $uid,
		?string $needle,
		array &$out,
	): void {
		try {
			$collectiveId = (int)$collective->getId();
			$collectiveName = (string)$collective->getName();
			$pages = $pageService->findAll($collectiveId, $uid);
		} catch (Throwable $e) {
			return;
		}

		foreach ($pages as $page) {
			$row = $this->pickerRowFromPage(
				page: $page,
				collectiveId: $collectiveId,
				collectiveName: $collectiveName,
				needle: $needle
			);
			if ($row !== null) {
				$out[] = $row;
			}
		}
	}//end collectPages()

	/**
	 * Map a single NC Collectives page into a picker row, applying the
	 * optional title filter.
	 *
	 * @param object $page A Collectives PageInfo instance.
	 * @param int $collectiveId The owning collective id.
	 * @param string $collectiveName The owning collective name.
	 * @param string|null $needle Lower-cased title filter, or null.
	 *
	 * @return array<string,mixed>|null The picker row, or null when the
	 *                                  page is unreadable or filtered out.
	 */
	private function pickerRowFromPage(
		object $page,
		int $collectiveId,
		string $collectiveName,
		?string $needle,
	): ?array {
		try {
			$title = (string)$page->getTitle();
			$pageId = (int)$page->getId();
		} catch (Throwable $e) {
			return null;
		}

		if ($needle !== null && str_contains(mb_strtolower($title), $needle) === false) {
			return null;
		}

		$lastModified = null;
		try {
			$timestamp = (int)$page->getTimestamp();
			if ($timestamp > 0) {
				$lastModified = (new DateTime())->setTimestamp($timestamp)->format(DateTime::ATOM);
			}
		} catch (Throwable $e) {
			$lastModified = null;
		}

		$emoji = null;
		try {
			$emoji = $page->getEmoji();
		} catch (Throwable $e) {
			$emoji = null;
		}

		return [
			'pageId' => $pageId,
			'collectiveId' => $collectiveId,
			'collectiveName' => $collectiveName,
			'title' => $title,
			'emoji' => $emoji,
			'lastModified' => $lastModified,
			'url' => $this->pageDeepLink(pageId: $pageId),
		];
	}//end pickerRowFromPage()

	/**
	 * Whether a link row's cache is older than the stale window.
	 *
	 * @param CollectiveLink $link The link row.
	 *
	 * @return bool
	 */
	private function isStale(CollectiveLink $link): bool {
		$linkedAt = $link->getLinkedAt();
		if ($linkedAt === null) {
			return true;
		}

		return (time() - $linkedAt->getTimestamp()) > self::STALE_AFTER;
	}//end isStale()

	/**
	 * Refresh a link row's cached page metadata in place.
	 *
	 * Best-effort: when the page can't be resolved the link is left
	 * untouched (the page may have been deleted in NC Collectives).
	 *
	 * @param CollectiveLink $link The link row.
	 * @param string $uid The owning user id.
	 *
	 * @return CollectiveLink The (possibly updated) link row.
	 */
	private function refreshLink(CollectiveLink $link, string $uid): CollectiveLink {
		$info = $this->fetchPageInfo(pageId: (int)$link->getPageId(), uid: $uid);
		if ($info === null) {
			return $link;
		}

		if (($info['collectiveName'] ?? '') !== '') {
			$link->setCollectiveName((string)$info['collectiveName']);
		}

		$link->setPageTitle((string)($info['title'] ?? $link->getPageTitle()));
		$link->setSlug($info['slug'] ?? null);
		$link->setEmoji($info['emoji'] ?? null);
		$link->setLastModified($info['lastModified'] ?? null);
		$link->setUrl($info['url'] ?? $link->getUrl());
		$link->setLinkedAt(new DateTime());

		try {
			return $this->collectiveLinkMapper->update($link);
		} catch (Throwable $e) {
			$this->logger->debug('CollectiveLinkService::refreshLink update failed: ' . $e->getMessage());
			return $link;
		}
	}//end refreshLink()

	/**
	 * Fetch normalised page metadata from NC Collectives.
	 *
	 * Walks the user's collectives to find the one owning the page, then
	 * normalises the PageInfo into the shape used by hydrateLink /
	 * refreshLink.
	 *
	 * @param int $pageId The page id.
	 * @param string $uid The owning user id.
	 *
	 * @return array<string,mixed>|null
	 */
	private function fetchPageInfo(int $pageId, string $uid): ?array {
		$collectiveService = $this->getCollectiveService();
		$pageService = $this->getPageService();
		if ($collectiveService === null || $pageService === null) {
			return null;
		}

		try {
			$collectives = $collectiveService->getCollectives($uid);
		} catch (Throwable $e) {
			$this->logger->debug('CollectiveLinkService::fetchPageInfo getCollectives failed: ' . $e->getMessage());
			return null;
		}

		foreach ($collectives as $collective) {
			try {
				$collectiveId = (int)$collective->getId();
				$page = $pageService->find($collectiveId, $pageId, $uid);
			} catch (Throwable $e) {
				continue;
			}

			return $this->normalisePage(page: $page, collective: $collective);
		}

		return null;
	}//end fetchPageInfo()

	/**
	 * Normalise a Collectives PageInfo + Collective into the info shape.
	 *
	 * @param object $page A Collectives PageInfo instance.
	 * @param object $collective The owning Collective entity.
	 *
	 * @return array<string,mixed>
	 */
	private function normalisePage(object $page, object $collective): array {
		$lastModified = null;
		try {
			$timestamp = (int)$page->getTimestamp();
			if ($timestamp > 0) {
				$lastModified = (new DateTime())->setTimestamp($timestamp);
			}
		} catch (Throwable $e) {
			$lastModified = null;
		}

		$emoji = null;
		try {
			$emoji = $page->getEmoji();
		} catch (Throwable $e) {
			$emoji = null;
		}

		$slug = null;
		try {
			$slug = $page->getSlug();
		} catch (Throwable $e) {
			$slug = null;
		}

		return [
			'title' => (string)$page->getTitle(),
			'collectiveId' => (int)$collective->getId(),
			'collectiveName' => (string)$collective->getName(),
			'slug' => $slug,
			'emoji' => $emoji,
			'lastModified' => $lastModified,
			'url' => $this->pageDeepLink(pageId: (int)$page->getId()),
		];
	}//end normalisePage()

	/**
	 * Build the NC Collectives deep link for a page.
	 *
	 * Uses the stable `?fileId=` query form which Collectives resolves to
	 * the correct page regardless of slug/path, so the link survives
	 * renames + moves.
	 *
	 * @param int $pageId The page id.
	 *
	 * @return string
	 */
	private function pageDeepLink(int $pageId): string {
		return $this->urlGenerator->linkToRoute('collectives.start.index') . '?fileId=' . $pageId;
	}//end pageDeepLink()
}//end class
