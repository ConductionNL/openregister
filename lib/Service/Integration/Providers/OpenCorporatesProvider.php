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
class OpenCorporatesProvider extends AbstractIntegrationProvider implements OpenCorporatesSearchInterface {

	use ConnectorRoutedProviderTrait;
	use CompanyLookupCalls;

	/**
	 * OpenConnector source id this provider routes through.
	 *
	 * @var string
	 */
	public const SOURCE_ID = 'opencorporates';

	/**
	 * The search path of the source.
	 *
	 * @var string
	 */
	private const SEARCH_PATH = 'companies/search';

	/**
	 * The source's query param of each registry filter.
	 *
	 * @var array<string,string>
	 */
	private const LIST_PARAMS = ['_search' => 'q', '_limit' => 'per_page', '_page' => 'page'];

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
	 * @param ConnectorRouting $routing The router, app manager and localisation.
	 * @param LoggerInterface  $logger  Logger for degraded paths.
	 *
	 * @return void
	 */
	public function __construct(
		ConnectorRouting $routing,
		private LoggerInterface $logger,
	) {
		$this->router = $routing->router;
		$this->appManager = $routing->appManager;
		$this->l10n = $routing->l10n;
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
		return $this->extractRows(response: $this->askRegister(query: $this->listQuery(filters: $filters)));
	}//end list()

	/**
	 * Free-text company search against the OpenCorporates register.
	 *
	 * Object-independent read surface a consuming app uses to find companies
	 * by name, optionally scoped to a jurisdiction (e.g. `nl`). Degrades
	 * null-safely to `{ unavailable, cause }` rather than throwing (AD-23).
	 *
	 * @param CompanySearch $search The text, paging (limit clamped 1..100, page >= 1) and the
	 *                             optional criterion `jurisdiction`, an ISO code (e.g. `nl`).
	 *
	 * @return array<string,mixed> `{ results, total, limit, page }` on
	 *                             success, or `{ unavailable, cause, ... }`
	 *                             when the source is unconfigured/down.
	 *
	 * @spec openspec/changes/archive/2026-10-05-integration-kvk-opencorporates/tasks.md
	 */
	public function searchCompanies(CompanySearch $search): array {
		$run = $search->run(operation: 'OpenCorporatesProvider::searchCompanies');
		$params = ['per_page' => (string)$run->limit, 'page' => (string)$run->page, 'order' => 'score'];
		if ($search->text() !== '') {
			$params['q'] = $search->text();
		}

		$jurisdiction = trim((string)($search->criteria['jurisdiction'] ?? ''));
		if ($jurisdiction !== '') {
			$params['jurisdiction_code'] = $jurisdiction;
		}

		return $this->lookup(query: $params, run: $run);
	}//end searchCompanies()

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
	private function extractRows(array $response): array {
		$companies = ($response['results']['companies'] ?? null);
		if (is_array($companies) === true) {
			return array_map(fn (array $entry): array => $this->unwrapped(entry: $entry), $this->rowsOf(entries: $companies));
		}

		if (array_is_list($response) === true) {
			return $this->rowsOf(entries: $response);
		}

		return [];
	}//end extractRows()

	/**
	 * The company of an entry; an entry without one is used as it is.
	 *
	 * @param array<string,mixed> $entry The entry.
	 *
	 * @return array<string,mixed> The company.
	 */
	private function unwrapped(array $entry): array {
		if (is_array($entry['company'] ?? null) === true) {
			return $entry['company'];
		}

		return $entry;
	}//end unwrapped()
}//end class
