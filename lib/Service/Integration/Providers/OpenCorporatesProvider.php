<?php

/**
 * OpenCorporatesProvider — company search against the OpenCorporates open
 * company register, exposed through the IntegrationProvider contract.
 *
 * Storage strategy is `external` (AD-4 / AD-22): there is no local link
 * table — this is a stateless, read-only company-search leaf. Every
 * upstream call is delegated to {@see ExternalIntegrationRouter}, which
 * resolves the declared OpenConnector source (`opencorporates`), makes the
 * call, and surfaces structured failures via
 * {@see ProviderUnavailableException} (AD-23). The provider never carries
 * an HTTP client and never handles credentials — the OpenCorporates API
 * token lives on the OpenConnector `opencorporates` source (`auth: apikey`,
 * an `api_token` query parameter — AD-15).
 *
 * Centralises pipelinq's bespoke
 * `OCA\Pipelinq\Service\OpenCorporatesApiClient` (base URL + API token) onto
 * the canonical OR/OpenConnector path (ADR-022). The company→prospect field
 * mapping stays in the consuming app (pipelinq `OpenCorporatesResultMapper`);
 * this leaf round-trips the raw OpenCorporates JSON exactly as the source
 * returns it.
 *
 * Surfaces one read operation on top of the registry contract:
 *   - {@see searchCompanies()} — GET the OpenCorporates `/companies/search`
 *     by free text + jurisdiction. Degrades null-safely to
 *     `{ unavailable, cause }` rather than throwing.
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
 * @spec openspec/changes/archive/2026-10-05-integration-kvk-opencorporates/tasks.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Integration\Providers;

use OCA\OpenRegister\Exception\ProviderUnavailableException;
use OCA\OpenRegister\Service\Integration\AbstractIntegrationProvider;
use OCA\OpenRegister\Service\Integration\ExternalIntegrationRouterInterface;
use OCA\OpenRegister\Support\FleetAppId;
use OCP\App\IAppManager;
use OCP\IL10N;
use Psr\Log\LoggerInterface;

/**
 * OpenCorporates company-search integration provider — external,
 * OpenConnector-backed.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Composes the external
 *   router + app manager + l10n + logger + the ProviderUnavailableException
 *   cause vocabulary; each is required for the degrade-don't-throw search
 *   surface (AD-23).
 */
class OpenCorporatesProvider extends AbstractIntegrationProvider {

	use CompanySearchLeaf;
	use ConnectorRoutedProviderTrait;

	/**
	 * OpenConnector source id this provider routes through.
	 *
	 * @var string
	 */
	public const SOURCE_ID = 'opencorporates';

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
	 * The OpenCorporates endpoint every search GETs.
	 *
	 * @var string
	 */
	private const SEARCH_PATH = 'companies/search';

	/**
	 * The OpenCorporates query names of the list() filters.
	 *
	 * @var array<string, string>
	 */
	private const LIST_PARAMS = ['search' => 'q', 'limit' => 'per_page', 'page' => 'page'];

	/**
	 * Constructor.
	 *
	 * @param ExternalIntegrationRouterInterface $router External-call router.
	 * @param IAppManager $appManager NC app manager (isEnabled check).
	 * @param IL10N $l10n Localisation.
	 * @param LoggerInterface $logger Logger for degraded paths.
	 *
	 * @return void
	 */
	public function __construct(
		private ExternalIntegrationRouterInterface $router,
		private IAppManager $appManager,
		private IL10N $l10n,
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Stable provider id (matches the OpenConnector source name).
	 *
	 * @return string
	 */
	public function getId(): string {
		return 'opencorporates';
	}//end getId()

	/**
	 * Human-readable label shown in the admin UI.
	 *
	 * @return string
	 */
	public function getLabel(): string {
		return $this->l10n->t('OpenCorporates');
	}//end getLabel()

	/**
	 * MDI icon name for the tab / widget.
	 *
	 * @return string
	 */
	public function getIcon(): string {
		return 'Domain';
	}//end getIcon()

	/**
	 * Auth requirements descriptor. `type: 'external'` — the OpenCorporates
	 * API token is configured on the OpenConnector `opencorporates` source
	 * (an `api_token` query parameter), not here. OpenRegister's admin UI
	 * surfaces the source's auth status and links out to OpenConnector to
	 * configure it.
	 *
	 * @return array<string,mixed>
	 *
	 * @spec openspec/changes/archive/2026-10-05-integration-kvk-opencorporates/tasks.md
	 */
	public function authRequirements(): array {
		return [
			'type' => 'external',
			'configuredVia' => 'openconnector',
			'source' => self::SOURCE_ID,
			'supports' => ['apikey'],
		];
	}//end authRequirements()

	/**
	 * List companies matching a free-text query — the registry read-path.
	 *
	 * This leaf is object-independent (company search is not bound to an OR
	 * object), so the register/schema/objectId context is ignored; the
	 * `_search` filter carries the query. Mirrors the OpenCorporates
	 * `/companies/search` surface pipelinq's `OpenCorporatesApiClient`
	 * hits. Returns a flat list of raw company rows; the consumer maps them
	 * (pipelinq `OpenCorporatesResultMapper`).
	 *
	 * @param string $register Ignored (object-independent leaf).
	 * @param string $schema Ignored (object-independent leaf).
	 * @param string $objectId Ignored (object-independent leaf).
	 * @param array<string,mixed> $filters `_search` (query), `_limit`, `_page`.
	 *
	 * @return array<int,array<string,mixed>>
	 *
	 * @throws ProviderUnavailableException When the source is missing/down —
	 *                                      the controller maps the cause.
	 *
	 * @spec openspec/changes/archive/2026-10-05-integration-kvk-opencorporates/tasks.md
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) register/schema/objectId
	 *   are mandated by the IntegrationProvider contract; this leaf is
	 *   object-independent so they are intentionally unused.
	 */
	public function list(string $register, string $schema, string $objectId, array $filters = []): array {
		return $this->extractRows(response: $this->searchCall(query: $this->listQuery(filters: $filters)));
	}//end list()

	/**
	 * Free-text company search against the OpenCorporates register.
	 *
	 * Object-independent read surface a consuming app uses to find companies
	 * by name, optionally scoped to a jurisdiction (e.g. `nl`). Degrades
	 * null-safely to `{ unavailable, cause }` rather than throwing (AD-23).
	 *
	 * @param string $query Free-text company-name query.
	 * @param string|null $jurisdiction Optional ISO jurisdiction code (e.g. `nl`).
	 * @param int $limit Max results per page (clamped 1..100).
	 * @param int $page 1-based page (clamped >= 1).
	 *
	 * @return array<string,mixed> `{ results, total, limit, page }` on
	 *                             success, or `{ unavailable, cause, ... }`
	 *                             when the source is unconfigured/down.
	 *
	 * @spec openspec/changes/archive/2026-10-05-integration-kvk-opencorporates/tasks.md
	 */
	public function searchCompanies(string $query, ?string $jurisdiction = null, int $limit = 30, int $page = 1): array {
		$limit = max(1, min(100, $limit));
		$page = max(1, $page);
		$params = ['per_page' => (string)$limit, 'page' => (string)$page, 'order' => 'score'];

		$query = trim($query);
		if ($query !== '') {
			$params['q'] = $query;
		}

		$lookup = new CompanyLookup(operation: 'OpenCorporatesProvider::searchCompanies', limit: $limit, page: $page);
		return $this->lookup(params: $this->withJurisdiction(params: $params, jurisdiction: $jurisdiction), lookup: $lookup);
	}//end searchCompanies()

	/**
	 * Add a non-blank jurisdiction filter (trimmed) to the search query.
	 *
	 * @param array<string, string> $params The query so far.
	 * @param string|null $jurisdiction The jurisdiction code, or null.
	 *
	 * @return array<string, string> The query parameters.
	 */
	private function withJurisdiction(array $params, ?string $jurisdiction): array {
		if ($jurisdiction !== null && trim($jurisdiction) !== '') {
			$params['jurisdiction_code'] = trim($jurisdiction);
		}

		return $params;
	}//end withJurisdiction()

	/**
	 * Pull the company rows out of an OpenCorporates response envelope.
	 * OpenCorporates wraps hits under `results.companies` (each entry is
	 * `{ company: {...} }`), which this method unwraps to a flat list of
	 * raw `company` objects; a bare list is used as-is; anything else yields
	 * an empty list.
	 *
	 * @param array<string,mixed> $response Decoded source response.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	protected function extractRows(array $response): array {
		$companies = ($response['results']['companies'] ?? null);
		if (is_array($companies) === true) {
			return $this->unwrapCompanies(entries: $companies);
		}

		if (array_is_list($response) === true) {
			return $this->arrayRows(rows: $response);
		}

		return [];
	}//end extractRows()

	/**
	 * The company of each array entry: its `company` member when that is an
	 * array, else the entry itself.
	 *
	 * @param array<mixed> $entries The `results.companies` entries.
	 *
	 * @return array<int, array<string, mixed>> The company rows.
	 */
	private function unwrapCompanies(array $entries): array {
		$out = [];
		foreach ($this->arrayRows(rows: $entries) as $entry) {
			$out[] = $this->companyOf(entry: $entry);
		}

		return $out;
	}//end unwrapCompanies()

	/**
	 * The `company` member of an entry when that is an array, else the entry.
	 *
	 * @param array<string, mixed> $entry A `results.companies` entry.
	 *
	 * @return array<string, mixed> The company row.
	 */
	private function companyOf(array $entry): array {
		if (isset($entry['company']) === true && is_array($entry['company']) === true) {
			return $entry['company'];
		}

		return $entry;
	}//end companyOf()
}//end class
