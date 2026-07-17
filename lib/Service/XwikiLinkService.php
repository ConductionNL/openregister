<?php

/**
 * XwikiLinkService — Tier-2 xWiki (external, OpenConnector-routed)
 * integration service.
 *
 * Composes the {@see XwikiLinkMapper} (local link table tracking WHICH
 * remote xWiki pages are bound to an OR object) with the existing
 * {@see XwikiProvider} + {@see ExternalIntegrationRouter} OpenConnector
 * dispatch (AD-4 / ADR-019). The provider + router are resolved lazily
 * via the container so this service loads even when OpenConnector is
 * absent, mirroring the unconfigured-source 503-with-cause pattern from
 * XwikiProvider.
 *
 *   - linkPage(uuid, registerId, schemaId, pageReference)
 *       — bind an existing remote xWiki page; caches title/space/url
 *   - createAndLinkPage(uuid, registerId, schemaId, space, title)
 *       — create a new page in remote xWiki via OpenConnector POST, then link it
 *   - unlinkPage(uuid, pageReference)
 *       — remove the binding (the page itself stays in remote xWiki)
 *   - getLinkedPages(uuid)
 *       — list linked pages, refreshing cached metadata from xWiki when
 *         a source is configured and the row's cache is stale
 *   - getAvailablePages(?search)
 *       — browse remote xWiki pages via OpenConnector (picker source)
 *
 * Every method that touches OpenConnector gracefully degrades to the
 * unconfigured-source state when no `xwiki` source exists (or the
 * upstream is unreachable): the picker/list paths return a structured
 * `{ unavailable, cause }` descriptor; the mutating paths throw a 503
 * Exception carrying the same cause so the controller surfaces
 * `details.cause` for the UI's 4-state banner.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 * @link    https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use DateTime;
use Exception;
use OCA\OpenRegister\Db\XwikiLink;
use OCA\OpenRegister\Db\XwikiLinkMapper;
use OCA\OpenRegister\Exception\ProviderUnavailableException;
use OCA\OpenRegister\Service\Integration\ExternalIntegrationRouter;
use OCA\OpenRegister\Service\Integration\Providers\XwikiProvider;
use OCP\App\IAppManager;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * XwikiLinkService.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)   Composes the link
 *     mapper + XwikiProvider + ExternalIntegrationRouter (late-bound) +
 *     user session + app manager + container + logger. Each dependency is
 *     required for one of the Tier-2 flows (link, create, unlink, list,
 *     picker, cache refresh, graceful degradation).
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Tier-2 service implements
 * linkPage/createAndLinkPage/unlinkPage/getLinkedPages/getAvailablePages plus
 * getProvider/resolveRouter/requireUid/resolveRemotePage/hydrateLink/normaliseRemoteRow/normaliseList/
 * toServiceException/isStale/refreshLink; each is a required face of the xWiki integration surface.
 */
class XwikiLinkService
{
    private const REQUIRED_APP = 'openconnector';

    private const STALE_AFTER = 86400;
    // 24 hours in seconds.

    /**
     * Constructor.
     *
     * @param XwikiLinkMapper    $xwikiLinkMapper Persistence for link rows.
     * @param ContainerInterface $container       Container for late-bound provider/router.
     * @param IAppManager        $appManager      NC app manager.
     * @param IUserSession       $userSession     Active session.
     * @param LoggerInterface    $logger          Logger.
     */
    public function __construct(
        private readonly XwikiLinkMapper $xwikiLinkMapper,
        private readonly ContainerInterface $container,
        private readonly IAppManager $appManager,
        private readonly IUserSession $userSession,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Whether OpenConnector (which carries the `xwiki` source + credentials)
     * is installed + enabled. The source itself can still be missing — that
     * surfaces as `openconnector-source-missing` at call time.
     *
     * @return bool
     */
    public function isOpenConnectorAvailable(): bool
    {
        return $this->appManager->isInstalled(self::REQUIRED_APP);
    }//end isOpenConnectorAvailable()

    /**
     * Lazily resolve the XwikiProvider from the container.
     *
     * Returns null when the provider can't be resolved so callers degrade
     * gracefully (ADR-019 AD-23).
     *
     * @return XwikiProvider|null
     */
    private function getProvider(): ?XwikiProvider
    {
        try {
            $provider = $this->container->get(XwikiProvider::class);
            if ($provider instanceof XwikiProvider) {
                return $provider;
            }

            return null;
        } catch (Throwable $e) {
            $this->logger->debug('XwikiLinkService: XwikiProvider unavailable: '.$e->getMessage());
            return null;
        }
    }//end getProvider()

    /**
     * Active session UID, or throw if no user is logged in.
     *
     * @return string The user id.
     *
     * @throws Exception When there is no active user.
     */
    private function requireUid(): string
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            throw new Exception('No user logged in', 401);
        }

        return $user->getUID();
    }//end requireUid()

    /**
     * Build the unconfigured-source descriptor that mirrors the
     * XwikiProvider 503-with-cause pattern.
     *
     * @param string $cause One of the ProviderUnavailableException CAUSE_* values.
     *
     * @return array{unavailable: bool, cause: string, results: array<int,mixed>, total: int}
     */
    private function unavailableState(string $cause): array
    {
        return [
            'unavailable' => true,
            'cause'       => $cause,
            'results'     => [],
            'total'       => 0,
        ];
    }//end unavailableState()

    /**
     * Link an existing remote xWiki page to an OR object.
     *
     * Idempotent: a duplicate link raises a 409 Exception. Page metadata
     * (title/space/url) is resolved from xWiki via OpenConnector and cached
     * at link time; when the upstream is unreachable the caller-supplied
     * reference is stored with best-effort metadata so the link survives.
     *
     * @param string $objectUuid    Parent OR object uuid.
     * @param int    $registerId    OR register id.
     * @param int    $schemaId      OR schema id.
     * @param string $pageReference Remote xWiki page reference (URL or `Space.Page`).
     *
     * @return XwikiLink The persisted link row.
     *
     * @throws Exception On missing user (401), empty reference (400),
     *                   duplicate (409), source unconfigured/upstream down (503).
     *
     * @spec openspec/changes/retrofit-2026-05-25-bw2-svc-flat-2/tasks.md#task-2
     */
    public function linkPage(string $objectUuid, int $registerId, int $schemaId, string $pageReference): XwikiLink
    {
        $uid = $this->requireUid();

        $pageReference = trim($pageReference);
        if ($pageReference === '') {
            throw new Exception('Page reference is required', 400);
        }

        if ($this->isOpenConnectorAvailable() === false) {
            throw new Exception('OpenConnector is not available', 503);
        }

        // Resolve the canonical reference + metadata via OpenConnector so
        // the row caches a real title/space/url. The source normalises a
        // full URL or a `Space.Page` path to a canonical reference (AD-2).
        $info = $this->resolveRemotePage(pageReference: $pageReference, registerId: $registerId, schemaId: $schemaId, objectUuid: $objectUuid);

        $canonicalReference = (string) ($info['reference'] ?? $pageReference);
        if ($canonicalReference === '') {
            $canonicalReference = $pageReference;
        }

        $existing = $this->xwikiLinkMapper->findByObjectAndPage($objectUuid, $canonicalReference);
        if ($existing !== null) {
            throw new Exception('Page already linked to this object', 409);
        }

        $link = $this->hydrateLink(
            objectUuid: $objectUuid,
            registerId: $registerId,
            schemaId: $schemaId,
            pageReference: $canonicalReference,
            info: $info,
            uid: $uid
        );

        return $this->xwikiLinkMapper->insert($link);
    }//end linkPage()

    /**
     * Create a new page in remote xWiki via OpenConnector and link it.
     *
     * @param string $objectUuid Parent OR object uuid.
     * @param int    $registerId OR register id.
     * @param int    $schemaId   OR schema id.
     * @param string $space      Target xWiki space.
     * @param string $title      New page title.
     *
     * @return XwikiLink The persisted link row.
     *
     * @throws Exception On missing user (401), empty title/space (400),
     *                   source unconfigured/upstream down (503).
     *
     * @spec openspec/changes/retrofit-2026-05-25-bw2-svc-flat-2/tasks.md#task-2
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) createAndLinkPage() guards user auth, non-empty
     * title/space, OpenConnector availability, provider resolution, create call, canonical-reference
     * extraction, and title/space fallback in sequence; each is a required guard for the atomic
     * create+link contract.
     * @SuppressWarnings(PHPMD.NPathComplexity)      empty-title + empty-space + OpenConnector-unavailable
     * + provider-null + ProviderUnavailableException + generic-Throwable + canonical-reference-fallback
     * + title-fallback + space-fallback produce many independent paths; all guard the create+link contract.
     */
    public function createAndLinkPage(
        string $objectUuid,
        int $registerId,
        int $schemaId,
        string $space,
        string $title
    ): XwikiLink {
        $uid = $this->requireUid();

        $space = trim($space);
        $title = trim($title);
        if ($title === '') {
            throw new Exception('Page title is required', 400);
        }

        if ($space === '') {
            throw new Exception('Space is required', 400);
        }

        if ($this->isOpenConnectorAvailable() === false) {
            throw new Exception('OpenConnector is not available', 503);
        }

        $provider = $this->getProvider();
        if ($provider === null) {
            throw new Exception('OpenConnector is not available', 503);
        }

        try {
            // The OpenConnector source resolves `{ space, title }` to a new
            // remote xWiki page and returns the normalised row.
            $row = $provider->create(
                (string) $registerId,
                (string) $schemaId,
                $objectUuid,
                ['space' => $space, 'title' => $title]
            );
        } catch (ProviderUnavailableException $e) {
            throw $this->toServiceException(exception: $e);
        } catch (Throwable $e) {
            $this->logger->warning('XwikiLinkService::createAndLinkPage failed: '.$e->getMessage());
            throw new Exception('Failed to create xWiki page', 500);
        }

        $info = $this->normaliseRemoteRow(row: $row);
        if ($info['title'] === '') {
            $info['title'] = $title;
        }

        if ($info['space'] === '') {
            $info['space'] = $space;
        }

        $canonicalReference = (string) ($info['reference'] ?? '');
        if ($canonicalReference === '') {
            $canonicalReference = $space.'.'.$title;
        }

        $link = $this->hydrateLink(
            objectUuid: $objectUuid,
            registerId: $registerId,
            schemaId: $schemaId,
            pageReference: $canonicalReference,
            info: $info,
            uid: $uid
        );

        return $this->xwikiLinkMapper->insert($link);
    }//end createAndLinkPage()

    /**
     * Build an XwikiLink from normalised page info.
     *
     * @param string              $objectUuid    Parent OR object uuid.
     * @param int                 $registerId    OR register id.
     * @param int                 $schemaId      OR schema id.
     * @param string              $pageReference Canonical xWiki page reference.
     * @param array<string,mixed> $info          Normalised page info.
     * @param string              $uid           The linking user id.
     *
     * @return XwikiLink
     */
    private function hydrateLink(
        string $objectUuid,
        int $registerId,
        int $schemaId,
        string $pageReference,
        array $info,
        string $uid
    ): XwikiLink {
        $link = new XwikiLink();
        $link->setObjectUuid($objectUuid);
        $link->setRegisterId($registerId);
        $link->setSchemaId($schemaId);
        $link->setPageReference($pageReference);
        $link->setSpace($info['space'] ?? null);
        $link->setTitle((string) ($info['title'] ?? $pageReference));
        $link->setUrl($info['url'] ?? null);
        $link->setCachedAt(new DateTime());
        $link->setLinkedBy($uid);
        $link->setLinkedAt(new DateTime());

        return $link;
    }//end hydrateLink()

    /**
     * Unlink a page from an object.
     *
     * Does NOT delete the page itself — it stays in the remote xWiki
     * instance for any other linked objects.
     *
     * @param string $objectUuid    Parent OR object uuid.
     * @param string $pageReference Canonical xWiki page reference.
     *
     * @return void
     *
     * @throws Exception On missing user (401) or no matching link (404).
     *
     * @spec openspec/changes/retrofit-2026-05-25-bw2-svc-flat-2/tasks.md#task-2
     */
    public function unlinkPage(string $objectUuid, string $pageReference): void
    {
        $this->requireUid();

        $deleted = $this->xwikiLinkMapper->deleteByObjectAndPage($objectUuid, $pageReference);
        if ($deleted === 0) {
            throw new Exception('xWiki link not found', 404);
        }
    }//end unlinkPage()

    /**
     * Return the linked pages for an object, refreshing cached
     * title/space/url from remote xWiki when a source is configured and a
     * row's cache is older than 24h.
     *
     * Never throws on an upstream failure — stale rows are returned as-is
     * so historical references survive even when xWiki is down (AD-23).
     *
     * @param string $objectUuid Parent OR object uuid.
     *
     * @return array<int,array<string,mixed>>
     *
     * @spec openspec/changes/retrofit-2026-05-25-bw2-svc-flat-2/tasks.md#task-2
     */
    public function getLinkedPages(string $objectUuid): array
    {
        $links     = $this->xwikiLinkMapper->findByObjectUuid($objectUuid);
        $available = $this->isOpenConnectorAvailable();

        $results = [];
        foreach ($links as $link) {
            if ($available === true && $this->isStale(link: $link) === true) {
                $link = $this->refreshLink(link: $link);
            }

            $results[] = $link->jsonSerialize();
        }

        return $results;
    }//end getLinkedPages()

    /**
     * Browse the remote xWiki pages available to link (picker source).
     *
     * Delegates to the XwikiProvider's list/browse path through
     * OpenConnector. Returns the unconfigured-source descriptor when no
     * `xwiki` source exists (or the upstream is unreachable) so the picker
     * can render its Configure-XWiki CTA rather than crash (AD-23).
     *
     * @param string|null $search Optional title/reference substring filter.
     *
     * @return array<string,mixed> `{ results, total }` on success, or
     *                             `{ unavailable, cause, results, total }`
     *                             when the source is unconfigured/down.
     *
     * @spec openspec/changes/retrofit-2026-05-25-bw2-svc-flat-2/tasks.md#task-2
     */
    public function getAvailablePages(?string $search=null): array
    {
        if ($this->isOpenConnectorAvailable() === false) {
            return $this->unavailableState(cause: ProviderUnavailableException::CAUSE_OPENCONNECTOR_DOWN);
        }

        $provider = $this->getProvider();
        if ($provider === null) {
            return $this->unavailableState(cause: ProviderUnavailableException::CAUSE_OPENCONNECTOR_DOWN);
        }

        $filters = [];
        if ($search !== null && $search !== '') {
            $filters['_search'] = $search;
        }

        try {
            // Browse uses the same list() surface with empty OR context —
            // the OpenConnector source returns the available remote pages.
            $rows = $provider->list('', '', '', $filters);
        } catch (ProviderUnavailableException $e) {
            return $this->unavailableState(cause: $e->getCause());
        } catch (Throwable $e) {
            $this->logger->warning('XwikiLinkService::getAvailablePages failed: '.$e->getMessage());
            return $this->unavailableState(cause: ProviderUnavailableException::CAUSE_UPSTREAM_SERVICE_DOWN);
        }

        $out = [];
        foreach ($rows as $row) {
            if (is_array($row) === true) {
                $out[] = $this->normaliseRemoteRow(row: $row);
            }
        }

        return ['results' => $out, 'total' => count($out)];
    }//end getAvailablePages()

    /**
     * Free-text search of the remote xWiki knowledge base — object-independent.
     *
     * This is the query-first surface a consuming app (e.g. a dashboard widget)
     * uses to find pages by free text, without an OR object context. It shares
     * the XwikiProvider browse path with {@see getAvailablePages()} but exposes
     * a paginated `{ results, total, limit, offset }` envelope and resolves the
     * xWiki base URL exclusively from the OpenConnector `xwiki` source (AD-4) —
     * the consumer never holds a direct xWiki URL.
     *
     * Read-only and null-safe: when no `xwiki` source is configured (or the
     * upstream is unreachable) it returns the unconfigured-source descriptor
     * `{ unavailable, cause, results: [], total: 0, limit, offset }` rather than
     * throwing, so the caller degrades to an empty/Configure state and never a
     * fatal (AD-23).
     *
     * @param string|null $query  Free-text query (title/content). Null/empty
     *                            lists the most recent / available pages.
     * @param int         $limit  Max results (clamped 1..100).
     * @param int         $offset Pagination offset (clamped >= 0).
     *
     * @return array<string,mixed> `{ results, total, limit, offset }` on
     *                             success, or `{ unavailable, cause, results,
     *                             total, limit, offset }` when the source is
     *                             unconfigured/down.
     *
     * @spec openspec/changes/integration-xwiki-query-search/specs/integration-xwiki/spec.md
     */
    public function searchPages(?string $query=null, int $limit=25, int $offset=0): array
    {
        $limit  = max(1, min(100, $limit));
        $offset = max(0, $offset);

        if ($this->isOpenConnectorAvailable() === false) {
            return $this->degraded(cause: ProviderUnavailableException::CAUSE_OPENCONNECTOR_DOWN, limit: $limit, offset: $offset);
        }

        $provider = $this->getProvider();
        if ($provider === null) {
            return $this->degraded(cause: ProviderUnavailableException::CAUSE_OPENCONNECTOR_DOWN, limit: $limit, offset: $offset);
        }

        $filters = ['_limit' => $limit, '_page' => (int) (floor($offset / $limit) + 1)];
        if ($query !== null && trim($query) !== '') {
            $filters['_search'] = trim($query);
        }

        try {
            // Object-independent browse/search: empty OR context, the
            // OpenConnector source returns the matching remote pages.
            $rows = $provider->list('', '', '', $filters);
        } catch (ProviderUnavailableException $e) {
            return $this->degraded(cause: $e->getCause(), limit: $limit, offset: $offset);
        } catch (Throwable $e) {
            $this->logger->warning('XwikiLinkService::searchPages failed: '.$e->getMessage());
            return $this->degraded(cause: ProviderUnavailableException::CAUSE_UPSTREAM_SERVICE_DOWN, limit: $limit, offset: $offset);
        }

        $out = [];
        foreach ($rows as $row) {
            if (is_array($row) === true) {
                $out[] = $this->normaliseRemoteRow(row: $row);
            }
        }

        return [
            'results' => $out,
            'total'   => count($out),
            'limit'   => $limit,
            'offset'  => $offset,
        ];
    }//end searchPages()

    /**
     * Build the degraded search envelope — the unconfigured-source descriptor
     * folded with the resolved limit/offset, so the search shape stays stable
     * across the success + degraded paths (AD-23).
     *
     * @param string $cause  One of the ProviderUnavailableException CAUSE_* values.
     * @param int    $limit  Resolved limit.
     * @param int    $offset Resolved offset.
     *
     * @return array<string,mixed>
     */
    private function degraded(string $cause, int $limit, int $offset): array
    {
        $state           = $this->unavailableState(cause: $cause);
        $state['limit']  = $limit;
        $state['offset'] = $offset;

        return $state;
    }//end degraded()

    /**
     * Resolve a remote xWiki page's canonical reference + metadata via
     * OpenConnector. Best-effort: when the upstream is unreachable the
     * caller-supplied reference is returned with empty metadata so the
     * link still persists.
     *
     * @param string $pageReference Caller reference (URL or `Space.Page`).
     * @param int    $registerId    OR register id.
     * @param int    $schemaId      OR schema id.
     * @param string $objectUuid    Parent OR object uuid.
     *
     * @return array<string,mixed> Normalised info `{ reference, title, space, url }`.
     *
     * @throws Exception When the source is unconfigured/down (503).
     */
    private function resolveRemotePage(string $pageReference, int $registerId, int $schemaId, string $objectUuid): array
    {
        $provider = $this->getProvider();
        if ($provider === null) {
            throw new Exception('OpenConnector is not available', 503);
        }

        try {
            $row = $provider->get((string) $registerId, (string) $schemaId, $objectUuid, $pageReference);
        } catch (ProviderUnavailableException $e) {
            throw $this->toServiceException(exception: $e);
        } catch (Throwable $e) {
            // Non-classified failure — keep the user-supplied reference and
            // empty metadata so the link still persists.
            $this->logger->debug('XwikiLinkService::resolveRemotePage soft-failed: '.$e->getMessage());
            return ['reference' => $pageReference, 'title' => $pageReference, 'space' => '', 'url' => null];
        }

        return $this->normaliseRemoteRow(row: $row);
    }//end resolveRemotePage()

    /**
     * Normalise a provider row into the info shape used by hydrateLink +
     * the picker output.
     *
     * @param array<string,mixed> $row One normalised XwikiProvider row.
     *
     * @return array<string,mixed>
     */
    private function normaliseRemoteRow(array $row): array
    {
        $reference = (string) ($row['reference'] ?? $row['id'] ?? '');
        $title     = (string) ($row['title'] ?? '');
        $space     = (string) ($row['space'] ?? '');
        $url       = $row['url'] ?? null;

        if ($title === '') {
            $title = $reference;
        }

        $resolvedUrl = null;
        if ($url !== null && $url !== '') {
            $resolvedUrl = (string) $url;
        }

        return [
            'reference' => $reference,
            'title'     => $title,
            'space'     => $space,
            'url'       => $resolvedUrl,
        ];
    }//end normaliseRemoteRow()

    /**
     * Whether a link row's cache is older than the stale window.
     *
     * @param XwikiLink $link The link row.
     *
     * @return bool
     */
    private function isStale(XwikiLink $link): bool
    {
        $cachedAt = $link->getCachedAt();
        if ($cachedAt === null) {
            return true;
        }

        return (time() - $cachedAt->getTimestamp()) > self::STALE_AFTER;
    }//end isStale()

    /**
     * Refresh a link row's cached metadata in place from remote xWiki.
     *
     * Best-effort: when the page can't be resolved the link is left
     * untouched (the page may have been deleted in xWiki, or the upstream
     * may be down).
     *
     * @param XwikiLink $link The link row.
     *
     * @return XwikiLink The (possibly updated) link row.
     */
    private function refreshLink(XwikiLink $link): XwikiLink
    {
        $provider = $this->getProvider();
        if ($provider === null) {
            return $link;
        }

        try {
            $row = $provider->get(
                (string) (int) $link->getRegisterId(),
                (string) (int) ($link->getSchemaId() ?? 0),
                (string) $link->getObjectUuid(),
                (string) $link->getPageReference()
            );
        } catch (Throwable $e) {
            $this->logger->debug('XwikiLinkService::refreshLink fetch failed: '.$e->getMessage());
            return $link;
        }

        $info = $this->normaliseRemoteRow(row: $row);

        if (($info['title'] ?? '') !== '') {
            $link->setTitle((string) $info['title']);
        }

        if (($info['space'] ?? '') !== '') {
            $link->setSpace((string) $info['space']);
        }

        if (($info['url'] ?? null) !== null) {
            $link->setUrl((string) $info['url']);
        }

        $link->setCachedAt(new DateTime());

        try {
            return $this->xwikiLinkMapper->update($link);
        } catch (Throwable $e) {
            $this->logger->debug('XwikiLinkService::refreshLink update failed: '.$e->getMessage());
            return $link;
        }
    }//end refreshLink()

    /**
     * Translate a ProviderUnavailableException into a service Exception
     * carrying a 503 code and the cause in its message (the controller maps
     * the cause into `details.cause`).
     *
     * @param ProviderUnavailableException $exception The router exception.
     *
     * @return Exception
     */
    private function toServiceException(ProviderUnavailableException $exception): Exception
    {
        // Encode the cause so the controller can surface details.cause.
        return new Exception('xwiki:'.$exception->getCause(), 503);
    }//end toServiceException()
}//end class
