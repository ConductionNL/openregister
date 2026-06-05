<?php

/**
 * OpenRegister XwikiProvider
 *
 * Integration provider for XWiki — links XWiki pages to OpenRegister objects
 * through external routing via OpenConnector. All CRUD is delegated to
 * ExternalIntegrationRouter; the provider carries no HTTP client and no
 * credentials of its own.
 *
 * @category Integration
 * @package  OCA\OpenRegister\Service\Integration\Providers
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/integration-xwiki/tasks.md#task-1
 * @spec openspec/changes/integration-xwiki/tasks.md#task-2
 * @spec openspec/changes/integration-xwiki/tasks.md#task-3
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Integration\Providers;

use OCA\OpenRegister\Service\Integration\ExternalIntegrationRouter;
use OCA\OpenRegister\Service\Integration\IntegrationProviderInterface;
use OCP\App\IAppManager;
use Psr\Log\LoggerInterface;

/**
 * XWiki integration provider for OpenRegister.
 *
 * Declares storage='external' and delegates all CRUD to ExternalIntegrationRouter
 * with source='xwiki'. Auth is configured on the OpenConnector source (basic or
 * OAuth2 depending on the XWiki version); this provider only declares what is
 * supported so the admin UI can surface the right configuration flow.
 *
 * Tab rows are shaped to: { id, reference, title, space, page, breadcrumb, url, content }.
 * The breadcrumb is derived from hierarchy.items[].label when provided by the
 * OpenConnector source, falling back to "$space / $title" when absent.
 */
class XwikiProvider implements IntegrationProviderInterface
{
    private const PROVIDER_ID          = 'xwiki';
    private const OPENCONNECTOR_SOURCE = 'xwiki';
    private const REQUIRED_APP         = 'openconnector';

    /**
     * Constructor for XwikiProvider.
     *
     * @param ExternalIntegrationRouter $router     External CRUD router
     * @param IAppManager               $appManager Nextcloud app manager
     * @param LoggerInterface           $logger     Logger
     */
    public function __construct(
        private readonly ExternalIntegrationRouter $router,
        private readonly IAppManager $appManager,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * {@inheritdoc}
     *
     * @return string
     *
     * @spec openspec/changes/integration-xwiki/tasks.md#task-1
     */
    public function getId(): string
    {
        return self::PROVIDER_ID;
    }//end getId()

    /**
     * {@inheritdoc}
     *
     * @return string
     *
     * @spec openspec/changes/integration-xwiki/tasks.md#task-1
     */
    public function getLabel(): string
    {
        return 'Articles';
    }//end getLabel()

    /**
     * {@inheritdoc}
     *
     * @return string
     *
     * @spec openspec/changes/integration-xwiki/tasks.md#task-1
     */
    public function getIcon(): string
    {
        return 'FileDocumentMultiple';
    }//end getIcon()

    /**
     * {@inheritdoc}
     *
     * @return string
     *
     * @spec openspec/changes/integration-xwiki/tasks.md#task-1
     */
    public function getGroup(): string
    {
        return 'external';
    }//end getGroup()

    /**
     * {@inheritdoc}
     *
     * @return string|null
     *
     * @spec openspec/changes/integration-xwiki/tasks.md#task-1
     */
    public function getRequiredApp(): ?string
    {
        return self::REQUIRED_APP;
    }//end getRequiredApp()

    /**
     * {@inheritdoc}
     *
     * @return string
     *
     * @spec openspec/changes/integration-xwiki/tasks.md#task-1
     */
    public function getStorageStrategy(): string
    {
        return 'external';
    }//end getStorageStrategy()

    /**
     * {@inheritdoc}
     *
     * @return string|null
     *
     * @spec openspec/changes/integration-xwiki/tasks.md#task-1
     */
    public function getOpenConnectorSource(): ?string
    {
        return self::OPENCONNECTOR_SOURCE;
    }//end getOpenConnectorSource()

    /**
     * {@inheritdoc}
     *
     * Returns true when the openconnector app is installed. ExternalIntegrationRouter
     * degrades gracefully when OpenConnector is present but no source is configured.
     *
     * @return bool
     *
     * @spec openspec/changes/integration-xwiki/tasks.md#task-1
     */
    public function isEnabled(): bool
    {
        return $this->appManager->isInstalled(self::REQUIRED_APP);
    }//end isEnabled()

    /**
     * {@inheritdoc}
     *
     * Auth is handled entirely on the OpenConnector source. Both basic auth and
     * OAuth2 are supported depending on the customer's XWiki version.
     *
     * @return array<string, mixed>
     *
     * @spec openspec/changes/integration-xwiki/tasks.md#task-2
     */
    public function authRequirements(): array
    {
        return [
            'type'          => 'external',
            'configuredVia' => 'openconnector',
            'source'        => self::OPENCONNECTOR_SOURCE,
            'supports'      => ['basic', 'oauth2'],
        ];
    }//end authRequirements()

    /**
     * {@inheritdoc}
     *
     * @param string               $register Object register slug
     * @param string               $schema   Object schema slug
     * @param string               $objectId Object identifier
     * @param array<string, mixed> $params   Optional filter/pagination params
     *
     * @return array<int, array<string, mixed>>
     *
     * @spec openspec/changes/integration-xwiki/tasks.md#task-3
     */
    public function list(string $register, string $schema, string $objectId, array $params=[]): array
    {
        $result = $this->router->call(
            source: self::OPENCONNECTOR_SOURCE,
            action: 'list',
            register: $register,
            schema: $schema,
            objectId: $objectId,
            payload: $params,
        );

        $items = $result['result'] ?? [];

        return array_map(fn (array $row): array => $this->normalizeRow(row: $row), $items);
    }//end list()

    /**
     * {@inheritdoc}
     *
     * @param string $register Object register slug
     * @param string $schema   Object schema slug
     * @param string $objectId Object identifier
     * @param string $itemId   Linked item identifier
     *
     * @return array<string, mixed>
     *
     * @spec openspec/changes/integration-xwiki/tasks.md#task-3
     */
    public function get(string $register, string $schema, string $objectId, string $itemId): array
    {
        $result = $this->router->call(
            source: self::OPENCONNECTOR_SOURCE,
            action: 'get',
            register: $register,
            schema: $schema,
            objectId: $objectId,
            payload: ['id' => $itemId],
        );

        $row = $result['result'] ?? [];

        return $this->normalizeRow(row: $row);
    }//end get()

    /**
     * {@inheritdoc}
     *
     * The payload MUST contain a 'reference' key (full XWiki URL or Space.Page path).
     * The OpenConnector source resolves it to the canonical Space.Page identifier.
     *
     * @param string               $register Object register slug
     * @param string               $schema   Object schema slug
     * @param string               $objectId Object identifier
     * @param array<string, mixed> $data     Link payload (['reference' => $urlOrPath])
     *
     * @return array<string, mixed>
     *
     * @spec openspec/changes/integration-xwiki/tasks.md#task-3
     */
    public function create(string $register, string $schema, string $objectId, array $data): array
    {
        $result = $this->router->call(
            source: self::OPENCONNECTOR_SOURCE,
            action: 'create',
            register: $register,
            schema: $schema,
            objectId: $objectId,
            payload: $data,
        );

        $row = $result['result'] ?? [];

        return $this->normalizeRow(row: $row);
    }//end create()

    /**
     * {@inheritdoc}
     *
     * @param string               $register Object register slug
     * @param string               $schema   Object schema slug
     * @param string               $objectId Object identifier
     * @param string               $itemId   Linked item identifier
     * @param array<string, mixed> $data     Update payload
     *
     * @return array<string, mixed>
     *
     * @spec openspec/changes/integration-xwiki/tasks.md#task-3
     */
    public function update(
        string $register,
        string $schema,
        string $objectId,
        string $itemId,
        array $data,
    ): array {
        $result = $this->router->call(
            source: self::OPENCONNECTOR_SOURCE,
            action: 'update',
            register: $register,
            schema: $schema,
            objectId: $objectId,
            payload: array_merge($data, ['id' => $itemId]),
        );

        $row = $result['result'] ?? [];

        return $this->normalizeRow(row: $row);
    }//end update()

    /**
     * {@inheritdoc}
     *
     * Removes the OR sub-resource pairing only. The page in XWiki is never deleted.
     *
     * @param string $register Object register slug
     * @param string $schema   Object schema slug
     * @param string $objectId Object identifier
     * @param string $itemId   Linked item identifier
     *
     * @return void
     *
     * @spec openspec/changes/integration-xwiki/tasks.md#task-3
     */
    public function delete(string $register, string $schema, string $objectId, string $itemId): void
    {
        $this->router->call(
            source: self::OPENCONNECTOR_SOURCE,
            action: 'delete',
            register: $register,
            schema: $schema,
            objectId: $objectId,
            payload: ['id' => $itemId],
        );
    }//end delete()

    /**
     * {@inheritdoc}
     *
     * @return array<string, mixed>
     *
     * @spec openspec/changes/integration-xwiki/tasks.md#task-3
     */
    public function health(): array
    {
        return $this->router->probe(source: self::OPENCONNECTOR_SOURCE);
    }//end health()

    /**
     * {@inheritdoc}
     *
     * Maps raw XWiki page data to the canonical shape:
     * { id, reference, title, space, page, breadcrumb, url, content }
     *
     * Breadcrumb is derived from hierarchy.items[].label when provided by the
     * OpenConnector source mapping. Falls back to "$space / $title" to ensure
     * disambiguation even when the source omits the hierarchy.
     *
     * @param array<string, mixed> $row Raw row data
     *
     * @return array<string, mixed>
     *
     * @spec openspec/changes/integration-xwiki/tasks.md#task-3
     */
    public function normalizeRow(array $row): array
    {
        $id        = (string) ($row['id'] ?? $row['xwikiId'] ?? '');
        $reference = (string) ($row['reference'] ?? $row['fullName'] ?? $id);
        $title     = (string) ($row['title'] ?? $row['xwikiPageTitle'] ?? '');
        $space     = (string) ($row['space'] ?? $row['xwikiSpace'] ?? '');
        $page      = (string) ($row['page'] ?? $row['xwikiPage'] ?? $title);
        $url       = (string) ($row['url'] ?? $row['pageUrl'] ?? '');

        // Prefer source-supplied breadcrumb; fall back to coarse "$space / $title".
        $breadcrumb = (string) ($row['breadcrumb'] ?? $row['hierarchy'] ?? '');
        if ($breadcrumb === '' && $space !== '') {
            $breadcrumb = $space.' / '.$title;
        }

        // RenderedContent alias accepted alongside content.
        $content = (string) ($row['content'] ?? $row['renderedContent'] ?? '');

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
}//end class
