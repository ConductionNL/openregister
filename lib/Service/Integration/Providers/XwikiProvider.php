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
 * @link https://conduction.nl
 *
 * @spec openspec/changes/integration-xwiki/tasks.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Integration\Providers;

use OCA\OpenRegister\Service\Integration\AbstractIntegrationProvider;
use OCA\OpenRegister\Service\Integration\ExternalIntegrationRouter;
use OCP\App\IAppManager;
use OCP\IL10N;

/**
 * XWiki integration provider — external, OpenConnector-backed.
 */
class XwikiProvider extends AbstractIntegrationProvider
{

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
    private const REQUIRED_APP = 'openconnector';

    /**
     * Constructor.
     *
     * @param ExternalIntegrationRouter $router     External-call router.
     * @param IAppManager               $appManager NC app manager (isEnabled check).
     * @param IL10N                     $l10n       Localisation.
     *
     * @return void
     */
    public function __construct(
        private ExternalIntegrationRouter $router,
        private IAppManager $appManager,
        private IL10N $l10n,
    ) {
    }//end __construct()

    /**
     * Stable provider id (matches the PHP/JS registrations + the
     * OpenConnector source name).
     *
     * @return string
     */
    public function getId(): string
    {
        return 'xwiki';
    }//end getId()

    /**
     * Human-readable label shown in the sidebar tab / admin UI.
     *
     * @return string
     */
    public function getLabel(): string
    {
        return $this->l10n->t('Articles');
    }//end getLabel()

    /**
     * MDI icon name for the tab / widget.
     *
     * @return string
     */
    public function getIcon(): string
    {
        return 'FileDocumentMultiple';
    }//end getIcon()

    /**
     * Named group this integration belongs to (AD-16).
     *
     * @return string|null
     */
    public function getGroup(): ?string
    {
        return 'external';
    }//end getGroup()

    /**
     * Nextcloud app that must be installed for this integration to
     * function — OpenConnector carries the `xwiki` source + credentials.
     *
     * @return string|null
     */
    public function getRequiredApp(): ?string
    {
        return self::REQUIRED_APP;
    }//end getRequiredApp()

    /**
     * Storage strategy (AD-22) — `external`: no local link table; the
     * pairings live in OpenConnector and the pages in remote XWiki.
     *
     * @return string
     */
    public function getStorageStrategy(): string
    {
        return 'external';
    }//end getStorageStrategy()

    /**
     * OpenConnector source id this provider routes all CRUD through
     * (AD-4).
     *
     * @return string|null
     */
    public function getOpenConnectorSource(): ?string
    {
        return self::SOURCE_ID;
    }//end getOpenConnectorSource()

    /**
     * Whether the integration is available — true iff OpenConnector is
     * installed and enabled (it owns the `xwiki` source + credentials).
     * ExternalIntegrationRouter still degrades gracefully if the source
     * itself is missing or the remote XWiki is down.
     *
     * @return bool
     */
    public function isEnabled(): bool
    {
        return $this->appManager->isInstalled(self::REQUIRED_APP);
    }//end isEnabled()

    /**
     * Auth requirements descriptor. `type: 'external'` — credentials
     * are configured on the OpenConnector source, not here. XWiki
     * deployments use either HTTP Basic or OAuth2 depending on the
     * customer; the source config picks one. OpenRegister's admin UI
     * surfaces the source's auth status and links out to OpenConnector
     * to configure it.
     *
     * @return array<string,mixed>
     */
    public function authRequirements(): array
    {
        return [
            'type'          => 'external',
            'configuredVia' => 'openconnector',
            'source'        => self::SOURCE_ID,
            'supports'      => ['basic', 'oauth2'],
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
     * @param string              $register Register slug or numeric id.
     * @param string              $schema   Schema slug or numeric id.
     * @param string              $objectId Object uuid.
     * @param array<string,mixed> $filters  Optional: `_search`, `_limit`, `_page`.
     *
     * @return array<int,array<string,mixed>>
     */
    public function list(string $register, string $schema, string $objectId, array $filters=[]): array
    {
        $query    = $this->contextQuery(register: $register, schema: $schema, objectId: $objectId, filters: $filters);
        $response = $this->router->call(
            provider: $this,
            method: 'GET',
            path: '',
            options: ['query' => $query]
        );

        return $this->normalizeList(response: $response);
    }//end list()

    /**
     * Fetch a single linked XWiki page (with text preview).
     *
     * The OpenConnector source returns the HTML-rendered content under
     * `content` / `renderedContent`; the UI truncates it to ~500 chars
     * for the preview and never executes macros (AD-1). This method
     * just round-trips the row.
     *
     * @param string $register Register slug or numeric id.
     * @param string $schema   Schema slug or numeric id.
     * @param string $objectId Object uuid.
     * @param string $entityId Canonical XWiki page reference (e.g. `Space.Page`).
     *
     * @return array<string,mixed>
     */
    public function get(string $register, string $schema, string $objectId, string $entityId): array
    {
        $query    = $this->contextQuery(register: $register, schema: $schema, objectId: $objectId, filters: []);
        $response = $this->router->call(
            provider: $this,
            method: 'GET',
            path: rawurlencode($entityId),
            options: ['query' => $query]
        );

        return $this->normalizeRow(row: $response);
    }//end get()

    /**
     * Link an XWiki page to an OR object.
     *
     * `$payload` carries a `reference` — either a full XWiki URL or a
     * `space.page` path (AD-2). The OpenConnector source resolves it
     * to a canonical reference and records the pairing; the resolved
     * row is returned.
     *
     * @param string              $register Register slug or numeric id.
     * @param string              $schema   Schema slug or numeric id.
     * @param string              $objectId Object uuid.
     * @param array<string,mixed> $payload  At least `reference` (URL or `space.page`).
     *
     * @return array<string,mixed>
     */
    public function create(string $register, string $schema, string $objectId, array $payload): array
    {
        $body = $payload;
        $body['register'] = $register;
        $body['schema']   = $schema;
        $body['object']   = $objectId;

        $response = $this->router->call(provider: $this, method: 'POST', path: '', options: ['body' => $body]);

        return $this->normalizeRow(row: $response);
    }//end create()

    /**
     * Update a linked-page pairing (e.g. re-point to a different page).
     *
     * @param string              $register Register slug or numeric id.
     * @param string              $schema   Schema slug or numeric id.
     * @param string              $objectId Object uuid.
     * @param string              $entityId Canonical XWiki page reference.
     * @param array<string,mixed> $payload  Fields to update (e.g. `reference`).
     *
     * @return array<string,mixed>
     */
    public function update(string $register, string $schema, string $objectId, string $entityId, array $payload): array
    {
        $body = $payload;
        $body['register'] = $register;
        $body['schema']   = $schema;
        $body['object']   = $objectId;

        $response = $this->router->call(
            provider: $this,
            method: 'PUT',
            path: rawurlencode($entityId),
            options: ['body' => $body]
        );

        return $this->normalizeRow(row: $response);
    }//end update()

    /**
     * Unlink an XWiki page from an OR object. Removes the pairing in
     * OpenConnector — does NOT delete the page in XWiki.
     *
     * @param string $register Register slug or numeric id.
     * @param string $schema   Schema slug or numeric id.
     * @param string $objectId Object uuid.
     * @param string $entityId Canonical XWiki page reference.
     *
     * @return void
     */
    public function delete(string $register, string $schema, string $objectId, string $entityId): void
    {
        $this->router->call(
            provider: $this,
            method: 'DELETE',
            path: rawurlencode($entityId),
            options: ['query' => $this->contextQuery(register: $register, schema: $schema, objectId: $objectId, filters: [])]
        );
    }//end delete()

    /**
     * Health descriptor — defers to the router's probe so the admin
     * UI / OCS capabilities report the same status runtime callers
     * would see (OpenConnector down / source missing / upstream down /
     * ok).
     *
     * @return array{status: string, authStatus: string, message: ?string}
     */
    public function health(): array
    {
        return $this->router->probe(provider: $this);
    }//end health()

    /**
     * Build the standard `{register, schema, object, …filters}` query
     * the OpenConnector source expects as request context.
     *
     * @param string              $register Register slug or numeric id.
     * @param string              $schema   Schema slug or numeric id.
     * @param string              $objectId Object uuid.
     * @param array<string,mixed> $filters  Caller filters merged in (search/limit/page).
     *
     * @return array<string,mixed>
     */
    private function contextQuery(string $register, string $schema, string $objectId, array $filters): array
    {
        return array_merge(
            $filters,
            ['register' => $register, 'schema' => $schema, 'object' => $objectId]
        );
    }//end contextQuery()

    /**
     * Normalise the source's list response (which may be a bare array
     * of rows, or `{ results: [...] }`, or `{ items: [...] }`) into a
     * plain array of normalised rows.
     *
     * @param array<string,mixed> $response Decoded source response.
     *
     * @return array<int,array<string,mixed>>
     */
    private function normalizeList(array $response): array
    {
        $rows = $response;
        if (isset($response['results']) === true && is_array($response['results']) === true) {
            $rows = $response['results'];
        } else if (isset($response['items']) === true && is_array($response['items']) === true) {
            $rows = $response['items'];
        }

        $out = [];
        foreach ((array) $rows as $row) {
            if (is_array($row) === true) {
                $out[] = $this->normalizeRow(row: $row);
            }
        }

        return $out;
    }//end normalizeList()

    /**
     * Shape one XWiki page row into the
     * `{ id, title, breadcrumb, url, space, page, content }` contract
     * the `@conduction/nextcloud-vue` xwiki tab + card expect. Unknown
     * keys on the source row are preserved so the source can pass
     * extra fields through without a provider change.
     *
     * @param array<string,mixed> $row One source row (or single-page response).
     *
     * @return array<string,mixed>
     */
    private function normalizeRow(array $row): array
    {
        $reference = (string) ($row['reference'] ?? $row['pageReference'] ?? $row['id'] ?? '');
        $space     = (string) ($row['space'] ?? $row['spaceName'] ?? '');
        $page      = (string) ($row['page'] ?? $row['pageName'] ?? $row['name'] ?? '');
        $title     = (string) ($row['title'] ?? $page ?? $reference);

        $breadcrumb = $row['breadcrumb'] ?? null;
        if (is_array($breadcrumb) === false || $breadcrumb === []) {
            // Best-effort breadcrumb from the reference if the source
            // didn't supply one — "Space / Page" or just the reference.
            $breadcrumb = array_values(
                    array_filter(
                    array_merge(
                $space !== '' ? explode('.', $space) : [],
                [$title]
                    )
                    )
                    );
        }

        return array_merge(
            $row,
            [
                'id'         => $reference,
                'reference'  => $reference,
                'title'      => $title,
                'space'      => $space,
                'page'       => $page,
                'breadcrumb' => $breadcrumb,
                // `url` is the source-mapped field; `link` /
                // `xwikiAbsoluteUrl` are fallbacks if the source
                // mapping was left at XWiki's raw field name.
                'url'        => (string) ($row['url'] ?? $row['link'] ?? $row['xwikiAbsoluteUrl'] ?? ''),
                // The HTML-rendered content the UI truncates for the
                // text preview (AD-1). Macros are NOT executed here —
                // whatever the source returns is passed through; the
                // UI strips to text + ~500 chars.
                'content'    => $row['content'] ?? $row['renderedContent'] ?? null,
            ]
        );
    }//end normalizeRow()
}//end class
