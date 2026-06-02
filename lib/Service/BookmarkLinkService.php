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
use OCA\OpenRegister\Db\BookmarkLinkMapper;
use OCP\App\IAppManager;
use OCP\IUserSession;
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
class BookmarkLinkService
{
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
     * @param BookmarkLinkMapper $bookmarkLinkMapper Persistence for link rows.
     * @param IAppManager        $appManager         NC app manager.
     * @param IUserSession       $userSession        Active session.
     * @param LoggerInterface    $logger             Logger.
     */
    public function __construct(
        private readonly BookmarkLinkMapper $bookmarkLinkMapper,
        private readonly IAppManager $appManager,
        private readonly IUserSession $userSession,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Whether NC Bookmarks is installed + enabled for the current user.
     *
     * @return bool
     */
    public function isBookmarksAvailable(): bool
    {
        return $this->appManager->isEnabledForUser(self::REQUIRED_APP);
    }//end isBookmarksAvailable()

    /**
     * Link an existing NC Bookmarks bookmark to an OR object.
     *
     * Idempotent: a duplicate link raises a 409 Exception (callers should
     * surface as HTTP 409 to the UI).
     *
     * @param string $objectUuid Parent OR object uuid.
     * @param int    $registerId OR register id.
     * @param int    $schemaId   OR schema id.
     * @param int    $bookmarkId NC Bookmarks bookmark id.
     *
     * @return BookmarkLink The persisted link row.
     *
     * @throws Exception On missing user, missing bookmark (404), duplicate (409).
     *
     * @spec openspec/changes/retrofit-2026-05-25-bw2-svc-flat-2/tasks.md#task-2
     */
    public function linkBookmark(string $objectUuid, int $registerId, int $schemaId, int $bookmarkId): BookmarkLink
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            throw new Exception('No user logged in');
        }

        $existing = $this->bookmarkLinkMapper->findByObjectAndBookmark($objectUuid, $bookmarkId);
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
        $link->setObjectUuid($objectUuid);
        $link->setRegisterId($registerId);
        $link->setSchemaId($schemaId);
        $link->setBookmarkId($bookmarkId);
        $link->setTitle($details['title']);
        $link->setUrl($details['url']);
        $link->setDescription($details['description']);
        $link->setTags($details['tags']);
        $link->setAddedAt($details['addedAt']);
        $link->setLinkedBy($user->getUID());
        $link->setLinkedAt(new DateTime());

        return $this->bookmarkLinkMapper->insert($link);
    }//end linkBookmark()

    /**
     * Unlink a bookmark from an object.
     *
     * @param string $objectUuid Parent OR object uuid.
     * @param int    $bookmarkId NC Bookmarks bookmark id.
     *
     * @return void
     *
     * @throws Exception When no matching link is found (404).
     *
     * @spec openspec/changes/retrofit-2026-05-25-bw2-svc-flat-2/tasks.md#task-2
     */
    public function unlinkBookmark(string $objectUuid, int $bookmarkId): void
    {
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
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     *
     * @spec openspec/changes/retrofit-2026-05-25-bw2-svc-flat-2/tasks.md#task-2
     */
    public function getLinkedBookmarks(string $objectUuid): array
    {
        $links = $this->bookmarkLinkMapper->findByObjectUuid($objectUuid);
        if ($links === []) {
            return [];
        }

        $available = $this->isBookmarksAvailable();
        $user      = $this->userSession->getUser();
        $userId    = $user?->getUID();
        $now       = time();

        $results = [];
        foreach ($links as $link) {
            if ($available === true && $userId !== null && $this->isStale(link: $link, now: $now) === true) {
                $details = $this->fetchBookmarkDetails(bookmarkId: (int) $link->getBookmarkId(), userId: $userId);
                if ($details !== null) {
                    $link->setTitle($details['title']);
                    $link->setUrl($details['url']);
                    $link->setDescription($details['description']);
                    $link->setTags($details['tags']);
                    $link->setAddedAt($details['addedAt']);
                    $link->setLinkedAt(new DateTime());

                    try {
                        $this->bookmarkLinkMapper->update($link);
                    } catch (Throwable $e) {
                        $this->logger->debug('BookmarkLinkService cache refresh failed: '.$e->getMessage());
                    }
                }
            }

            $results[] = $link->jsonSerialize();
        }//end foreach

        return $results;
    }//end getLinkedBookmarks()

    /**
     * Return bookmarks visible to the current user (picker source).
     *
     * Optional substring search against title/url and optional tag
     * filter. Returns an empty list when Bookmarks is unavailable.
     *
     * @param string|null            $search Optional title/url substring filter.
     * @param array<int,string>|null $tags   Optional tag filter.
     *
     * @return array<int,array{id:int,title:string,url:string,description:string,tags:array<int,string>,added:?int}>
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     *
     * @spec openspec/changes/retrofit-2026-05-25-bw2-svc-flat-2/tasks.md#task-2
     */
    public function getAvailableBookmarks(?string $search=null, ?array $tags=null): array
    {
        if ($this->isBookmarksAvailable() === false) {
            return [];
        }

        $user = $this->userSession->getUser();
        if ($user === null) {
            return [];
        }

        $mapper = $this->resolveBookmarkMapper();
        if ($mapper === null) {
            return [];
        }

        try {
            $queryParameters = new \OCA\Bookmarks\QueryParameters();
            if ($search !== null && $search !== '') {
                $queryParameters->setSearch([$search]);
            }

            if ($tags !== null && count($tags) > 0) {
                $queryParameters->setTags(array_values($tags));
            }

            $bookmarks = $mapper->findAll($user->getUID(), $queryParameters);
        } catch (Throwable $e) {
            $this->logger->warning('BookmarkLinkService::getAvailableBookmarks failed: '.$e->getMessage());
            return [];
        }

        $out = [];
        foreach ($bookmarks as $bookmark) {
            $out[] = $this->normaliseBookmark(bookmark: $bookmark);
        }

        return $out;
    }//end getAvailableBookmarks()

    /**
     * Create a new NC Bookmarks bookmark and link it to an OR object in
     * one operation.
     *
     * The new bookmark is owned by the current user. Tags supplied here
     * are applied to the bookmark via NC Bookmarks' TagMapper.
     *
     * @param string            $objectUuid  Parent OR object uuid.
     * @param int               $registerId  OR register id.
     * @param int               $schemaId    OR schema id.
     * @param string            $title       Bookmark title (required).
     * @param string            $url         Bookmark URL (required).
     * @param string|null       $description Optional description.
     * @param array<int,string> $tags        Optional tags.
     *
     * @return BookmarkLink The persisted link row.
     *
     * @throws Exception On missing user, Bookmarks unavailable, or create failure.
     *
     * @SuppressWarnings(PHPMD.ExcessiveParameterList)
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     *
     * @spec openspec/changes/retrofit-2026-05-25-bw2-svc-flat-2/tasks.md#task-2
     */
    public function createAndLinkBookmark(
        string $objectUuid,
        int $registerId,
        int $schemaId,
        string $title,
        string $url,
        ?string $description=null,
        array $tags=[]
    ): BookmarkLink {
        $user = $this->userSession->getUser();
        if ($user === null) {
            throw new Exception('No user logged in');
        }

        if ($this->isBookmarksAvailable() === false) {
            throw new Exception('Bookmarks is not available', 503);
        }

        if (trim($title) === '') {
            throw new Exception('Title is required', 400);
        }

        if (trim($url) === '') {
            throw new Exception('URL is required', 400);
        }

        $mapper = $this->resolveBookmarkMapper();
        if ($mapper === null) {
            throw new Exception('Bookmarks is not available', 503);
        }

        $cleanTags = $this->cleanTags(tags: $tags);

        try {
            $bookmark = new \OCA\Bookmarks\Db\Bookmark();
            $bookmark->setUserId($user->getUID());
            $bookmark->setUrl(trim($url));
            $bookmark->setTitle(mb_substr(trim($title), 0, 512));
            $bookmark->setDescription((string) ($description ?? ''));

            $saved      = $mapper->insertOrUpdate($bookmark);
            $bookmarkId = (int) $saved->getId();

            if ($bookmarkId === 0) {
                throw new Exception('Failed to retrieve created bookmark id', 500);
            }

            if (count($cleanTags) > 0) {
                $tagMapper = $this->resolveTagMapper();
                if ($tagMapper !== null) {
                    $tagMapper->addTo($cleanTags, $bookmarkId);
                }
            }
        } catch (Exception $e) {
            throw $e;
        } catch (Throwable $e) {
            $this->logger->warning('Failed to create bookmark: '.$e->getMessage());
            throw new Exception('Failed to create bookmark: '.$e->getMessage(), 500);
        }//end try

        $existing = $this->bookmarkLinkMapper->findByObjectAndBookmark($objectUuid, $bookmarkId);
        if ($existing !== null) {
            return $existing;
        }

        $link = new BookmarkLink();
        $link->setObjectUuid($objectUuid);
        $link->setRegisterId($registerId);
        $link->setSchemaId($schemaId);
        $link->setBookmarkId($bookmarkId);
        $link->setTitle(mb_substr(trim($title), 0, 512));
        $link->setUrl(trim($url));
        $link->setDescription((string) ($description ?? ''));
        $link->setTags($cleanTags);
        $link->setAddedAt(new DateTime());
        $link->setLinkedBy($user->getUID());
        $link->setLinkedAt(new DateTime());

        return $this->bookmarkLinkMapper->insert($link);
    }//end createAndLinkBookmark()

    /**
     * Whether a link row is stale enough to warrant a cache refresh.
     *
     * @param BookmarkLink $link The link row.
     * @param int          $now  Current epoch seconds.
     *
     * @return bool
     */
    private function isStale(BookmarkLink $link, int $now): bool
    {
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
     * @param int    $bookmarkId NC Bookmarks bookmark id.
     * @param string $userId     Owning user id (for tag scoping).
     *
     * @return array{title:string,url:string,description:string,tags:array<int,string>,addedAt:?DateTime}|null
     */
    private function fetchBookmarkDetails(int $bookmarkId, string $userId): ?array
    {
        $mapper = $this->resolveBookmarkMapper();
        if ($mapper === null) {
            return null;
        }

        try {
            $bookmark = $mapper->find($bookmarkId);
        } catch (Throwable $e) {
            $this->logger->debug('fetchBookmarkDetails find failed: '.$e->getMessage());
            return null;
        }

        if ((string) $bookmark->getUserId() !== $userId) {
            return null;
        }

        $normalised = $this->normaliseBookmark(bookmark: $bookmark);

        $addedAt = null;
        if ($normalised['added'] !== null && $normalised['added'] > 0) {
            try {
                $addedAt = new DateTime('@'.$normalised['added']);
            } catch (Throwable $e) {
                $addedAt = null;
            }
        }

        return [
            'title'       => $normalised['title'],
            'url'         => $normalised['url'],
            'description' => $normalised['description'],
            'tags'        => $normalised['tags'],
            'addedAt'     => $addedAt,
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
    private function normaliseBookmark(object $bookmark): array
    {
        $arr = (array) $bookmark;
        if (method_exists($bookmark, 'toArray') === true) {
            $arr = $bookmark->toArray();
        }

        $idFallback = 0;
        if (method_exists($bookmark, 'getId') === true) {
            $idFallback = $bookmark->getId();
        }

        $id  = (int) ($arr['id'] ?? $idFallback);
        $url = (string) ($arr['url'] ?? '');

        $tags = [];
        if (method_exists($bookmark, 'getTags') === true) {
            $rawTags = $bookmark->getTags();
            if (is_array($rawTags) === true) {
                $tags = $rawTags;
            }
        }

        if (count($tags) === 0 && $id > 0) {
            $tagMapper = $this->resolveTagMapper();
            if ($tagMapper !== null) {
                try {
                    $tags = $tagMapper->findByBookmark($id);
                } catch (Throwable $e) {
                    $tags = [];
                }
            }
        }

        $added = null;
        if (isset($arr['added']) === true) {
            $added = (int) $arr['added'];
        }

        return [
            'id'          => $id,
            'title'       => (string) ($arr['title'] ?? $url),
            'url'         => $url,
            'description' => (string) ($arr['description'] ?? ''),
            'tags'        => $this->cleanTags(tags: $tags),
            'added'       => $added,
        ];
    }//end normaliseBookmark()

    /**
     * Strip empty + `or:*` marker tags and re-index.
     *
     * @param array<int,mixed> $tags Raw tags.
     *
     * @return array<int,string>
     */
    private function cleanTags(array $tags): array
    {
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
    private function resolveBookmarkMapper(): ?object
    {
        if (class_exists('\OCA\Bookmarks\Db\BookmarkMapper') === false) {
            return null;
        }

        try {
            return \OCP\Server::get('\OCA\Bookmarks\Db\BookmarkMapper');
        } catch (Throwable $e) {
            $this->logger->debug('resolveBookmarkMapper failed: '.$e->getMessage());
            return null;
        }
    }//end resolveBookmarkMapper()

    /**
     * Lazily resolve NC Bookmarks' TagMapper behind a class_exists guard.
     *
     * @return \OCA\Bookmarks\Db\TagMapper|null
     */
    private function resolveTagMapper(): ?object
    {
        if (class_exists('\OCA\Bookmarks\Db\TagMapper') === false) {
            return null;
        }

        try {
            return \OCP\Server::get('\OCA\Bookmarks\Db\TagMapper');
        } catch (Throwable $e) {
            $this->logger->debug('resolveTagMapper failed: '.$e->getMessage());
            return null;
        }
    }//end resolveTagMapper()
}//end class
