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

use OCA\OpenRegister\Kernel\Contracts\ObjectRef;
use OCA\OpenRegister\Db\OpenProjectLink;
use OCA\OpenRegister\Db\OpenProjectLinkMapperInterface;
use OCA\OpenRegister\Service\Integration\AbstractIntegrationProvider;
use OCA\OpenRegister\Service\Integration\ExternalIntegrationRouterInterface;
use OCA\OpenRegister\Support\FleetAppId;
use OCP\App\IAppManager;
use OCP\IL10N;
use Throwable;

/**
 * OpenProject integration provider — external, OpenConnector-backed.
 */
class OpenProjectProvider extends AbstractIntegrationProvider {

	use ConnectorRoutedProviderTrait;
	use ConnectorEntityCallsTrait;

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
	// The connector answers to `integriq` on development and `openconnector`
	// on beta/main; FleetAppId holds both spellings, so this names the app
	// rather than one spelling of it. Never compare it to an id directly —
	// go through FleetAppId, or the comparison is false on half the fleet.
	private const REQUIRED_APP = 'integriq';

	/**
	 * External-call router.
	 *
	 * @var ExternalIntegrationRouterInterface
	 */
	private ExternalIntegrationRouterInterface $router;

	/**
	 * NC app manager.
	 *
	 * @var IAppManager
	 */
	private IAppManager $appManager;

	/**
	 * Localisation.
	 *
	 * @var IL10N
	 */
	private IL10N $l10n;

	/**
	 * Constructor.
	 *
	 * @param ConnectorRouting                    $routing    The router, app manager and localisation.
	 * @param OpenProjectLinkMapperInterface|null $linkMapper Tier-2 link table (optional).
	 *
	 * @return void
	 */
	public function __construct(
		ConnectorRouting $routing,
		private ?OpenProjectLinkMapperInterface $linkMapper = null,
	) {
		$this->router = $routing->router;
		$this->appManager = $routing->appManager;
		$this->l10n = $routing->l10n;
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
		return $this->linkedOrSourced(ref: new ObjectRef(register: $register, schema: $schema, id: $objectId), filters: $filters);
	}//end list()

	/**
	 * The object's work packages: the link table first, else the external source.
	 *
	 * @param ObjectRef           $ref     The owning object.
	 * @param array<string,mixed> $filters Caller filters, passed to the source.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function linkedOrSourced(ObjectRef $ref, array $filters): array {
		// Tier-2 path: read from the link table first.
		$linked = $this->linkedRows(objectId: $ref->id);
		if (count($linked) > 0) {
			return $linked;
		}

		// Fallback: query the external source through OpenConnector.
		$query = $this->contextQuery(ref: $ref, filters: $filters);
		return $this->listFromSource(query: $query);
	}//end linkedOrSourced()

	/**
	 * The rows of the link table for an object; none when the table cannot be read.
	 *
	 * @param string $objectId Object uuid.
	 *
	 * @return array<int,array<string,mixed>> The rows.
	 */
	private function linkedRows(string $objectId): array {
		if ($this->linkMapper === null) {
			return [];
		}

		try {
			$links = $this->linkMapper->findByObjectUuid($objectId);
		} catch (Throwable $e) {
			return [];
		}

		return array_map(fn (OpenProjectLink $link): array => $this->rowFromLink(link: $link), $links);
	}//end linkedRows()

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
		$subject = (string)$link->getSubject();

		return ['id' => $id, 'reference' => $id, 'subject' => $subject, 'title' => $subject]
			+ $this->linkLabels(link: $link)
			+ ['data' => $link->jsonSerialize()];
	}//end rowFromLink()

	/**
	 * The labels of a link row.
	 *
	 * @param OpenProjectLink $link The link row.
	 *
	 * @return array<string,string> The labels, empty when unset.
	 */
	private function linkLabels(OpenProjectLink $link): array {
		return [
			'status' => (string)$link->getStatus(),
			'type' => (string)$link->getType(),
			'priority' => (string)$link->getPriority(),
			'assignee' => (string)$link->getAssignee(),
			'project' => (string)$link->getProject(),
			'url' => (string)$link->getUrl(),
		];
	}//end linkLabels()

	/**
	 * Pull the rows array out of the source's envelope and shape each
	 * row through {@see self::normalizeRow()}.
	 *
	 * @param array<string,mixed> $response Decoded source response.
	 *
	 * @return array<int,array<string,mixed>>
	 *
	 * @SuppressWarnings(PHPMD.UnusedPrivateMethod) Called from
	 *     ConnectorEntityCallsTrait::listFromSource(), which PHPMD does not follow.
	 */
	private function normalizeList(array $response): array {
		$rows = array_filter($this->extractRowsFromEnvelope(response: $response), 'is_array');

		return array_map(fn (array $row): array => $this->normalizeRow(row: $row), array_values($rows));
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
			if (is_array($response[$key] ?? null) === true) {
				return $this->rowsUnder(key: $key, candidate: $response[$key]);
			}
		}

		if (array_is_list($response) === true) {
			return $response;
		}

		return [];
	}//end extractRowsFromEnvelope()

	/**
	 * The rows under an envelope key. OpenProject HAL+JSON nests them under _embedded.elements.
	 *
	 * @param string       $key       The envelope key.
	 * @param array<mixed> $candidate What the key holds.
	 *
	 * @return array<mixed> The rows.
	 */
	private function rowsUnder(string $key, array $candidate): array {
		if ($key === '_embedded' && is_array($candidate['elements'] ?? null) === true) {
			return $candidate['elements'];
		}

		return $candidate;
	}//end rowsUnder()

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

		return array_merge(
			$row,
			['id' => $id, 'reference' => $id, 'subject' => $subject, 'title' => $subject, 'status' => $status],
			$this->halLabels(row: $row),
			['url' => $url]
		);
	}//end normalizeRow()

	/**
	 * Flatten the OpenProject HAL-style _links/_embedded labels onto top-level keys, so
	 * CnOpenprojectTab can render them without walking nested envelopes.
	 *
	 * @param array<string,mixed> $row The raw source row.
	 *
	 * @return array<string,string> The labels.
	 */
	private function halLabels(array $row): array {
		$labels = [];
		foreach (['type', 'priority', 'assignee', 'project'] as $field) {
			$labels[$field] = $this->halLabel(row: $row, field: $field);
		}

		return $labels;
	}//end halLabels()

	/**
	 * The first non-empty string of a top-level field, its link title and its embedded name.
	 *
	 * @param array<string,mixed> $row   The raw source row.
	 * @param string              $field The field.
	 *
	 * @return string The label, or an empty string.
	 */
	private function halLabel(array $row, string $field): string {
		$candidates = [($row[$field] ?? null), ($row['_links'][$field]['title'] ?? null), ($row['_embedded'][$field]['name'] ?? null)];
		foreach ($candidates as $candidate) {
			if (is_string($candidate) === true && $candidate !== '') {
				return $candidate;
			}
		}

		return '';
	}//end halLabel()
}//end class
