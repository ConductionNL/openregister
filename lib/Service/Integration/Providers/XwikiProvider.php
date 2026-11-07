<?php

/**
 * XwikiProvider — exposes XWiki pages linked to an OpenRegister
 * object via the IntegrationProvider contract.
 *
 * Storage strategy is `external` (AD-4 / AD-22): there is no local
 * link table — pairings live in OpenConnector's own model and the
 * pages themselves live in the remote XWiki instance. All CRUD is
 * delegated to {@see ExternalIntegrationRouter}, which resolves the
 * declared OpenConnector source (`xwiki`), makes the call, and
 * surfaces structured failures via {@see ProviderUnavailableException}
 * (AD-23). The provider never carries an HTTP client and never
 * handles credentials — those are configured on the OpenConnector
 * source (basic or OAuth2, customer-dependent — AD-15).
 *
 * Per the leaf design.md:
 *   - AD-1: detail-page preview is text-only (first ~500 chars of the
 *     HTML-rendered content, macros NOT executed) — the UI does the
 *     truncation; the provider just round-trips whatever the source
 *     returns under `content` / `preview`.
 *   - AD-2: callers may link a page by full XWiki URL or by a
 *     `space.page` path — the OpenConnector source normalises both to
 *     a canonical reference. The provider passes the raw `reference`
 *     through in `create()`.
 *   - AD-3: rows carry a `breadcrumb` so the UI can disambiguate
 *     same-titled pages in different spaces.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Integration\Providers
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/integration-xwiki/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Integration\Providers;

use OCA\OpenRegister\Db\XwikiLink;
use OCA\OpenRegister\Db\XwikiLinkMapper;
use OCA\OpenRegister\Service\Integration\AbstractIntegrationProvider;
use OCA\OpenRegister\Service\Integration\ExternalIntegrationRouterInterface;
use OCA\OpenRegister\Support\FleetAppId;
use OCP\App\IAppManager;
use OCP\IL10N;
use Throwable;

/**
 * XWiki integration provider — external, OpenConnector-backed.
 */
class XwikiProvider extends AbstractIntegrationProvider {

	use ConnectorRoutedProviderTrait;
	use ConnectorEntityCallsTrait;

	/**
	 * OpenConnector source id this provider routes through.
	 *
	 * @var string
	 */
	private const SOURCE_ID = 'xwiki';

	/**
	 * NC app that must be installed for this integration to function
	 * (it carries the OpenConnector source + credentials).
	 *
	 * @var string
	 */
	// The connector answers to `integriq` on development and `openconnector`
	// on beta/main; FleetAppId holds both spellings, so this names the app
	// rather than one spelling of it. Never compare it to an id directly —
	// go through FleetAppId, or the comparison is false on half the fleet.
	private const REQUIRED_APP = 'integriq';

	/**
	 * Constructor.
	 *
	 * @param ExternalIntegrationRouterInterface $router External-call router.
	 * @param IAppManager $appManager NC app manager (isEnabled check).
	 * @param IL10N $l10n Localisation.
	 * @param XwikiLinkMapper|null $xwikiLinkMapper Tier-2 local link table
	 *                                              (nullable so the Tier-1
	 *                                              external-only wiring + the
	 *                                              provider unit test keep
	 *                                              working without it).
	 *
	 * @return void
	 */
	public function __construct(
		private ExternalIntegrationRouterInterface $router,
		private IAppManager $appManager,
		private IL10N $l10n,
		private ?XwikiLinkMapper $xwikiLinkMapper = null,
	) {
	}//end __construct()

	/**
	 * Stable provider id (matches the PHP/JS registrations + the
	 * OpenConnector source name).
	 *
	 * @return string
	 */
	public function getId(): string {
		return 'xwiki';
	}//end getId()

	/**
	 * Human-readable label shown in the sidebar tab / admin UI.
	 *
	 * @return string
	 */
	public function getLabel(): string {
		return $this->l10n->t('Articles');
	}//end getLabel()

	/**
	 * MDI icon name for the tab / widget.
	 *
	 * @return string
	 */
	public function getIcon(): string {
		return 'FileDocumentMultiple';
	}//end getIcon()

	/**
	 * Auth requirements descriptor. `type: 'external'` — credentials
	 * are configured on the OpenConnector source, not here. XWiki
	 * deployments use either HTTP Basic or OAuth2 depending on the
	 * customer; the source config picks one. OpenRegister's admin UI
	 * surfaces the source's auth status and links out to OpenConnector
	 * to configure it.
	 *
	 * @return array<string,mixed>
	 *
	 * @spec openspec/specs/integration-xwiki/spec.md
	 */
	public function authRequirements(): array {
		return [
			'type' => 'external',
			'configuredVia' => 'openconnector',
			'source' => self::SOURCE_ID,
			'supports' => ['basic', 'oauth2'],
		];
	}//end authRequirements()

	/**
	 * List the XWiki pages linked to an OR object.
	 *
	 * Delegates to the OpenConnector `xwiki` source, passing the
	 * object context as query params. The source returns the linked
	 * pages (already normalised across XWiki 5.x / 10.x / 14.x); this
	 * method shapes each row into the
	 * `{ id, title, breadcrumb, url, space, page }` contract the UI
	 * relies on (AD-3 breadcrumb). On any failure the
	 * ProviderUnavailableException bubbles to the controller, which
	 * translates it to a 503 with a cause the UI can render as a
	 * degraded/reconnect state — never a broken tab (AD-23).
	 *
	 * Tier-2: when a local link table is wired AND we have an object
	 * context, the linked pages are read from the link table first (so
	 * the sidebar tab survives even when the upstream xWiki is down),
	 * then enriched from remote xWiki best-effort. With no object context
	 * (the picker's "browse available pages" call) or no mapper (Tier-1
	 * wiring / unit test) we delegate straight to the router, preserving
	 * the original external-only behaviour + the 4-state auth UX (router
	 * raises ProviderUnavailableException which the controller maps to
	 * details.cause).
	 *
	 * @param string $register Register slug or numeric id.
	 * @param string $schema Schema slug or numeric id.
	 * @param string $objectId Object uuid.
	 * @param array<string,mixed> $filters Optional: `_search`, `_limit`, `_page`.
	 *
	 * @return array<int,array<string,mixed>>
	 *
	 * @spec openspec/specs/integration-xwiki/spec.md
	 */
	public function list(string $register, string $schema, string $objectId, array $filters = []): array {
		if ($this->xwikiLinkMapper !== null && $objectId !== '') {
			$scope = $this->contextQuery(register: $register, schema: $schema, objectId: $objectId, filters: []);
			return $this->listLinkedPages(mapper: $this->xwikiLinkMapper, scope: $scope);
		}

		$query = $this->contextQuery(register: $register, schema: $schema, objectId: $objectId, filters: $filters);
		return $this->listFromSource(query: $query);
	}//end list()

	/**
	 * List the locally-linked xWiki pages for an object, enriching each
	 * row from remote xWiki best-effort.
	 *
	 * @param XwikiLinkMapper     $mapper The Tier-2 link table.
	 * @param array<string,mixed> $scope  The `{register, schema, object}` context.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function listLinkedPages(XwikiLinkMapper $mapper, array $scope): array {
		$out = [];
		foreach ($mapper->findByObjectUuid($scope['object']) as $link) {
			$out[] = $this->linkedPageRow(link: $link, scope: $scope);
		}

		return $out;
	}//end listLinkedPages()

	/**
	 * One linked page: the cached link-table row, overridden by the live
	 * values from remote xWiki when it answers.
	 *
	 * The link-table row alone carries `{ reference, title, space, url }`
	 * so the tab renders even when the upstream is unreachable; when the
	 * source responds, the live title/space/url override the cached
	 * values. An upstream failure never breaks the list — the cached row
	 * is used as-is (AD-23).
	 *
	 * @param XwikiLink           $link  The link-table row.
	 * @param array<string,mixed> $scope The `{register, schema, object}` context.
	 *
	 * @return array<string,mixed>
	 */
	private function linkedPageRow(XwikiLink $link, array $scope): array {
		$row = $this->cachedLinkRow(link: $link);
		try {
			$enriched = $this->get(register: $scope['register'], schema: $scope['schema'], objectId: $scope['object'], entityId: $row['reference']);
		} catch (Throwable $e) {
			// Upstream unreachable — fall back to the cached row.
			return $this->normalizeRow(row: $row);
		}

		// Live values override the cached ones when present.
		return array_merge($row, array_filter($enriched, static fn ($v) => $v !== null && $v !== ''));
	}//end linkedPageRow()

	/**
	 * The row a link-table entry yields on its own, before enrichment.
	 *
	 * @param XwikiLink $link The link-table row.
	 *
	 * @return array<string,mixed>
	 */
	private function cachedLinkRow(XwikiLink $link): array {
		$reference = (string)$link->getPageReference();
		return [
			'id' => $reference,
			'reference' => $reference,
			'title' => (string)$link->getTitle(),
			'space' => (string)($link->getSpace() ?? ''),
			'url' => (string)($link->getUrl() ?? ''),
			'breadcrumb' => null,
		];
	}//end cachedLinkRow()

	/**
	 * Normalise the source's list response into a plain array of
	 * normalised rows; non-array rows are dropped.
	 *
	 * @param array<string,mixed> $response Decoded source response.
	 *
	 * @return array<int,array<string,mixed>>
	 *
	 * @SuppressWarnings(PHPMD.UnusedPrivateMethod) Called from
	 *     ConnectorEntityCallsTrait::listFromSource(), which PHPMD does not follow.
	 */
	private function normalizeList(array $response): array {
		$rows = array_filter($this->extractRows(response: $response), 'is_array');
		return array_values(array_map(fn (array $row): array => $this->normalizeRow(row: $row), $rows));
	}//end normalizeList()

	/**
	 * Pull the rows array out of whichever envelope key is present:
	 * `results` / `items` (a purpose-built OpenConnector source), or
	 * XWiki's own `pageSummaries` (space listing) / `searchResults`
	 * (search) when the source proxies XWiki's REST API directly. A bare
	 * list response is used as-is; an assoc response with none of those
	 * keys yields an empty list (rather than iterating the envelope's
	 * values as if they were rows).
	 *
	 * @param array<string,mixed> $response Decoded source response.
	 *
	 * @return array<mixed>
	 */
	private function extractRows(array $response): array {
		foreach (['results', 'items', 'pageSummaries', 'searchResults'] as $key) {
			if (isset($response[$key]) === true && is_array($response[$key]) === true) {
				return $response[$key];
			}
		}

		if (array_is_list($response) === true) {
			return $response;
		}

		return [];
	}//end extractRows()

	/**
	 * Shape one XWiki page row into the
	 * `{ id, title, breadcrumb, url, space, page, content }` contract
	 * the `@conduction/nextcloud-vue` xwiki tab + card expect. Unknown
	 * keys on the source row are preserved so the source can pass
	 * extra fields through without a provider change.
	 *
	 * `content` is the HTML-rendered content the UI truncates for the text
	 * preview (AD-1). Macros are NOT executed here — whatever the source
	 * returns is passed through; the UI strips to text + ~500 chars.
	 *
	 * @param array<string,mixed> $row One source row (or single-page response).
	 *
	 * @return array<string,mixed>
	 */
	private function normalizeRow(array $row): array {
		$identity = $this->pageIdentity(row: $row);
		return array_merge(
			$row,
			$identity,
			[
				'breadcrumb' => $this->breadcrumb(row: $row, identity: $identity),
				'url' => $this->pageUrl(row: $row),
				'content' => $row['content'] ?? $row['renderedContent'] ?? null,
			]
		);
	}//end normalizeRow()

	/**
	 * The `{ id, reference, title, space, page }` part of a row, in that order.
	 *
	 * @param array<string,mixed> $row One source row.
	 *
	 * @return array<string,string>
	 */
	private function pageIdentity(array $row): array {
		$reference = (string)($row['reference'] ?? $row['pageReference'] ?? $row['id'] ?? '');
		$page = (string)($row['page'] ?? $row['pageName'] ?? $row['name'] ?? '');
		return [
			'id' => $reference,
			'reference' => $reference,
			'title' => $this->firstNonEmpty(candidates: [$row['title'] ?? '', $page, $reference]),
			'space' => (string)($row['space'] ?? $row['spaceName'] ?? ''),
			'page' => $page,
		];
	}//end pageIdentity()

	/**
	 * The first candidate that is not an empty string, or '' when none is.
	 *
	 * @param array<int,mixed> $candidates Values in order of preference.
	 *
	 * @return string
	 */
	private function firstNonEmpty(array $candidates): string {
		foreach ($candidates as $candidate) {
			if ((string)$candidate !== '') {
				return (string)$candidate;
			}
		}

		return '';
	}//end firstNonEmpty()

	/**
	 * The source's breadcrumb, or a best-effort one built from the space
	 * segments and the title ("Space / Page") when it supplied none.
	 *
	 * @param array<string,mixed>  $row      One source row.
	 * @param array<string,string> $identity The row's identity (space, title).
	 *
	 * @return array<mixed>
	 */
	private function breadcrumb(array $row, array $identity): array {
		$breadcrumb = $row['breadcrumb'] ?? null;
		if (is_array($breadcrumb) === true && $breadcrumb !== []) {
			return $breadcrumb;
		}

		$spaceSegments = [];
		if ($identity['space'] !== '') {
			$spaceSegments = explode('.', $identity['space']);
		}

		return array_values(array_filter(array_merge($spaceSegments, [$identity['title']])));
	}//end breadcrumb()

	/**
	 * The page URL: `url` is the source-mapped field; `link` /
	 * `xwikiAbsoluteUrl` are fallbacks if the source mapping was left at
	 * XWiki's raw field name; finally the `rel=".../rel/page"` href from
	 * XWiki's REST `links` array (present on page summaries / search hits).
	 *
	 * @param array<string,mixed> $row One source row.
	 *
	 * @return string
	 */
	private function pageUrl(array $row): string {
		return (string)($row['url'] ?? $row['link'] ?? $row['xwikiAbsoluteUrl'] ?? $this->pageLinkHref(row: $row) ?? '');
	}//end pageUrl()

	/**
	 * Extract the page URL from an XWiki REST `links` array — the entry
	 * whose `rel` ends in `/rel/page`. Returns null when the row has no
	 * usable `links` array (e.g. a purpose-built source that maps the
	 * URL onto a flat field instead).
	 *
	 * @param array<string,mixed> $row One source row.
	 *
	 * @return string|null The page href, or null if not found.
	 */
	private function pageLinkHref(array $row): ?string {
		$links = $row['links'] ?? null;
		if (is_array($links) === false) {
			return null;
		}

		foreach ($links as $link) {
			if ($this->isPageLink(link: $link) === true) {
				return (string)$link['href'];
			}
		}

		return null;
	}//end pageLinkHref()

	/**
	 * Whether one `links` entry carries an href to the page (or page child).
	 *
	 * @param mixed $link One entry of the `links` array.
	 *
	 * @return bool
	 */
	private function isPageLink(mixed $link): bool {
		if (is_array($link) === false || isset($link['href']) === false) {
			return false;
		}

		$rel = (string)($link['rel'] ?? '');
		return str_ends_with($rel, '/rel/page') === true || str_ends_with($rel, '/rel/pagechild') === true;
	}//end isPageLink()
}//end class
