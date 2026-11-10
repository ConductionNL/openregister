<?php

/**
 * KvkProvider — company lookup against the Dutch KvK Handelsregister,
 * exposed through the IntegrationProvider contract.
 *
 * Storage strategy is `external` (AD-4 / AD-22): there is no local link
 * table — this is a stateless, read-only company-lookup leaf. Every
 * upstream call is delegated to {@see ExternalIntegrationRouter}, which
 * resolves the declared OpenConnector source (`kvk`), makes the call, and
 * surfaces structured failures via {@see ProviderUnavailableException}
 * (AD-23). The provider never carries an HTTP client and never handles
 * credentials — the KvK API key lives on the OpenConnector `kvk` source
 * (`auth: apikey`, an `apikey` request header — AD-15).
 *
 * Centralises pipelinq's bespoke `OCA\Pipelinq\Service\KvkApiClient`
 * (base URL + API key) onto the canonical OR/OpenConnector path (ADR-022).
 * The Dutch→prospect field mapping stays in the consuming app
 * (pipelinq `KvkResultMapper`); this leaf round-trips the raw KvK JSON
 * exactly as the source returns it.
 *
 * Surfaces two read operations on top of the registry contract:
 *   - {@see lookupByKvkNumber()} — GET a single company by KvK number
 *     (KvK `/v2/vestigingsprofielen/{vestigingsnummer}` style profiles
 *     are operator-dependent; this leaf hits the source's base path with
 *     the KvK number so the source mapping picks the profile endpoint).
 *   - {@see searchCompanies()} — GET the KvK `/zoeken` search by free text.
 * Both degrade null-safely to `{ unavailable, cause }` rather than throwing.
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
use OCA\OpenRegister\Kernel\Contracts\ObjectRef;
use OCA\OpenRegister\Service\Integration\AbstractIntegrationProvider;
use OCA\OpenRegister\Service\Integration\ExternalIntegrationRouterInterface;
use OCA\OpenRegister\Support\FleetAppId;
use OCP\App\IAppManager;
use OCP\IL10N;
use Psr\Log\LoggerInterface;

/**
 * KvK company-lookup integration provider — external, OpenConnector-backed.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Composes the external
 *   router + app manager + l10n + logger + the ProviderUnavailableException
 *   cause vocabulary; each is required for the degrade-don't-throw lookup
 *   surface (AD-23).
 */
class KvkProvider extends AbstractIntegrationProvider implements KvkLookupInterface {

	use ConnectorRoutedProviderTrait;
	use CompanyLookupCalls;

	/**
	 * OpenConnector source id this provider routes through.
	 *
	 * @var string
	 */
	public const SOURCE_ID = 'kvk';

	/**
	 * The search path of the source.
	 *
	 * @var string
	 */
	private const SEARCH_PATH = 'zoeken';

	/**
	 * The source's query param of each registry filter.
	 *
	 * @var array<string,string>
	 */
	private const LIST_PARAMS = ['_search' => 'naam', '_limit' => 'aantal', '_page' => 'pagina'];

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
		return 'kvk';
	}//end getId()

	/**
	 * Human-readable label shown in the admin UI.
	 *
	 * @return string
	 */
	public function getLabel(): string {
		return $this->l10n->t('KvK Company Register');
	}//end getLabel()

	/**
	 * MDI icon name for the tab / widget.
	 *
	 * @return string
	 */
	public function getIcon(): string {
		return 'OfficeBuilding';
	}//end getIcon()

	/**
	 * List companies matching a free-text query — the registry read-path.
	 *
	 * This leaf is object-independent (company lookup is not bound to an OR
	 * object), so the register/schema/objectId context is ignored; the
	 * `_search` filter carries the query. Mirrors the KvK `/zoeken` surface
	 * pipelinq's `KvkApiClient::search()` hits. Returns a flat list of raw
	 * KvK result rows; the consumer maps them (pipelinq `KvkResultMapper`).
	 *
	 * @param ObjectRef $ref The owning object: register, schema and uuid.
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
	public function list(ObjectRef $ref, array $filters = []): array {
		return $this->extractRows(response: $this->askRegister(query: $this->listQuery(filters: $filters)));
	}//end list()

	/**
	 * Look up a single company by its KvK number.
	 *
	 * Hits the source's `/zoeken?kvkNummer={n}` surface (the same search
	 * endpoint pipelinq's `KvkApiClient` uses, filtered to one number) and
	 * returns the raw KvK rows. Degrades null-safely to
	 * `{ unavailable, cause }` rather than throwing, so a consuming app
	 * never fatals on a missing/down source (AD-23).
	 *
	 * @param string $kvkNumber The 8-digit KvK number.
	 *
	 * @return array<string,mixed> `{ results, total }` on success, or
	 *                             `{ unavailable, cause, results, total }`
	 *                             when the source is unconfigured/down.
	 *
	 * @spec openspec/changes/archive/2026-10-05-integration-kvk-opencorporates/tasks.md
	 */
	public function lookupByKvkNumber(string $kvkNumber): array {
		$kvkNumber = trim($kvkNumber);
		if ($kvkNumber === '') {
			return ['results' => [], 'total' => 0];
		}

		return $this->lookup(query: ['kvkNummer' => $kvkNumber], run: new CompanyLookupRun(operation: 'KvkProvider::lookupByKvkNumber'));
	}//end lookupByKvkNumber()

	/**
	 * Free-text company search against the KvK Handelsregister.
	 *
	 * Object-independent read surface a consuming app uses to find companies
	 * by name (or other KvK search criteria carried in `$criteria`). Degrades
	 * null-safely to `{ unavailable, cause }` rather than throwing (AD-23).
	 *
	 * @param CompanySearch $search The text, paging (limit clamped 1..100, page >= 1) and extra
	 *                             KvK query params (e.g. `plaats`, `type`, `sbiHoofdActiviteit`).
	 *
	 * @return array<string,mixed> `{ results, total, limit, page }` on
	 *                             success, or `{ unavailable, cause, ... }`
	 *                             when the source is unconfigured/down.
	 *
	 * @spec openspec/changes/archive/2026-10-05-integration-kvk-opencorporates/tasks.md
	 */
	public function searchCompanies(CompanySearch $search): array {
		$run = $search->run(operation: 'KvkProvider::searchCompanies');
		$params = $search->criteria;
		if ($search->text() !== '') {
			$params['naam'] = $search->text();
		}

		$params['aantal'] = (string)$run->limit;
		$params['pagina'] = (string)$run->page;

		return $this->lookup(query: $params, run: $run);
	}//end searchCompanies()

	/**
	 * Pull the result rows out of a KvK response envelope. KvK wraps search
	 * hits under `resultaten`; a bare list is used as-is; anything else
	 * yields an empty list (rather than iterating the envelope's scalars as
	 * if they were rows).
	 *
	 * @param array<string,mixed> $response Decoded source response.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function extractRows(array $response): array {
		if (is_array($response['resultaten'] ?? null) === true) {
			return $this->rowsOf(entries: $response['resultaten']);
		}

		if (array_is_list($response) === true) {
			return $this->rowsOf(entries: $response);
		}

		return [];
	}//end extractRows()
}//end class
