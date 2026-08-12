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
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
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
 * @spec openspec/specs/integration-openproject/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Integration\Providers;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- self-documenting IntegrationProvider metadata getters mirror the contract in the interface.

use OCA\OpenRegister\Db\OpenProjectLink;
use OCA\OpenRegister\Db\OpenProjectLinkMapper;
use OCA\OpenRegister\Service\Integration\AbstractIntegrationProvider;
use OCA\OpenRegister\Service\Integration\ExternalIntegrationRouter;
use OCP\App\IAppManager;
use OCP\IL10N;
use Throwable;

/**
 * OpenProject integration provider — external, OpenConnector-backed.
 */
class OpenProjectProvider extends AbstractIntegrationProvider {

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
	 * @param ExternalIntegrationRouter $router External-call router.
	 * @param IAppManager $appManager NC app manager.
	 * @param IL10N $l10n Localisation.
	 * @param OpenProjectLinkMapper|null $linkMapper Tier-2 link table (optional).
	 *
	 * @return void
	 */
	public function __construct(
		private ExternalIntegrationRouter $router,
		private IAppManager $appManager,
		private IL10N $l10n,
		private ?OpenProjectLinkMapper $linkMapper = null,
	) {
	}//end __construct()

	public function getId(): string {
		return 'openproject';
	}//end getId()

	public function getLabel(): string {
		return $this->l10n->t('Projects');
	}//end getLabel()

	public function getIcon(): string {
		return 'Briefcase';
	}//end getIcon()

	public function getGroup(): ?string {
		return 'external';
	}//end getGroup()

	public function getRequiredApp(): ?string {
		return self::REQUIRED_APP;
	}//end getRequiredApp()

	public function getStorageStrategy(): string {
		return 'external';
	}//end getStorageStrategy()

	public function getOpenConnectorSource(): ?string {
		return self::SOURCE_ID;
	}//end getOpenConnectorSource()

	public function isEnabled(): bool {
		return $this->appManager->isInstalled(self::REQUIRED_APP);
	}//end isEnabled()

	/**
	 * Auth requirements descriptor.
	 *
	 * @return array<string,mixed>
	 *
	 * @spec openspec/specs/integration-openproject/spec.md
	 */
	public function authRequirements(): array {
		return [
			'type' => 'external',
			'configuredVia' => 'openconnector',
			'source' => self::SOURCE_ID,
			'supports' => ['oauth2', 'api-key'],
		];
	}//end authRequirements()

	/**
	 * List OpenProject work packages linked to an OR object.
	 *
	 * Tier-2 path: reads the dedicated `openregister_openproject_links`
	 * table first so the linked list renders from the cached row even
	 * when the OpenConnector source is temporarily unconfigured / down.
	 * When no link rows exist (or the mapper isn't wired) it falls back
	 * to the external router so a fresh source still surfaces rows.
	 *
	 * @param string $register Register slug or numeric id.
	 * @param string $schema Schema slug or numeric id.
	 * @param string $objectId Object uuid.
	 * @param array<string,mixed> $filters Optional: `_search`, `_limit`, `_page`.
	 *
	 * @return array<int,array<string,mixed>>
	 *
	 * @spec openspec/specs/integration-openproject/spec.md
	 */
	public function list(string $register, string $schema, string $objectId, array $filters = []): array {
		// Tier-2 path: read from the link table first.
		if ($this->linkMapper !== null) {
			try {
				$linkRows = $this->linkMapper->findByObjectUuid($objectId);
			} catch (Throwable $e) {
				$linkRows = [];
			}

			if (count($linkRows) > 0) {
				return array_map(
					fn (OpenProjectLink $link): array => $this->rowFromLink(link: $link),
					$linkRows
				);
			}
		}

		// Fallback: query the external source through OpenConnector.
		$query = $this->contextQuery(register: $register, schema: $schema, objectId: $objectId, filters: $filters);
		$response = $this->router->call(
			provider: $this,
			method: 'GET',
			path: '',
			options: ['query' => $query, 'headers' => $this->requestHeaders()]
		);

		return $this->normalizeList(response: $response);
	}//end list()

	/**
	 * Convert an OpenProjectLink row into the registry leaf-row shape,
	 * preserving the flat type / status / priority / assignee / project
	 * fields the bespoke CnOpenprojectTab renders.
	 *
	 * @param OpenProjectLink $link Link row from the mapper.
	 *
	 * @return array<string,mixed>
	 */
	private function rowFromLink(OpenProjectLink $link): array {
		$id = (string)$link->getWorkPackageId();
		$data = $link->jsonSerialize();

		return [
			'id' => $id,
			'reference' => $id,
			'subject' => (string)$link->getSubject(),
			'title' => (string)$link->getSubject(),
			'status' => (string)($link->getStatus() ?? ''),
			'type' => (string)($link->getType() ?? ''),
			'priority' => (string)($link->getPriority() ?? ''),
			'assignee' => (string)($link->getAssignee() ?? ''),
			'project' => (string)($link->getProject() ?? ''),
			'url' => (string)($link->getUrl() ?? ''),
			'data' => $data,
		];
	}//end rowFromLink()

	/**
	 * Fetch a single linked OpenProject work package.
	 *
	 * @param string $register Register slug or numeric id.
	 * @param string $schema Schema slug or numeric id.
	 * @param string $objectId Object uuid.
	 * @param string $entityId Work-package id.
	 *
	 * @return array<string,mixed>
	 *
	 * @spec openspec/specs/integration-openproject/spec.md
	 */
	public function get(string $register, string $schema, string $objectId, string $entityId): array {
		$query = $this->contextQuery(register: $register, schema: $schema, objectId: $objectId, filters: []);
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
	 * @param string $register Register slug or numeric id.
	 * @param string $schema Schema slug or numeric id.
	 * @param string $objectId Object uuid.
	 * @param array<string,mixed> $payload Reference or new-WP fields.
	 *
	 * @return array<string,mixed>
	 *
	 * @spec openspec/specs/integration-openproject/spec.md
	 */
	public function create(string $register, string $schema, string $objectId, array $payload): array {
		$body = $payload;
		$body['register'] = $register;
		$body['schema'] = $schema;
		$body['object'] = $objectId;

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
	 * @param string $register Register slug or numeric id.
	 * @param string $schema Schema slug or numeric id.
	 * @param string $objectId Object uuid.
	 * @param string $entityId Work-package id.
	 * @param array<string,mixed> $payload Fields to update.
	 *
	 * @return array<string,mixed>
	 *
	 * @spec openspec/specs/integration-openproject/spec.md
	 */
	public function update(string $register, string $schema, string $objectId, string $entityId, array $payload): array {
		$body = $payload;
		$body['register'] = $register;
		$body['schema'] = $schema;
		$body['object'] = $objectId;

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
	 * @param string $schema Schema slug or numeric id.
	 * @param string $objectId Object uuid.
	 * @param string $entityId Work-package id.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/integration-openproject/spec.md
	 */
	public function delete(string $register, string $schema, string $objectId, string $entityId): void {
		$this->router->call(
			provider: $this,
			method: 'DELETE',
			path: rawurlencode($entityId),
			options: [
				'query' => $this->contextQuery(register: $register, schema: $schema, objectId: $objectId, filters: []),
				'headers' => $this->requestHeaders(),
			]
		);
	}//end delete()

	/**
	 * Health descriptor — defers to the router's probe.
	 *
	 * @return array{status: string, authStatus: string, message: ?string}
	 *
	 * @spec exclude Thin delegation to ExternalIntegrationRouter::probe
	 *   (annotated to pluggable-integration-registry task-4); carries no provider-specific health behaviour.
	 */
	public function health(): array {
		return $this->router->probe(provider: $this);
	}//end health()

	/**
	 * Standard `{register, schema, object, …filters}` context query.
	 *
	 * @param string $register Register slug or numeric id.
	 * @param string $schema Schema slug or numeric id.
	 * @param string $objectId Object uuid.
	 * @param array<string,mixed> $filters Caller filters merged in.
	 *
	 * @return array<string,mixed>
	 */
	private function contextQuery(string $register, string $schema, string $objectId, array $filters): array {
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
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Body-vs-no-body is the
	 *     natural toggle for HTTP request headers; a two-method split
	 *     (requestHeaders / requestHeadersWithBody) would duplicate the
	 *     static Accept header.
	 */
	private function requestHeaders(bool $withBody = false): array {
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
	private function normalizeList(array $response): array {
		$rows = $this->extractRowsFromEnvelope(response: $response);

		$out = [];
		foreach ($rows as $row) {
			if (is_array($row) === true) {
				$out[] = $this->normalizeRow(row: $row);
			}
		}

		return $out;
	}//end normalizeList()

	/**
	 * Extract the row list from a potentially enveloped source response.
	 *
	 * Handles OpenProject HAL+JSON (`_embedded.elements`), generic
	 * `results`, `items`, `elements` wrappers, and bare top-level arrays.
	 *
	 * @param array<string,mixed> $response Decoded source response.
	 *
	 * @return array<mixed> Flat list of raw row arrays.
	 */
	private function extractRowsFromEnvelope(array $response): array {
		foreach (['results', 'items', '_embedded', 'elements'] as $key) {
			if (isset($response[$key]) === false || is_array($response[$key]) === false) {
				continue;
			}

			$candidate = $response[$key];
			// OpenProject HAL+JSON nests rows under _embedded.elements.
			if ($key === '_embedded'
				&& isset($candidate['elements']) === true
				&& is_array($candidate['elements']) === true
			) {
				$candidate = $candidate['elements'];
			}

			return $candidate;
		}

		if (array_is_list($response) === true) {
			return $response;
		}

		return [];
	}//end extractRowsFromEnvelope()

	/**
	 * Shape one work-package row to the registry contract.
	 *
	 * @param array<string,mixed> $row One source row.
	 *
	 * @return array<string,mixed>
	 */
	private function normalizeRow(array $row): array {
		$id = (string)($row['id'] ?? $row['reference'] ?? '');
		$subject = (string)($row['subject'] ?? $row['title'] ?? $row['name'] ?? $id);
		$status = (string)($row['status'] ?? ($row['_links']['status']['title'] ?? ''));
		$url = (string)($row['url'] ?? ($row['_links']['self']['href'] ?? ''));

		// Flatten the OpenProject hAL-style _links/_embedded labels onto
		// top-level keys so CnOpenprojectTab can render type / priority /
		// assignee / project without hand-walking nested envelopes.
		// Falls through to top-level fields when a source pre-maps them.
		$type = $this->pickHalLabel(row: $row, field: 'type', linkKey: 'title', embedKey: 'name');
		$priority = $this->pickHalLabel(row: $row, field: 'priority', linkKey: 'title', embedKey: 'name');
		$assignee = $this->pickHalLabel(row: $row, field: 'assignee', linkKey: 'title', embedKey: 'name');
		$project = $this->pickHalLabel(row: $row, field: 'project', linkKey: 'title', embedKey: 'name');

		return array_merge(
			$row,
			[
				'id' => $id,
				'reference' => $id,
				'subject' => $subject,
				'title' => $subject,
				'status' => $status,
				'type' => $type,
				'priority' => $priority,
				'assignee' => $assignee,
				'project' => $project,
				'url' => $url,
			]
		);
	}//end normalizeRow()

	/**
	 * Pick a hAL label from a work-package row, preferring a top-level
	 * field, then `_links.<field>.<linkKey>`, then `_embedded.<field>.<embedKey>`.
	 *
	 * Empty strings count as "missing" so source mappings that emit a
	 * blank top-level key still fall through to the envelope copy.
	 *
	 * @param array $row Raw work-package row.
	 * @param string $field Field name (`type`, `priority`, `assignee`, `project`).
	 * @param string $linkKey Label key under `_links.<field>` (`title`).
	 * @param string $embedKey Label key under `_embedded.<field>` (`name`).
	 *
	 * @return string Resolved label or empty string.
	 */
	private function pickHalLabel(array $row, string $field, string $linkKey, string $embedKey): string {
		$top = $row[$field] ?? null;
		if (is_string($top) === true && $top !== '') {
			return $top;
		}

		$link = $row['_links'][$field][$linkKey] ?? null;
		if (is_string($link) === true && $link !== '') {
			return $link;
		}

		$embed = $row['_embedded'][$field][$embedKey] ?? null;
		if (is_string($embed) === true && $embed !== '') {
			return $embed;
		}

		return '';
	}//end pickHalLabel()
}//end class
