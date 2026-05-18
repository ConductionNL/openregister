<?php

/**
 * OpenProjectProvider — exposes OpenProject work packages linked to an
 * OR object via the IntegrationProvider contract.
 *
 * Mirrors the XwikiProvider pattern (AD-4 / AD-22): `external` storage,
 * no local link table. All CRUD goes through OpenConnector — the
 * `openproject` source declared on the OpenConnector side owns the base
 * URL, credentials (OAuth2 / API key — customer-dependent, AD-15), and
 * field mappings. {@see ExternalIntegrationRouter} surfaces structured
 * failures via {@see \OCA\OpenRegister\Exception\ProviderUnavailableException}
 * (AD-23) so the UI degrades to a "Configure" CTA rather than a broken
 * tab when the source is missing or the remote OpenProject is down.
 *
 * No NC app is required — OpenProject is external; the only install
 * dependency is OpenConnector (which carries the source + credentials).
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
 * @spec openspec/changes/integration-openproject/tasks.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Integration\Providers;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- self-documenting IntegrationProvider metadata getters mirror the contract in the interface.

use OCA\OpenRegister\Service\Integration\AbstractIntegrationProvider;
use OCA\OpenRegister\Service\Integration\ExternalIntegrationRouter;
use OCP\App\IAppManager;
use OCP\IL10N;

/**
 * OpenProject integration provider — external, OpenConnector-backed.
 */
class OpenProjectProvider extends AbstractIntegrationProvider
{

    /**
     * OpenConnector source id this provider routes through.
     *
     * @var string
     */
    private const SOURCE_ID = 'openproject';

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
     * @param IAppManager               $appManager NC app manager.
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

    public function getId(): string
    {
        return 'openproject';
    }//end getId()

    public function getLabel(): string
    {
        return $this->l10n->t('Projects');
    }//end getLabel()

    public function getIcon(): string
    {
        return 'Briefcase';
    }//end getIcon()

    public function getGroup(): ?string
    {
        return 'external';
    }//end getGroup()

    public function getRequiredApp(): ?string
    {
        return self::REQUIRED_APP;
    }//end getRequiredApp()

    public function getStorageStrategy(): string
    {
        return 'external';
    }//end getStorageStrategy()

    public function getOpenConnectorSource(): ?string
    {
        return self::SOURCE_ID;
    }//end getOpenConnectorSource()

    public function isEnabled(): bool
    {
        return $this->appManager->isInstalled(self::REQUIRED_APP);
    }//end isEnabled()

    /**
     * Auth requirements descriptor.
     *
     * @return array<string,mixed>
     */
    public function authRequirements(): array
    {
        return [
            'type'          => 'external',
            'configuredVia' => 'openconnector',
            'source'        => self::SOURCE_ID,
            'supports'      => ['oauth2', 'api-key'],
        ];
    }//end authRequirements()

    /**
     * List OpenProject work packages linked to an OR object.
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
            options: ['query' => $query, 'headers' => $this->requestHeaders()]
        );

        return $this->normalizeList(response: $response);
    }//end list()

    /**
     * Fetch a single linked OpenProject work package.
     *
     * @param string $register Register slug or numeric id.
     * @param string $schema   Schema slug or numeric id.
     * @param string $objectId Object uuid.
     * @param string $entityId Work-package id.
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
            options: ['query' => $query, 'headers' => $this->requestHeaders()]
        );

        return $this->normalizeRow(row: $response);
    }//end get()

    /**
     * Link or create an OpenProject work package against an OR object.
     *
     * @param string              $register Register slug or numeric id.
     * @param string              $schema   Schema slug or numeric id.
     * @param string              $objectId Object uuid.
     * @param array<string,mixed> $payload  Reference or new-WP fields.
     *
     * @return array<string,mixed>
     */
    public function create(string $register, string $schema, string $objectId, array $payload): array
    {
        $body = $payload;
        $body['register'] = $register;
        $body['schema']   = $schema;
        $body['object']   = $objectId;

        $response = $this->router->call(
            provider: $this,
            method: 'POST',
            path: '',
            options: ['body' => $body, 'headers' => $this->requestHeaders(withBody: true)]
        );

        return $this->normalizeRow(row: $response);
    }//end create()

    /**
     * Update a linked work-package pairing.
     *
     * @param string              $register Register slug or numeric id.
     * @param string              $schema   Schema slug or numeric id.
     * @param string              $objectId Object uuid.
     * @param string              $entityId Work-package id.
     * @param array<string,mixed> $payload  Fields to update.
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
            options: ['body' => $body, 'headers' => $this->requestHeaders(withBody: true)]
        );

        return $this->normalizeRow(row: $response);
    }//end update()

    /**
     * Unlink a work package. The package itself stays in OpenProject.
     *
     * @param string $register Register slug or numeric id.
     * @param string $schema   Schema slug or numeric id.
     * @param string $objectId Object uuid.
     * @param string $entityId Work-package id.
     *
     * @return void
     */
    public function delete(string $register, string $schema, string $objectId, string $entityId): void
    {
        $this->router->call(
            provider: $this,
            method: 'DELETE',
            path: rawurlencode($entityId),
            options: [
                'query'   => $this->contextQuery(register: $register, schema: $schema, objectId: $objectId, filters: []),
                'headers' => $this->requestHeaders(),
            ]
        );
    }//end delete()

    public function health(): array
    {
        return $this->router->probe(provider: $this);
    }//end health()

    /**
     * Standard `{register, schema, object, …filters}` context query.
     *
     * @param string              $register Register slug or numeric id.
     * @param string              $schema   Schema slug or numeric id.
     * @param string              $objectId Object uuid.
     * @param array<string,mixed> $filters  Caller filters merged in.
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
     * Headers every OpenProject call carries.
     *
     * @param bool $withBody Whether the request carries a JSON body.
     *
     * @return array<string,string>
     */
    private function requestHeaders(bool $withBody=false): array
    {
        $headers = ['Accept' => 'application/json'];
        if ($withBody === true) {
            $headers['Content-Type'] = 'application/json';
        }

        return $headers;
    }//end requestHeaders()

    /**
     * Pull the rows array out of the source's envelope and shape each
     * row through {@see self::normalizeRow()}.
     *
     * @param array<string,mixed> $response Decoded source response.
     *
     * @return array<int,array<string,mixed>>
     */
    private function normalizeList(array $response): array
    {
        $rows = [];
        foreach (['results', 'items', '_embedded', 'elements'] as $key) {
            if (isset($response[$key]) === true && is_array($response[$key]) === true) {
                $candidate = $response[$key];
                // OpenProject's HAL+JSON envelope nests rows under _embedded.elements.
                if ($key === '_embedded'
                    && isset($candidate['elements']) === true
                    && is_array($candidate['elements']) === true
                ) {
                    $candidate = $candidate['elements'];
                }

                $rows = $candidate;
                break;
            }
        }

        if ($rows === [] && array_is_list($response) === true) {
            $rows = $response;
        }

        $out = [];
        foreach ($rows as $row) {
            if (is_array($row) === true) {
                $out[] = $this->normalizeRow(row: $row);
            }
        }

        return $out;
    }//end normalizeList()

    /**
     * Shape one work-package row to the registry contract.
     *
     * @param array<string,mixed> $row One source row.
     *
     * @return array<string,mixed>
     */
    private function normalizeRow(array $row): array
    {
        $id      = (string) ($row['id'] ?? $row['reference'] ?? '');
        $subject = (string) ($row['subject'] ?? $row['title'] ?? $row['name'] ?? $id);
        $status  = (string) ($row['status'] ?? ($row['_links']['status']['title'] ?? ''));
        $url     = (string) ($row['url'] ?? ($row['_links']['self']['href'] ?? ''));

        return array_merge(
            $row,
            [
                'id'        => $id,
                'reference' => $id,
                'title'     => $subject,
                'status'    => $status,
                'url'       => $url,
            ]
        );
    }//end normalizeRow()
}//end class
