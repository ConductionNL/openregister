<?php

/**
 * XWiki Integration Provider
 *
 * Integrates XWiki pages with OpenRegister objects via the pluggable integration
 * registry. All CRUD is routed through ExternalIntegrationRouter to the OpenConnector
 * 'xwiki' source — this provider carries no HTTP client and no credentials.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Integration\Providers
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/integration-xwiki/tasks.md#task-1
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Integration\Providers;

use OCA\OpenRegister\Service\Integration\ExternalIntegrationRouter;
use OCA\OpenRegister\Service\Integration\IntegrationProviderInterface;
use OCP\App\IAppManager;
use Psr\Log\LoggerInterface;

/**
 * XWiki integration provider.
 *
 * Declares storage='external', id='xwiki', group='external'.
 * Requires the 'openconnector' NC app (which carries the xwiki source + credentials).
 * Row shape: {id, reference, title, space, page, breadcrumb, url, content}.
 *
 * AD-1: text preview strips HTML tags + macro markup — macros are never executed in NC.
 * AD-3: breadcrumb is derived from hierarchy.items when available, falling back to space+title.
 */
class XwikiProvider implements IntegrationProviderInterface
{

    /**
     * OpenConnector source identifier for XWiki.
     *
     * @var string
     */
    private const SOURCE = 'xwiki';

    /**
     * Required Nextcloud app for this integration.
     *
     * @var string
     */
    private const REQUIRED_APP = 'openconnector';

    /**
     * Constructor.
     *
     * @param ExternalIntegrationRouter $router     External integration router.
     * @param IAppManager               $appManager App manager for isEnabled check.
     * @param LoggerInterface           $logger     Logger.
     *
     * @return void
     */
    public function __construct(
        private readonly ExternalIntegrationRouter $router,
        private readonly IAppManager $appManager,
        private readonly LoggerInterface $logger
    ) {
    }//end __construct()

    /**
     * Unique identifier for this integration.
     *
     * @return string Always 'xwiki'.
     *
     * @spec openspec/changes/integration-xwiki/tasks.md#task-1
     */
    public function getId(): string
    {
        return 'xwiki';
    }//end getId()

    /**
     * Human-readable label shown in the admin Integrations UI.
     *
     * @return string 'Articles'.
     */
    public function getLabel(): string
    {
        return 'Articles';
    }//end getLabel()

    /**
     * Icon identifier (Material Design or NC icon name).
     *
     * @return string Icon name.
     */
    public function getIcon(): string
    {
        return 'FileDocumentMultiple';
    }//end getIcon()

    /**
     * Category group for grouping in the integrations list.
     *
     * @return string Always 'external'.
     */
    public function getGroup(): string
    {
        return 'external';
    }//end getGroup()

    /**
     * NC app that must be installed for this integration.
     *
     * @return string 'openconnector'.
     */
    public function getRequiredApp(): ?string
    {
        return self::REQUIRED_APP;
    }//end getRequiredApp()

    /**
     * Storage strategy for linked objects.
     *
     * @return string Always 'external'.
     */
    public function getStorageStrategy(): string
    {
        return 'external';
    }//end getStorageStrategy()

    /**
     * Source identifier on the OpenConnector side.
     *
     * @return string Always 'xwiki'.
     */
    public function getOpenConnectorSource(): ?string
    {
        return self::SOURCE;
    }//end getOpenConnectorSource()

    /**
     * Authentication requirements for this integration.
     *
     * Auth is configured on the OpenConnector source; this provider only
     * declares what auth mechanisms the xwiki source supports.
     *
     * @return array{type: string, configuredVia: string, source: string, supports: list<string>}
     *
     * @spec openspec/changes/integration-xwiki/tasks.md#task-2
     */
    public function authRequirements(): array
    {
        return [
            'type'          => 'external',
            'configuredVia' => 'openconnector',
            'source'        => self::SOURCE,
            'supports'      => ['basic', 'oauth2'],
        ];
    }//end authRequirements()

    /**
     * Whether this integration is currently enabled and usable.
     *
     * Mirrors IAppManager::isInstalled('openconnector') — the actual availability
     * of the xwiki source is checked lazily on first use.
     *
     * @return bool True when openconnector is installed.
     *
     * @spec openspec/changes/integration-xwiki/tasks.md#task-1
     */
    public function isEnabled(): bool
    {
        return $this->appManager->isInstalled(appId: self::REQUIRED_APP);
    }//end isEnabled()

    /**
     * List linked XWiki pages for an OR object.
     *
     * @param string $register The register identifier.
     * @param string $schema   The schema identifier.
     * @param string $objectId The object ID.
     * @param array  $params   Optional pagination / filter parameters.
     *
     * @return array{results: list<array<string,mixed>>, total: int}
     *
     * @spec openspec/changes/integration-xwiki/tasks.md#task-3
     */
    public function list(string $register, string $schema, string $objectId, array $params=[]): array
    {
        $raw = $this->router->call(
            source: self::SOURCE,
            operation: 'list',
            context: [
                'register' => $register,
                'schema'   => $schema,
                'object'   => $objectId,
                'params'   => $params,
            ]
        );

        $results = $this->normalizeList(rows: $raw['results'] ?? $raw);

        return [
            'results' => $results,
            'total'   => $raw['total'] ?? count($results),
        ];
    }//end list()

    /**
     * Retrieve a single linked XWiki page.
     *
     * Returns null when not found or when the router call fails.
     *
     * @param string $register The register identifier.
     * @param string $schema   The schema identifier.
     * @param string $objectId The object ID.
     * @param string $linkedId The linked item ID.
     *
     * @return array<string,mixed>|null Normalised row or null.
     *
     * @spec openspec/changes/integration-xwiki/tasks.md#task-3
     */
    public function get(string $register, string $schema, string $objectId, string $linkedId): ?array
    {
        try {
            $raw = $this->router->call(
                source: self::SOURCE,
                operation: 'get',
                context: [
                    'register' => $register,
                    'schema'   => $schema,
                    'object'   => $objectId,
                    'id'       => $linkedId,
                ]
            );

            if (empty($raw) === true) {
                return null;
            }

            return $this->normalizeRow(row: $raw);
        } catch (\Exception $e) {
            $this->logger->warning(
                message: '[XwikiProvider] get() failed',
                context: ['linkedId' => $linkedId, 'message' => $e->getMessage()]
            );
            return null;
        }//end try
    }//end get()

    /**
     * Link (create) a new XWiki page reference to an OR object.
     *
     * @param string $register The register identifier.
     * @param string $schema   The schema identifier.
     * @param string $objectId The object ID.
     * @param array  $data     Link data: {reference: "<full URL or Space.Page path>"}.
     *
     * @return array<string,mixed> The created link row.
     *
     * @spec openspec/changes/integration-xwiki/tasks.md#task-3
     */
    public function create(string $register, string $schema, string $objectId, array $data): array
    {
        $raw = $this->router->call(
            source: self::SOURCE,
            operation: 'create',
            context: [
                'register' => $register,
                'schema'   => $schema,
                'object'   => $objectId,
                'data'     => $data,
            ]
        );

        return $this->normalizeRow(row: $raw);
    }//end create()

    /**
     * Update link metadata for a linked XWiki page.
     *
     * @param string $register The register identifier.
     * @param string $schema   The schema identifier.
     * @param string $objectId The object ID.
     * @param string $linkedId The linked item ID.
     * @param array  $data     Updated data.
     *
     * @return array<string,mixed> The updated row.
     *
     * @spec openspec/changes/integration-xwiki/tasks.md#task-3
     */
    public function update(string $register, string $schema, string $objectId, string $linkedId, array $data): array
    {
        $raw = $this->router->call(
            source: self::SOURCE,
            operation: 'update',
            context: [
                'register' => $register,
                'schema'   => $schema,
                'object'   => $objectId,
                'id'       => $linkedId,
                'data'     => $data,
            ]
        );

        return $this->normalizeRow(row: $raw);
    }//end update()

    /**
     * Unlink (delete) a linked XWiki page.
     *
     * Removes the OR sub-resource pairing only — never deletes the page in XWiki.
     *
     * @param string $register The register identifier.
     * @param string $schema   The schema identifier.
     * @param string $objectId The object ID.
     * @param string $linkedId The linked item ID.
     *
     * @return void
     *
     * @spec openspec/changes/integration-xwiki/tasks.md#task-3
     */
    public function delete(string $register, string $schema, string $objectId, string $linkedId): void
    {
        $this->router->call(
            source: self::SOURCE,
            operation: 'delete',
            context: [
                'register' => $register,
                'schema'   => $schema,
                'object'   => $objectId,
                'id'       => $linkedId,
            ]
        );
    }//end delete()

    /**
     * Check the health / reachability of the XWiki source via OpenConnector.
     *
     * @return array{status: string, message: string}
     *
     * @spec openspec/changes/integration-xwiki/tasks.md#task-3
     */
    public function health(): array
    {
        return $this->router->probe(source: self::SOURCE);
    }//end health()

    /**
     * Normalise a raw OpenConnector row to the canonical XWiki shape.
     *
     * Canonical shape: {id, reference, title, space, page, breadcrumb, url, content}.
     * AD-3: breadcrumb from hierarchy.items when available; falls back to space name.
     * AD-1: content is the rendered plain-text body — macros stripped by OpenConnector.
     *
     * @param array<string,mixed> $row Raw row from OpenConnector source.
     *
     * @return array<string,mixed> Normalised row.
     *
     * @spec openspec/changes/integration-xwiki/tasks.md#task-3
     */
    public function normalizeRow(array $row): array
    {
        $id        = (string) ($row['id'] ?? $row['uuid'] ?? '');
        $reference = (string) ($row['reference'] ?? $row['fullName'] ?? '');
        $title     = (string) ($row['title'] ?? $row['name'] ?? '');
        $space     = (string) ($row['space'] ?? $row['spaceName'] ?? '');
        $page      = (string) ($row['page'] ?? $row['name'] ?? $title);
        $url       = (string) ($row['url'] ?? $row['xwikiAbsoluteUrl'] ?? '');

        // Derive content from renderedContent alias, falling back to raw content.
        $content = (string) ($row['renderedContent'] ?? $row['content'] ?? '');

        // Derive breadcrumb from hierarchy items, or fall back to space+title.
        $breadcrumb = $this->deriveBreadcrumb(row: $row, space: $space, title: $title);

        return [
            'id'         => $id,
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
     * Normalise a list of raw OpenConnector rows.
     *
     * @param list<array<string,mixed>> $rows Raw rows from source.
     *
     * @return list<array<string,mixed>> Normalised rows.
     */
    public function normalizeList(array $rows): array
    {
        return array_values(
            array_map(
                fn(array $row) => $this->normalizeRow(row: $row),
                $rows
            )
        );
    }//end normalizeList()

    /**
     * Derive a breadcrumb string from the raw row.
     *
     * Prefers hierarchy.items[].label (native XWiki breadcrumb) and drops the last
     * element (which is the page title itself). Falls back to the space name.
     *
     * @param array<string,mixed> $row   Raw row.
     * @param string              $space Space name.
     * @param string              $title Page title.
     *
     * @return string Breadcrumb string.
     */
    private function deriveBreadcrumb(array $row, string $space, string $title): string
    {
        // Use hierarchy.items when the OpenConnector source provides it.
        $hierarchy = $row['hierarchy'] ?? [];
        $items     = is_array(value: $hierarchy) === true ? ($hierarchy['items'] ?? []) : [];

        if (empty($items) === false) {
            $labels = array_map(
                static fn(array $item): string => (string) ($item['label'] ?? ''),
                array_filter(array: $items, callback: static fn($item) => is_array(value: $item))
            );

            // Drop trailing empty labels and the last item (it is the page title).
            $labels = array_values(array_filter(array: $labels));
            if (count($labels) > 1) {
                array_pop(array: $labels);
            }

            if (empty($labels) === false) {
                return implode(separator: ' / ', array: $labels);
            }
        }

        // Coarse fallback: space name (omit title from breadcrumb).
        if ($space !== '') {
            return $space;
        }

        return $title;
    }//end deriveBreadcrumb()
}//end class
