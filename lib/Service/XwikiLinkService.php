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
use OCA\OpenRegister\Support\FleetAppId;
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
class XwikiLinkService implements XwikiLinkServiceInterface {
	// The connector answers to `integriq` on development and `openconnector`
	// on beta/main; FleetAppId holds both spellings, so this names the app
	// rather than one spelling of it. Never compare it to an id directly —
	// go through FleetAppId, or the comparison is false on half the fleet.
	private const REQUIRED_APP = 'integriq';

	private const STALE_AFTER = 86400;
	// 24 hours in seconds.

	/**
	 * Constructor.
	 *
	 * @param XwikiLinkMapper $xwikiLinkMapper Persistence for link rows.
	 * @param ContainerInterface $container Container for late-bound provider/router.
	 * @param IAppManager $appManager NC app manager.
	 * @param IUserSession $userSession Active session.
	 * @param LoggerInterface $logger Logger.
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
	public function isOpenConnectorAvailable(): bool {
		return FleetAppId::isInstalled($this->appManager, self::REQUIRED_APP);
	}//end isOpenConnectorAvailable()

	/**
	 * Lazily resolve the XwikiProvider from the container.
	 *
	 * Returns null when the provider can't be resolved so callers degrade
	 * gracefully (ADR-019 AD-23).
	 *
	 * @return XwikiProvider|null
	 */
	private function getProvider(): ?XwikiProvider {
		try {
			$provider = $this->container->get(XwikiProvider::class);
			if ($provider instanceof XwikiProvider) {
				return $provider;
			}

			return null;
		} catch (Throwable $e) {
			$this->logger->debug('XwikiLinkService: XwikiProvider unavailable: ' . $e->getMessage());
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
	private function requireUid(): string {
		return $this->userSession->getUser()?->getUID() ?? throw new Exception('No user logged in', 401);
	}//end requireUid()

	/**
	 * Build the unconfigured-source descriptor that mirrors the
	 * XwikiProvider 503-with-cause pattern.
	 *
	 * @param string $cause One of the ProviderUnavailableException CAUSE_* values.
	 *
	 * @return array{unavailable: bool, cause: string, results: array<int,mixed>, total: int}
	 */
	private function unavailableState(string $cause): array {
		return [
			'unavailable' => true,
			'cause' => $cause,
			'results' => [],
			'total' => 0,
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
	 * @param LinkTarget $target The object to link to.
	 * @param string $pageReference Remote xWiki page reference (URL or `Space.Page`).
	 *
	 * @return XwikiLink The persisted link row.
	 *
	 * @throws Exception On missing user (401), empty reference (400),
	 *                   duplicate (409), source unconfigured/upstream down (503).
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw2-svc-flat-2/tasks.md#task-2
	 */
	public function linkPage(LinkTarget $target, string $pageReference): XwikiLink {
		$uid           = $this->requireUid();
		$pageReference = trim($pageReference);
		if ($pageReference === '') {
			throw new Exception('Page reference is required', 400);
		}

		// Resolve the canonical reference + metadata via OpenConnector so
		// the row caches a real title/space/url. The source normalises a
		// full URL or a `Space.Page` path to a canonical reference (AD-2).
		$info      = $this->resolveRemotePage(pageReference: $pageReference, target: $target);
		$reference = $this->canonicalReference(info: $info, fallback: $pageReference);
		if ($this->xwikiLinkMapper->findByObjectAndPage($target->objectUuid, $reference) !== null) {
			throw new Exception('Page already linked to this object', 409);
		}

		return $this->persistLink(target: $target, uid: $uid, pageReference: $reference, info: $info);
	}//end linkPage()

	/**
	 * The canonical reference a normalised page info carries, or the fallback when it carries none.
	 *
	 * @param array<string,mixed> $info     Normalised page info.
	 * @param string              $fallback Reference to use when the info has an empty one.
	 *
	 * @return string
	 */
	private function canonicalReference(array $info, string $fallback): string {
		return $this->orDefault(value: (string)($info['reference'] ?? ''), default: $fallback);
	}//end canonicalReference()

	/**
	 * Build, stamp and insert the link row for a resolved page.
	 *
	 * @param LinkTarget          $target        The object to link to.
	 * @param string              $uid           The linking user.
	 * @param string              $pageReference Canonical xWiki page reference.
	 * @param array<string,mixed> $info          Normalised page info.
	 *
	 * @return XwikiLink The persisted link row.
	 */
	private function persistLink(LinkTarget $target, string $uid, string $pageReference, array $info): XwikiLink {
		$link = $this->hydrateLink(pageReference: $pageReference, info: $info);
		$target->stamp(link: $link, linkedBy: $uid);

		return $this->xwikiLinkMapper->insert($link);
	}//end persistLink()

	/**
	 * The XwikiProvider when OpenConnector is enabled and the provider resolves, else null.
	 *
	 * @return XwikiProvider|null
	 */
	private function availableProvider(): ?XwikiProvider {
		if ($this->isOpenConnectorAvailable() === false) {
			return null;
		}

		return $this->getProvider();
	}//end availableProvider()

	/**
	 * The XwikiProvider, or a 503 when OpenConnector or the provider is missing.
	 *
	 * @return XwikiProvider
	 *
	 * @throws Exception When OpenConnector is not available (503).
	 */
	private function requireProvider(): XwikiProvider {
		$provider = $this->availableProvider();
		if ($provider === null) {
			throw new Exception('OpenConnector is not available', 503);
		}

		return $provider;
	}//end requireProvider()

	/**
	 * Create a new page in remote xWiki via OpenConnector and link it.
	 *
	 * @param LinkTarget $target The object to link to.
	 * @param string $space Target xWiki space.
	 * @param string $title New page title.
	 *
	 * @return XwikiLink The persisted link row.
	 *
	 * @throws Exception On missing user (401), empty title/space (400),
	 *                   source unconfigured/upstream down (503).
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw2-svc-flat-2/tasks.md#task-2
	 */
	public function createAndLinkPage(LinkTarget $target, string $space, string $title): XwikiLink {
		$uid   = $this->requireUid();
		$space = trim($space);
		$title = trim($title);
		if ($title === '') {
			throw new Exception('Page title is required', 400);
		}

		if ($space === '') {
			throw new Exception('Space is required', 400);
		}

		$info      = $this->createRemotePage(target: $target, space: $space, title: $title);
		$reference = $this->canonicalReference(info: $info, fallback: $space . '.' . $title);

		return $this->persistLink(target: $target, uid: $uid, pageReference: $reference, info: $info);
	}//end createAndLinkPage()

	/**
	 * Create the page in remote xWiki and return its normalised info, with the
	 * requested title and space filling in whatever the source left empty.
	 *
	 * @param LinkTarget $target The object the page is created for.
	 * @param string     $space  Target xWiki space (trimmed, non-empty).
	 * @param string     $title  New page title (trimmed, non-empty).
	 *
	 * @return array<string,mixed> Normalised info `{ reference, title, space, url }`.
	 *
	 * @throws Exception When OpenConnector is unavailable (503) or the create fails (503/500).
	 */
	private function createRemotePage(LinkTarget $target, string $space, string $title): array {
		$provider = $this->requireProvider();
		try {
			// The OpenConnector source resolves `{ space, title }` to a new
			// remote xWiki page and returns the normalised row.
			$row = $provider->create((string)$target->registerId, (string)$target->schemaId(), $target->objectUuid, ['space' => $space, 'title' => $title]);
		} catch (ProviderUnavailableException $e) {
			throw $this->toServiceException(exception: $e);
		} catch (Throwable $e) {
			$this->logger->warning('XwikiLinkService::createAndLinkPage failed: ' . $e->getMessage());
			throw new Exception('Failed to create xWiki page', 500);
		}

		$info = $this->normaliseRemoteRow(row: $row);
		$info['title'] = $this->orDefault(value: $info['title'], default: $title);
		$info['space'] = $this->orDefault(value: $info['space'], default: $space);

		return $info;
	}//end createRemotePage()

	/**
	 * The value, or the default when the value is the empty string.
	 *
	 * @param string $value   Candidate value.
	 * @param string $default Replacement for an empty value.
	 *
	 * @return string
	 */
	private function orDefault(string $value, string $default): string {
		if ($value === '') {
			return $default;
		}

		return $value;
	}//end orDefault()

	/**
	 * Build an XwikiLink from normalised page info.
	 *
	 * @param string $pageReference Canonical xWiki page reference.
	 * @param array<string,mixed> $info Normalised page info.
	 *
	 * @return XwikiLink
	 */
	private function hydrateLink(
		string $pageReference,
		array $info,
	): XwikiLink {
		$link = new XwikiLink();
		$link->setPageReference($pageReference);
		$link->setSpace($info['space'] ?? null);
		$link->setTitle((string)($info['title'] ?? $pageReference));
		$link->setUrl($info['url'] ?? null);
		$link->setCachedAt(new DateTime());

		return $link;
	}//end hydrateLink()

	/**
	 * Unlink a page from an object.
	 *
	 * Does NOT delete the page itself — it stays in the remote xWiki
	 * instance for any other linked objects.
	 *
	 * @param string $objectUuid Parent OR object uuid.
	 * @param string $pageReference Canonical xWiki page reference.
	 *
	 * @return void
	 *
	 * @throws Exception On missing user (401) or no matching link (404).
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw2-svc-flat-2/tasks.md#task-2
	 */
	public function unlinkPage(string $objectUuid, string $pageReference): void {
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
	public function getLinkedPages(string $objectUuid): array {
		$links = $this->xwikiLinkMapper->findByObjectUuid($objectUuid);
		$available = $this->isOpenConnectorAvailable();

		$results = [];
		foreach ($links as $link) {
			$results[] = $this->currentLink(link: $link, refresh: $available)->jsonSerialize();
		}

		return $results;
	}//end getLinkedPages()

	/**
	 * The link, refreshed from remote xWiki first when refreshing is possible and its cache is stale.
	 *
	 * @param XwikiLink $link    The link row.
	 * @param bool      $refresh Whether OpenConnector is available to refresh from.
	 *
	 * @return XwikiLink
	 */
	private function currentLink(XwikiLink $link, bool $refresh): XwikiLink {
		if ($refresh === true && $this->isStale(link: $link) === true) {
			return $this->refreshLink(link: $link);
		}

		return $link;
	}//end currentLink()

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
	public function getAvailablePages(?string $search = null): array {
		$filters = [];
		if ($search !== null && $search !== '') {
			$filters['_search'] = $search;
		}

		$outcome = $this->browse(filters: $filters, caller: 'getAvailablePages');
		if ($outcome['cause'] !== null) {
			return $this->unavailableState(cause: $outcome['cause']);
		}

		return ['results' => $outcome['results'], 'total' => count($outcome['results'])];
	}//end getAvailablePages()

	/**
	 * Run the provider's browse/list path with an empty OR context and normalise the rows.
	 *
	 * @param array<string,mixed> $filters Provider list filters.
	 * @param string              $caller  Public method name, for the failure log line.
	 *
	 * @return array{cause: string|null, results: array<int,array<string,mixed>>} A null cause
	 *         with the rows on success, else the degradation cause and no rows.
	 */
	private function browse(array $filters, string $caller): array {
		$provider = $this->availableProvider();
		if ($provider === null) {
			return ['cause' => ProviderUnavailableException::CAUSE_OPENCONNECTOR_DOWN, 'results' => []];
		}

		try {
			// Browse uses the same list() surface with empty OR context —
			// the OpenConnector source returns the matching remote pages.
			$rows = $provider->list('', '', '', $filters);
		} catch (ProviderUnavailableException $e) {
			return ['cause' => $e->getCause(), 'results' => []];
		} catch (Throwable $e) {
			$this->logger->warning('XwikiLinkService::' . $caller . ' failed: ' . $e->getMessage());
			return ['cause' => ProviderUnavailableException::CAUSE_UPSTREAM_SERVICE_DOWN, 'results' => []];
		}

		return ['cause' => null, 'results' => $this->normaliseRows(rows: $rows)];
	}//end browse()

	/**
	 * Normalise the array rows of a provider list, skipping anything else.
	 *
	 * @param iterable<mixed> $rows Provider rows.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function normaliseRows(iterable $rows): array {
		$out = [];
		foreach ($rows as $row) {
			if (is_array($row) === true) {
				$out[] = $this->normaliseRemoteRow(row: $row);
			}
		}

		return $out;
	}//end normaliseRows()

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
	 * @param string|null $query Free-text query (title/content). Null/empty
	 *                           lists the most recent / available pages.
	 * @param int $limit Max results (clamped 1..100).
	 * @param int $offset Pagination offset (clamped >= 0).
	 *
	 * @return array<string,mixed> `{ results, total, limit, offset }` on
	 *                             success, or `{ unavailable, cause, results,
	 *                             total, limit, offset }` when the source is
	 *                             unconfigured/down.
	 *
	 * @spec openspec/specs/integration-xwiki/spec.md
	 */
	public function searchPages(?string $query = null, int $limit = 25, int $offset = 0): array {
		$limit  = max(1, min(100, $limit));
		$offset = max(0, $offset);

		// Object-independent browse/search: empty OR context.
		$outcome = $this->browse(filters: $this->searchFilters(query: $query, limit: $limit, offset: $offset), caller: 'searchPages');
		if ($outcome['cause'] !== null) {
			return $this->degraded(cause: $outcome['cause'], limit: $limit, offset: $offset);
		}

		return [
			'results' => $outcome['results'],
			'total' => count($outcome['results']),
			'limit' => $limit,
			'offset' => $offset,
		];
	}//end searchPages()

	/**
	 * Provider list filters for a free-text search page.
	 *
	 * @param string|null $query  Free-text query; blank adds no search filter.
	 * @param int         $limit  Clamped limit.
	 * @param int         $offset Clamped offset.
	 *
	 * @return array<string,mixed>
	 */
	private function searchFilters(?string $query, int $limit, int $offset): array {
		$filters = ['_limit' => $limit, '_page' => (int)(floor($offset / $limit) + 1)];
		if ($query !== null && trim($query) !== '') {
			$filters['_search'] = trim($query);
		}

		return $filters;
	}//end searchFilters()

	/**
	 * Build the degraded search envelope — the unconfigured-source descriptor
	 * folded with the resolved limit/offset, so the search shape stays stable
	 * across the success + degraded paths (AD-23).
	 *
	 * @param string $cause One of the ProviderUnavailableException CAUSE_* values.
	 * @param int $limit Resolved limit.
	 * @param int $offset Resolved offset.
	 *
	 * @return array<string,mixed>
	 */
	private function degraded(string $cause, int $limit, int $offset): array {
		$state = $this->unavailableState(cause: $cause);
		$state['limit'] = $limit;
		$state['offset'] = $offset;

		return $state;
	}//end degraded()

	/**
	 * Resolve a remote xWiki page's canonical reference + metadata via
	 * OpenConnector. Best-effort: when the upstream is unreachable the
	 * caller-supplied reference is returned with empty metadata so the
	 * link still persists.
	 *
	 * @param string     $pageReference Caller reference (URL or `Space.Page`).
	 * @param LinkTarget $target        The object the page is linked to.
	 *
	 * @return array<string,mixed> Normalised info `{ reference, title, space, url }`.
	 *
	 * @throws Exception When the source is unconfigured/down (503).
	 */
	private function resolveRemotePage(string $pageReference, LinkTarget $target): array {
		$provider = $this->requireProvider();
		try {
			$row = $provider->get((string)$target->registerId, (string)$target->schemaId(), $target->objectUuid, $pageReference);
		} catch (ProviderUnavailableException $e) {
			throw $this->toServiceException(exception: $e);
		} catch (Throwable $e) {
			// Non-classified failure — keep the user-supplied reference and
			// empty metadata so the link still persists.
			$this->logger->debug('XwikiLinkService::resolveRemotePage soft-failed: ' . $e->getMessage());
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
	private function normaliseRemoteRow(array $row): array {
		$reference = (string)($row['reference'] ?? $row['id'] ?? '');

		return [
			'reference' => $reference,
			'title' => $this->orDefault(value: (string)($row['title'] ?? ''), default: $reference),
			'space' => (string)($row['space'] ?? ''),
			'url' => $this->optionalUrl(url: $row['url'] ?? null),
		];
	}//end normaliseRemoteRow()

	/**
	 * A provider url as a string, or null when it is null or empty.
	 *
	 * @param mixed $url Raw url value.
	 *
	 * @return string|null
	 */
	private function optionalUrl(mixed $url): ?string {
		if ($url === null || $url === '') {
			return null;
		}

		return (string)$url;
	}//end optionalUrl()

	/**
	 * Whether a link row's cache is older than the stale window.
	 *
	 * @param XwikiLink $link The link row.
	 *
	 * @return bool
	 */
	private function isStale(XwikiLink $link): bool {
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
	private function refreshLink(XwikiLink $link): XwikiLink {
		$row = $this->fetchLinkedRow(link: $link);
		if ($row === null) {
			return $link;
		}

		$this->applyRemoteInfo(link: $link, info: $this->normaliseRemoteRow(row: $row));
		$link->setCachedAt(new DateTime());

		try {
			return $this->xwikiLinkMapper->update($link);
		} catch (Throwable $e) {
			$this->logger->debug('XwikiLinkService::refreshLink update failed: ' . $e->getMessage());
			return $link;
		}
	}//end refreshLink()

	/**
	 * Fetch the remote row behind a link, or null when the provider is missing or the fetch fails.
	 *
	 * @param XwikiLink $link The link row.
	 *
	 * @return array<string,mixed>|null
	 */
	private function fetchLinkedRow(XwikiLink $link): ?array {
		$provider = $this->getProvider();
		if ($provider === null) {
			return null;
		}

		$register = (string)(int)$link->getRegisterId();
		$schema   = (string)(int)($link->getSchemaId() ?? 0);
		try {
			return $provider->get($register, $schema, (string)$link->getObjectUuid(), (string)$link->getPageReference());
		} catch (Throwable $e) {
			$this->logger->debug('XwikiLinkService::refreshLink fetch failed: ' . $e->getMessage());
			return null;
		}
	}//end fetchLinkedRow()

	/**
	 * Copy the non-empty title, space and url of normalised page info onto a link.
	 *
	 * @param XwikiLink           $link The link row.
	 * @param array<string,mixed> $info Normalised page info.
	 *
	 * @return void
	 */
	private function applyRemoteInfo(XwikiLink $link, array $info): void {
		if (($info['title'] ?? '') !== '') {
			$link->setTitle((string)$info['title']);
		}

		if (($info['space'] ?? '') !== '') {
			$link->setSpace((string)$info['space']);
		}

		if (($info['url'] ?? null) !== null) {
			$link->setUrl((string)$info['url']);
		}
	}//end applyRemoteInfo()

	/**
	 * Translate a ProviderUnavailableException into a service Exception
	 * carrying a 503 code and the cause in its message (the controller maps
	 * the cause into `details.cause`).
	 *
	 * @param ProviderUnavailableException $exception The router exception.
	 *
	 * @return Exception
	 */
	private function toServiceException(ProviderUnavailableException $exception): Exception {
		// Encode the cause so the controller can surface details.cause.
		return new Exception('xwiki:' . $exception->getCause(), 503);
	}//end toServiceException()
}//end class
