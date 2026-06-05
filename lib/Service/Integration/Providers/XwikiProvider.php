<?php

/**
 * XwikiProvider
 *
 * Integration provider for XWiki (external knowledge platform).
 * Routes all CRUD through ExternalIntegrationRouter to the OpenConnector
 * 'xwiki' source (ADR-019 AD-4 / integration-xwiki spec).
 *
 * @category Provider
 * @package  OCA\OpenRegister\Service\Integration\Providers
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/integration-xwiki/tasks.md#task-1.1
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Integration\Providers;

use OCA\OpenRegister\Service\Integration\ExternalIntegrationRouter;
use OCA\OpenRegister\Service\Integration\IntegrationProvider;
use OCP\App\IAppManager;
use Psr\Log\LoggerInterface;

/**
 * XWiki integration provider — links XWiki pages to OR objects.
 *
 * Provider metadata:
 *   id='xwiki', label='Articles', icon='FileDocumentMultiple',
 *   group='external', requiredApp='openconnector', storage='external'.
 *
 * All CRUD is delegated to ExternalIntegrationRouter which dispatches
 * to the OpenConnector 'xwiki' source. The provider carries no HTTP
 * client and no credentials (ADR-019 AD-4).
 *
 * normalizeRow() shapes responses to:
 *   { id, reference, title, space, page, breadcrumb, url, content }
 *
 * AD-1: Content field carries already-rendered plain text (HTML tags
 * stripped to ~500 chars); macros are never executed in Nextcloud.
 *
 * AD-3: Breadcrumb shown in tab rows (Wiki / Dept / Subspace / Title).
 *
 * @category Provider
 * @package  OCA\OpenRegister\Service\Integration\Providers
 *
 * @spec openspec/changes/integration-xwiki/tasks.md#task-1.1
 */
class XwikiProvider implements IntegrationProvider
{

    /**
     * OpenConnector source id for XWiki.
     */
    private const SOURCE_ID = 'xwiki';

    /**
     * Constructor.
     *
     * @param ExternalIntegrationRouter $router     External integration router
     * @param IAppManager               $appManager NC app manager
     * @param LoggerInterface           $logger     Logger
     *
     * @spec openspec/changes/integration-xwiki/tasks.md#task-1.1
     */
    public function __construct(
        private readonly ExternalIntegrationRouter $router,
        private readonly IAppManager $appManager,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Returns 'xwiki' as the unique integration identifier.
     *
     * @return string Provider id
     *
     * @spec openspec/changes/integration-xwiki/tasks.md#task-1.1
     */
    public function getId(): string
    {
        return 'xwiki';
    }//end getId()

    /**
     * Returns 'Articles' as the human-readable label.
     *
     * @return string Provider label
     *
     * @spec openspec/changes/integration-xwiki/tasks.md#task-1.1
     */
    public function getLabel(): string
    {
        return 'Articles';
    }//end getLabel()

    /**
     * Returns the Material Design icon name for this provider.
     *
     * @return string Icon name
     *
     * @spec openspec/changes/integration-xwiki/tasks.md#task-1.1
     */
    public function getIcon(): string
    {
        return 'FileDocumentMultiple';
    }//end getIcon()

    /**
     * Returns 'external' as the provider group.
     *
     * @return string Provider group
     *
     * @spec openspec/changes/integration-xwiki/tasks.md#task-1.1
     */
    public function getGroup(): string
    {
        return 'external';
    }//end getGroup()

    /**
     * Returns 'openconnector' — the NC app that carries the xwiki source and credentials.
     *
     * @return string Required app id
     *
     * @spec openspec/changes/integration-xwiki/tasks.md#task-1.1
     */
    public function getRequiredApp(): ?string
    {
        return 'openconnector';
    }//end getRequiredApp()

    /**
     * Returns 'external' — CRUD is routed through OpenConnector, not stored in OR DB.
     *
     * @return string Storage strategy
     *
     * @spec openspec/changes/integration-xwiki/tasks.md#task-1.1
     */
    public function getStorageStrategy(): string
    {
        return 'external';
    }//end getStorageStrategy()

    /**
     * Returns 'xwiki' as the OpenConnector source id.
     *
     * @return string Source id
     *
     * @spec openspec/changes/integration-xwiki/tasks.md#task-1.1
     */
    public function getOpenConnectorSource(): ?string
    {
        return self::SOURCE_ID;
    }//end getOpenConnectorSource()

    /**
     * Returns auth requirements descriptor for XWiki via OpenConnector.
     *
     * Auth is configured on the OpenConnector source; OR only surfaces status.
     * Supports both Basic auth (older XWiki versions) and OAuth 2.0.
     *
     * @return array Auth requirements descriptor
     *
     * @spec openspec/changes/integration-xwiki/tasks.md#task-1.2
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
     * Returns true when the openconnector app is installed.
     *
     * Provider is enabled when openconnector is installed (it carries the
     * xwiki source and credentials). ExternalIntegrationRouter degrades
     * gracefully when openconnector is absent.
     *
     * @return bool Whether the provider is enabled
     *
     * @spec openspec/changes/integration-xwiki/tasks.md#task-1.1
     */
    public function isEnabled(): bool
    {
        return $this->appManager->isInstalled(appId: 'openconnector');
    }//end isEnabled()

    /**
     * Returns null — XWiki's own ACLs govern access transitively via OpenConnector.
     *
     * @return string|null Required NC permission, or null
     *
     * @spec openspec/changes/integration-xwiki/tasks.md#task-1.1
     */
    public function requiresPermission(): ?string
    {
        return null;
    }//end requiresPermission()

    /**
     * List linked XWiki pages for an object.
     *
     * @param string $register Register slug
     * @param string $schema   Schema slug
     * @param string $objectId Object UUID
     * @param array  $params   Pagination / filter params
     *
     * @return array Normalised page rows
     *
     * @spec openspec/changes/integration-xwiki/tasks.md#task-1.3
     */
    public function list(string $register, string $schema, string $objectId, array $params=[]): array
    {
        $rows = $this->router->call(
            method: 'list',
            source: self::SOURCE_ID,
            register: $register,
            schema: $schema,
            objectId: $objectId,
            id: null,
            data: $params,
        );

        return array_map(
            callback: fn(array $row) => $this->normalizeRow(row: $row),
            array: $rows,
        );
    }//end list()

    /**
     * Get a single linked XWiki page.
     *
     * @param string $register Register slug
     * @param string $schema   Schema slug
     * @param string $objectId Object UUID
     * @param string $id       Page item id
     *
     * @return array|null Normalised page row or null when not found
     *
     * @spec openspec/changes/integration-xwiki/tasks.md#task-1.3
     */
    public function get(string $register, string $schema, string $objectId, string $id): ?array
    {
        $row = $this->router->call(
            method: 'get',
            source: self::SOURCE_ID,
            register: $register,
            schema: $schema,
            objectId: $objectId,
            id: $id,
        );

        if (empty($row) === true) {
            return null;
        }

        return $this->normalizeRow(row: $row);
    }//end get()

    /**
     * Link a XWiki page to an object.
     *
     * AD-2: Accepts a full XWiki URL or a direct Space.Page path in $data['reference'].
     * URL resolution is performed server-side by the OpenConnector source.
     *
     * @param string $register Register slug
     * @param string $schema   Schema slug
     * @param string $objectId Object UUID
     * @param array  $data     Link data (must include 'reference')
     *
     * @return array Normalised created link record
     *
     * @spec openspec/changes/integration-xwiki/tasks.md#task-1.3
     */
    public function create(string $register, string $schema, string $objectId, array $data): array
    {
        $row = $this->router->call(
            method: 'create',
            source: self::SOURCE_ID,
            register: $register,
            schema: $schema,
            objectId: $objectId,
            id: null,
            data: $data,
        );

        return $this->normalizeRow(row: $row);
    }//end create()

    /**
     * Update a linked XWiki page record.
     *
     * @param string $register Register slug
     * @param string $schema   Schema slug
     * @param string $objectId Object UUID
     * @param string $id       Page item id
     * @param array  $data     Updated data
     *
     * @return array Normalised updated link record
     *
     * @spec openspec/changes/integration-xwiki/tasks.md#task-1.3
     */
    public function update(
        string $register,
        string $schema,
        string $objectId,
        string $id,
        array $data,
    ): array {
        $row = $this->router->call(
            method: 'update',
            source: self::SOURCE_ID,
            register: $register,
            schema: $schema,
            objectId: $objectId,
            id: $id,
            data: $data,
        );

        return $this->normalizeRow(row: $row);
    }//end update()

    /**
     * Remove the OR sub-resource pairing only — never deletes in XWiki.
     *
     * @param string $register Register slug
     * @param string $schema   Schema slug
     * @param string $objectId Object UUID
     * @param string $id       Page item id
     *
     * @return void
     *
     * @spec openspec/changes/integration-xwiki/tasks.md#task-1.3
     */
    public function delete(string $register, string $schema, string $objectId, string $id): void
    {
        $this->router->call(
            method: 'delete',
            source: self::SOURCE_ID,
            register: $register,
            schema: $schema,
            objectId: $objectId,
            id: $id,
        );
    }//end delete()

    /**
     * Probe the XWiki OpenConnector source for availability and auth status.
     *
     * @return array{status: string, authExpired?: bool, reason?: string} Health status
     *
     * @spec openspec/changes/integration-xwiki/tasks.md#task-1.3
     */
    public function health(): array
    {
        return $this->router->probe(source: self::SOURCE_ID);
    }//end health()

    /**
     * Shape a raw page row to the canonical integration shape.
     *
     * Output: { id, reference, title, space, page, breadcrumb, url, content }.
     *
     * AD-1: content carries plain text (HTML stripped, ~500 chars).
     * AD-3: breadcrumb derives from hierarchy.items[].label when present;
     *       falls back to "{space} / {title}" constructed from other fields.
     *
     * @param array $row Raw row from provider or router
     *
     * @return array Normalised row
     *
     * @spec openspec/changes/integration-xwiki/tasks.md#task-1.3
     */
    public function normalizeRow(array $row): array
    {
        $title     = (string) ($row['title'] ?? $row['name'] ?? '');
        $space     = (string) ($row['space'] ?? $row['spaceName'] ?? '');
        $page      = (string) ($row['page'] ?? $row['pageName'] ?? $title);
        $url       = (string) ($row['url'] ?? $row['pageUrl'] ?? '');
        $reference = (string) ($row['reference'] ?? $row['fullName'] ?? $row['id'] ?? '');

        $breadcrumb = $this->extractBreadcrumb(
            row: $row,
            space: $space,
            title: $title,
        );

        // AD-1: prefer already-rendered plain text; fall back to raw content.
        $rawContent = (string) ($row['renderedContent'] ?? $row['content'] ?? '');
        $content    = $this->sanitizeContent(raw: $rawContent);

        return [
            'id'         => (string) ($row['id'] ?? $row['pageId'] ?? ''),
            'reference'  => $reference,
            'title'      => $title,
            'space'      => $space,
            'page'       => $page,
            'breadcrumb' => $breadcrumb,
            'url'        => $url,
            'content'    => $content,
        ];
    }//end normalizeRow()

    /**
     * Extract or derive breadcrumb from a raw page row.
     *
     * AD-3: Native breadcrumb from hierarchy.items[].label is preferred.
     * Falls back to "{space} / {title}" for older XWiki versions.
     *
     * @param array  $row   Raw page row
     * @param string $space Space name
     * @param string $title Page title
     *
     * @return string Human-readable breadcrumb path
     *
     * @spec openspec/changes/integration-xwiki/tasks.md#task-1.3
     */
    private function extractBreadcrumb(array $row, string $space, string $title): string
    {
        // Native breadcrumb from XWiki REST hierarchy.items[].label.
        $hierarchy = $row['hierarchy'] ?? [];
        $items     = is_array(value: $hierarchy) === true ? ($hierarchy['items'] ?? []) : [];

        if (empty($items) === false) {
            $labels = array_map(
                callback: static fn(array $item) => (string) ($item['label'] ?? ''),
                array: $items,
            );
            $labels = array_filter(array: $labels);
            if (empty($labels) === false) {
                return implode(separator: ' / ', array: array_values(array: $labels));
            }
        }

        // Flat breadcrumb field (some OpenConnector mappings surface this directly).
        if (isset($row['breadcrumb']) === true && empty($row['breadcrumb']) === false) {
            return (string) $row['breadcrumb'];
        }

        // Coarse fallback: space / title.
        if ($space !== '' && $title !== '') {
            return $space.' / '.$title;
        }

        return $title;
    }//end extractBreadcrumb()

    /**
     * Strip HTML and truncate to ~500 chars for safe text preview (AD-1).
     *
     * Strips all HTML tags (including script/style bodies) to plain text.
     * Macro markup ({{velocity}}…) is treated as inert text after stripping.
     * Content is never injected into the DOM.
     *
     * @param string $raw Raw HTML or macro content from XWiki renderer
     *
     * @return string Plain text, up to 500 chars
     *
     * @spec openspec/changes/integration-xwiki/tasks.md#task-1.3
     */
    private function sanitizeContent(string $raw): string
    {
        if ($raw === '') {
            return '';
        }

        // Remove script and style tag bodies (including inline content).
        $stripped = preg_replace(
            pattern: '#<(script|style)[^>]*>.*?<\/\1>#si',
            replacement: '',
            subject: $raw,
        ) ?? '';

        // Strip remaining HTML tags to plain text.
        $text = strip_tags(string: $stripped);

        // Collapse whitespace.
        $text = (string) preg_replace(
            pattern: '/\s+/',
            replacement: ' ',
            subject: trim(string: $text),
        );

        // Truncate to 500 chars without breaking words.
        if (mb_strlen(string: $text) <= 500) {
            return $text;
        }

        return mb_substr(string: $text, start: 0, length: 497).'...';
    }//end sanitizeContent()
}//end class
