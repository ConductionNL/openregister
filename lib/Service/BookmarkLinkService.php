<?php

/**
 * BookmarkLinkService — Tier-2 bookmarks integration service.
 *
 * Composes the {@see BookmarkLinkMapper} with NC Bookmarks' own
 * `BookmarkMapper` + `TagMapper` (lazy-resolved via `\OCP\Server::get()`
 * behind a `class_exists` guard so OR boots cleanly when Bookmarks is not
 * installed) to provide the picker + inline-create UX surface area:
 *
 *   - linkBookmark(uuid, registerId, schemaId, bookmarkId)     — link existing
 *   - createAndLinkBookmark(uuid, ..., title, url,
 *                           description?, tags)                — create + link
 *   - unlinkBookmark(uuid, bookmarkId)
 *   - getLinkedBookmarks(uuid)                                 — list, cache-refresh
 *   - getAvailableBookmarks(search?, tags?)                    — picker source
 *
 * Replaces the original BookmarksProvider's `or:{uuid}` tag-marker
 * convention with a proper persistence layer so links survive Bookmarks
 * tag edits + don't pollute Bookmarks' UX. The cached title/url/
 * description/tags on each link row are refreshed from NC Bookmarks when
 * the row is older than 24h; when Bookmarks is unavailable, the cached
 * link-row values are returned unchanged so historical references survive
 * even after Bookmarks is uninstalled.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use DateTime;
use Exception;
use OCA\OpenRegister\Db\BookmarkLink;
use OCA\OpenRegister\Db\BookmarkLinkMapperInterface;
use OCP\App\IAppManager;
use OCP\IUser;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * BookmarkLinkService.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)   Composes mapper +
 *     IAppManager + IUserSession + logger + lazily-resolved Bookmarks
 *     mappers. Each dependency is required.
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) The create+link path
 *     and cache-refresh hydration inflate the count; the alternative
 *     (re-implementing NC Bookmarks' write path) would be worse.
 * @SuppressWarnings(PHPMD.StaticAccess)             `\OCP\Server::get()`
 *     is the documented way to late-resolve an optional peer app's
 *     services behind a class_exists guard (ADR-019).
 * @SuppressWarnings(PHPMD.MissingImport)            NC Bookmarks classes
 *     are referenced FQN-only on purpose — importing them would break
 *     autoloading when Bookmarks is not installed.
 */
class BookmarkLinkService implements BookmarkLinkServiceInterface {
	/**
	 * NC Bookmarks app id.
	 *
	 * @var string
	 */
	private const REQUIRED_APP = 'bookmarks';

	/**
	 * Marker tag prefix kept off the bookmark's user-visible tag set.
	 *
	 * @var string
	 */
	private const TAG_PREFIX = 'or:';

	/**
	 * Cache-refresh staleness threshold in seconds (24h).
	 *
	 * @var int
	 */
	private const CACHE_TTL = 86400;

	/**
	 * Constructor.
	 *
	 * @param BookmarkLinkMapperInterface $bookmarkLinkMapper Persistence for link rows.
	 * @param IAppManager $appManager NC app manager.
	 * @param IUserSession $userSession Active session.
	 * @param LoggerInterface $logger Logger.
	 * @param ContainerInterface $container App container the Bookmarks mappers are resolved from.
	 */
	public function __construct(
		private readonly BookmarkLinkMapperInterface $bookmarkLinkMapper,
		private readonly IAppManager $appManager,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
		private readonly ContainerInterface $container,
	) {
	}//end __construct()

	/**
	 * Whether NC Bookmarks is installed + enabled for the current user.
	 *
	 * @return bool
	 */
	public function isBookmarksAvailable(): bool {
		return $this->appManager->isEnabledForUser(self::REQUIRED_APP);
	}//end isBookmarksAvailable()

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
	public function linkBookmark(LinkTarget $target, int $bookmarkId): BookmarkLink {
		$user = $this->userSession->getUser();
		if ($user === null) {
			throw new Exception('No user logged in');
		}

		$existing = $this->bookmarkLinkMapper->findByObjectAndBookmark($target->objectUuid, $bookmarkId);
		if ($existing !== null) {
			throw new Exception('Bookmark already linked to this object', 409);
		}

		if ($this->isBookmarksAvailable() === false) {
			throw new Exception('Bookmarks is not available', 503);
		}

		$details = $this->fetchBookmarkDetails(bookmarkId: $bookmarkId, userId: $user->getUID());
		if ($details === null) {
			throw new Exception('Bookmark not found', 404);
		}

		$link = new BookmarkLink();
		$link->setBookmarkId($bookmarkId);
		$this->applyDetails(link: $link, details: $details);
		$target->stamp(link: $link, linkedBy: $user->getUID());

		return $this->bookmarkLinkMapper->insert($link);
	}//end linkBookmark()

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
	public function unlinkBookmark(string $objectUuid, int $bookmarkId): void {
		$deleted = $this->bookmarkLinkMapper->deleteByObjectAndBookmark($objectUuid, $bookmarkId);
		if ($deleted === 0) {
			throw new Exception('Bookmark link not found', 404);
		}
	}//end unlinkBookmark()

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
	public function getLinkedBookmarks(string $objectUuid): array {
		$links = $this->bookmarkLinkMapper->findByObjectUuid($objectUuid);
		if ($links === []) {
			return [];
		}

		$userId = $this->refreshingUserId();
		$now = time();

		$results = [];
		foreach ($links as $link) {
			if ($userId !== null && $this->isStale(link: $link, now: $now) === true) {
				$this->refreshLink(link: $link, userId: $userId);
			}

			$results[] = $link->jsonSerialize();
		}

		return $results;
	}//end getLinkedBookmarks()

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
	public function getAvailableBookmarks(?string $search = null, ?array $tags = null): array {
		$user = $this->pickerUser();
		if ($user === null) {
			return [];
		}

		$mapper = $this->resolveBookmarkMapper();
		if ($mapper === null) {
			return [];
		}

		return $this->normaliseAll(
			bookmarks: $this->findBookmarks(mapper: $mapper, userId: $user->getUID(), search: $search ?? '', tags: $tags ?? [])
		);
	}//end getAvailableBookmarks()

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
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
	 * @spec openspec/changes/retrofit-2026-05-25-bw2-svc-flat-2/tasks.md#task-2
	 */
	public function createAndLinkBookmark(LinkTarget $target, BookmarkDraft $draft): BookmarkLink {
		$user = $this->userSession->getUser();
		if ($user === null) {
			throw new Exception('No user logged in');
		}

		$mapper = $this->requireCreatableMapper(draft: $draft);

		$cleanTags = $this->cleanTags(tags: $draft->tags());

		$bookmarkId = $this->createBookmark(mapper: $mapper, userId: $user->getUID(), draft: $draft, cleanTags: $cleanTags);

		$existing = $this->bookmarkLinkMapper->findByObjectAndBookmark($target->objectUuid, $bookmarkId);
		if ($existing !== null) {
			return $existing;
		}

		$link = new BookmarkLink();
		$link->setBookmarkId($bookmarkId);
		$link->setTitle(mb_substr(trim($draft->title), 0, 512));
		$link->setUrl(trim($draft->url));
		$link->setDescription((string)($draft->description() ?? ''));
		$link->setTags($cleanTags);
		$link->setAddedAt(new DateTime());
		$target->stamp(link: $link, linkedBy: $user->getUID());

		return $this->bookmarkLinkMapper->insert($link);
	}//end createAndLinkBookmark()

	/**
	 * The user whose stale link rows may be refreshed: none when Bookmarks is unavailable.
	 *
	 * @return string|null
	 */
	private function refreshingUserId(): ?string {
		$available = $this->isBookmarksAvailable();
		$user = $this->userSession->getUser();
		if ($available === false) {
			return null;
		}

		return $user?->getUID();
	}//end refreshingUserId()

	/**
	 * The user the picker lists bookmarks for: none when Bookmarks is unavailable or nobody is logged in.
	 *
	 * @return IUser|null
	 */
	private function pickerUser(): ?IUser {
		if ($this->isBookmarksAvailable() === false) {
			return null;
		}

		return $this->userSession->getUser();
	}//end pickerUser()

	/**
	 * Normalise each listed bookmark into a picker row.
	 *
	 * @param iterable<object> $bookmarks NC Bookmarks entities.
	 *
	 * @return array<int,array{id:int,title:string,url:string,description:string,tags:array<int,string>,added:?int}>
	 */
	private function normaliseAll(iterable $bookmarks): array {
		$out = [];
		foreach ($bookmarks as $bookmark) {
			$out[] = $this->normaliseBookmark(bookmark: $bookmark);
		}

		return $out;
	}//end normaliseAll()

	/**
	 * Copy the Bookmarks-side details onto a link row.
	 *
	 * @param BookmarkLink $link The link row.
	 * @param array{title:string,url:string,description:string,tags:array<int,string>,addedAt:?DateTime} $details Bookmark details.
	 *
	 * @return void
	 */
	private function applyDetails(BookmarkLink $link, array $details): void {
		$link->setTitle($details['title']);
		$link->setUrl($details['url']);
		$link->setDescription($details['description']);
		$link->setTags($details['tags']);
		$link->setAddedAt($details['addedAt']);
	}//end applyDetails()

	/**
	 * Refresh a stale cached link row from NC Bookmarks; a failed save is logged and ignored.
	 *
	 * @param BookmarkLink $link   The cached link row.
	 * @param string       $userId The current user's id.
	 *
	 * @return void
	 */
	private function refreshLink(BookmarkLink $link, string $userId): void {
		$details = $this->fetchBookmarkDetails(bookmarkId: (int)$link->getBookmarkId(), userId: $userId);
		if ($details === null) {
			return;
		}

		$this->applyDetails(link: $link, details: $details);
		$link->setLinkedAt(new DateTime());

		try {
			$this->bookmarkLinkMapper->update($link);
		} catch (Throwable $e) {
			$this->logger->debug('BookmarkLinkService cache refresh failed: ' . $e->getMessage());
		}
	}//end refreshLink()

	/**
	 * List the user's bookmarks matching the optional search and tags; a failure is logged and yields none.
	 *
	 * @param object            $mapper The NC Bookmarks BookmarkMapper.
	 * @param string            $userId The current user's id.
	 * @param string            $search Title/url substring filter; '' for none.
	 * @param array<int,string> $tags   Tag filter; empty for none.
	 *
	 * @return iterable<object>
	 */
	private function findBookmarks(object $mapper, string $userId, string $search, array $tags): iterable {
		try {
			return $mapper->findAll($userId, $this->queryParameters(search: $search, tags: $tags));
		} catch (Throwable $e) {
			$this->logger->warning('BookmarkLinkService::getAvailableBookmarks failed: ' . $e->getMessage());
			return [];
		}
	}//end findBookmarks()

	/**
	 * Build the NC Bookmarks query parameters for a picker listing.
	 *
	 * @param string            $search Title/url substring filter; '' for none.
	 * @param array<int,string> $tags   Tag filter; empty for none.
	 *
	 * @return object
	 */
	private function queryParameters(string $search, array $tags): object {
		$queryParameters = new \OCA\Bookmarks\QueryParameters();
		if ($search !== '') {
			$queryParameters->setSearch([$search]);
		}

		if (count($tags) > 0) {
			$queryParameters->setTags(array_values($tags));
		}

		return $queryParameters;
	}//end queryParameters()

	/**
	 * Check that a bookmark can be created from the draft and resolve the Bookmarks mapper.
	 *
	 * @param BookmarkDraft $draft The bookmark to create.
	 *
	 * @return object The NC Bookmarks BookmarkMapper.
	 *
	 * @throws Exception When Bookmarks is unavailable (503) or the title or URL is empty (400).
	 */
	private function requireCreatableMapper(BookmarkDraft $draft): object {
		if ($this->isBookmarksAvailable() === false) {
			throw new Exception('Bookmarks is not available', 503);
		}

		if (trim($draft->title) === '') {
			throw new Exception('Title is required', 400);
		}

		if (trim($draft->url) === '') {
			throw new Exception('URL is required', 400);
		}

		$mapper = $this->resolveBookmarkMapper();
		if ($mapper === null) {
			throw new Exception('Bookmarks is not available', 503);
		}

		return $mapper;
	}//end requireCreatableMapper()

	/**
	 * Create the bookmark in NC Bookmarks and tag it.
	 *
	 * @param object            $mapper    The NC Bookmarks BookmarkMapper.
	 * @param string            $userId    The owning user's id.
	 * @param BookmarkDraft     $draft     The bookmark to create.
	 * @param array<int,string> $cleanTags The cleaned tags to attach.
	 *
	 * @return int The created bookmark id.
	 *
	 * @throws Exception When the bookmark cannot be created (500).
	 */
	private function createBookmark(object $mapper, string $userId, BookmarkDraft $draft, array $cleanTags): int {
		try {
			$bookmark = new \OCA\Bookmarks\Db\Bookmark();
			$bookmark->setUserId($userId);
			$bookmark->setUrl(trim($draft->url));
			$bookmark->setTitle(mb_substr(trim($draft->title), 0, 512));
			$bookmark->setDescription((string)($draft->description() ?? ''));

			$saved = $mapper->insertOrUpdate($bookmark);
			$bookmarkId = (int)$saved->getId();

			if ($bookmarkId === 0) {
				throw new Exception('Failed to retrieve created bookmark id', 500);
			}

			$this->addTags(cleanTags: $cleanTags, bookmarkId: $bookmarkId);
		} catch (Exception $e) {
			throw $e;
		} catch (Throwable $e) {
			$this->logger->warning('Failed to create bookmark: ' . $e->getMessage());
			throw new Exception('Failed to create bookmark: ' . $e->getMessage(), 500);
		}//end try

		return $bookmarkId;
	}//end createBookmark()

	/**
	 * Attach tags to a bookmark when there are any and the tag mapper resolves.
	 *
	 * @param array<int,string> $cleanTags  The cleaned tags.
	 * @param int               $bookmarkId The bookmark id.
	 *
	 * @return void
	 */
	private function addTags(array $cleanTags, int $bookmarkId): void {
		if (count($cleanTags) === 0) {
			return;
		}

		$tagMapper = $this->resolveTagMapper();
		if ($tagMapper !== null) {
			$tagMapper->addTo($cleanTags, $bookmarkId);
		}
	}//end addTags()

	/**
	 * The added-at time of a bookmark, or null when it has none.
	 *
	 * @param int $added Unix timestamp from Bookmarks; 0 when unknown.
	 *
	 * @return DateTime|null
	 */
	private function addedAt(int $added): ?DateTime {
		if ($added <= 0) {
			return null;
		}

		try {
			return new DateTime('@' . $added);
		} catch (Throwable $e) {
			return null;
		}
	}//end addedAt()

	/**
	 * The tags of a bookmark: its own, else (with a positive id) those the tag mapper finds.
	 *
	 * @param object $bookmark The NC Bookmarks entity.
	 * @param int    $id       The bookmark id.
	 *
	 * @return mixed
	 */
	private function bookmarkTags(object $bookmark, int $id): mixed {
		$tags = [];
		if (method_exists($bookmark, 'getTags') === true) {
			$rawTags = $bookmark->getTags();
			if (is_array($rawTags) === true) {
				$tags = $rawTags;
			}
		}

		if (count($tags) > 0 || $id <= 0) {
			return $tags;
		}

		return $this->lookupTags(id: $id);
	}//end bookmarkTags()

	/**
	 * The tags the NC Bookmarks tag mapper holds for a bookmark; none when it cannot be read.
	 *
	 * @param int $id The bookmark id.
	 *
	 * @return mixed
	 */
	private function lookupTags(int $id): mixed {
		$tagMapper = $this->resolveTagMapper();
		if ($tagMapper === null) {
			return [];
		}

		try {
			return $tagMapper->findByBookmark($id);
		} catch (Throwable $e) {
			return [];
		}
	}//end lookupTags()

	/**
	 * Whether a link row is stale enough to warrant a cache refresh.
	 *
	 * @param BookmarkLink $link The link row.
	 * @param int $now Current epoch seconds.
	 *
	 * @return bool
	 */
	private function isStale(BookmarkLink $link, int $now): bool {
		$linkedAt = $link->getLinkedAt();
		if ($linkedAt === null) {
			return true;
		}

		return ($now - $linkedAt->getTimestamp()) > self::CACHE_TTL;
	}//end isStale()

	/**
	 * Fetch a single bookmark's normalised details for caching at link
	 * time / cache-refresh.
	 *
	 * @param int $bookmarkId NC Bookmarks bookmark id.
	 * @param string $userId Owning user id (for tag scoping).
	 *
	 * @return array{title:string,url:string,description:string,tags:array<int,string>,addedAt:?DateTime}|null
	 */
	private function fetchBookmarkDetails(int $bookmarkId, string $userId): ?array {
		$mapper = $this->resolveBookmarkMapper();
		if ($mapper === null) {
			return null;
		}

		try {
			$bookmark = $mapper->find($bookmarkId);
		} catch (Throwable $e) {
			$this->logger->debug('fetchBookmarkDetails find failed: ' . $e->getMessage());
			return null;
		}

		if ((string)$bookmark->getUserId() !== $userId) {
			return null;
		}

		$normalised = $this->normaliseBookmark(bookmark: $bookmark);
		$addedAt = $this->addedAt(added: $normalised['added'] ?? 0);

		return [
			'title' => $normalised['title'],
			'url' => $normalised['url'],
			'description' => $normalised['description'],
			'tags' => $normalised['tags'],
			'addedAt' => $addedAt,
		];
	}//end fetchBookmarkDetails()

	/**
	 * Normalise a NC Bookmarks bookmark entity into the picker/leaf row
	 * shape. Tags are read via TagMapper when not already joined on the
	 * entity, and `or:*` markers are stripped.
	 *
	 * @param object $bookmark NC Bookmarks Bookmark entity.
	 *
	 * @return array{id:int,title:string,url:string,description:string,tags:array<int,string>,added:?int}
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
	 */
	private function normaliseBookmark(object $bookmark): array {
		$arr = (array)$bookmark;
		if (method_exists($bookmark, 'toArray') === true) {
			$arr = $bookmark->toArray();
		}

		$idFallback = 0;
		if (method_exists($bookmark, 'getId') === true) {
			$idFallback = $bookmark->getId();
		}

		$id = (int)($arr['id'] ?? $idFallback);
		$url = (string)($arr['url'] ?? '');

		$tags = $this->bookmarkTags(bookmark: $bookmark, id: $id);

		$added = null;
		if (isset($arr['added']) === true) {
			$added = (int)$arr['added'];
		}

		return [
			'id' => $id,
			'title' => (string)($arr['title'] ?? $url),
			'url' => $url,
			'description' => (string)($arr['description'] ?? ''),
			'tags' => $this->cleanTags(tags: $tags),
			'added' => $added,
		];
	}//end normaliseBookmark()

	/**
	 * Strip empty + `or:*` marker tags and re-index.
	 *
	 * @param array<int,mixed> $tags Raw tags.
	 *
	 * @return array<int,string>
	 */
	private function cleanTags(array $tags): array {
		$out = [];
		foreach ($tags as $tag) {
			if (is_string($tag) === false) {
				continue;
			}

			$trimmed = trim($tag);
			if ($trimmed === '' || str_starts_with($trimmed, self::TAG_PREFIX) === true) {
				continue;
			}

			$out[] = $trimmed;
		}

		return array_values(array_unique($out));
	}//end cleanTags()

	/**
	 * Lazily resolve NC Bookmarks' BookmarkMapper behind a class_exists
	 * guard so OR boots cleanly without the Bookmarks app.
	 *
	 * @return \OCA\Bookmarks\Db\BookmarkMapper|null
	 */
	private function resolveBookmarkMapper(): ?object {
		if (class_exists('\OCA\Bookmarks\Db\BookmarkMapper') === false) {
			return null;
		}

		try {
			return $this->container->get('\OCA\Bookmarks\Db\BookmarkMapper');
		} catch (Throwable $e) {
			$this->logger->debug('resolveBookmarkMapper failed: ' . $e->getMessage());
			return null;
		}
	}//end resolveBookmarkMapper()

	/**
	 * Lazily resolve NC Bookmarks' TagMapper behind a class_exists guard.
	 *
	 * @return \OCA\Bookmarks\Db\TagMapper|null
	 */
	private function resolveTagMapper(): ?object {
		if (class_exists('\OCA\Bookmarks\Db\TagMapper') === false) {
			return null;
		}

		try {
			return $this->container->get('\OCA\Bookmarks\Db\TagMapper');
		} catch (Throwable $e) {
			$this->logger->debug('resolveTagMapper failed: ' . $e->getMessage());
			return null;
		}
	}//end resolveTagMapper()
}//end class
