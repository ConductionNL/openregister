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
 * @spec openspec/changes/integration-kvk-opencorporates/tasks.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Integration\Providers;

use OCA\OpenRegister\Exception\ProviderUnavailableException;
use OCA\OpenRegister\Service\Integration\AbstractIntegrationProvider;
use OCA\OpenRegister\Service\Integration\ExternalIntegrationRouter;
use OCP\App\IAppManager;
use OCP\IL10N;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * KvK company-lookup integration provider — external, OpenConnector-backed.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Composes the external
 *   router + app manager + l10n + logger + the ProviderUnavailableException
 *   cause vocabulary; each is required for the degrade-don't-throw lookup
 *   surface (AD-23).
 */
class KvkProvider extends AbstractIntegrationProvider {

	/**
	 * OpenConnector source id this provider routes through.
	 *
	 * @var string
	 */
	public const SOURCE_ID = 'kvk';

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
	 * @param IAppManager $appManager NC app manager (isEnabled check).
	 * @param IL10N $l10n Localisation.
	 * @param LoggerInterface $logger Logger for degraded paths.
	 *
	 * @return void
	 */
	public function __construct(
		private ExternalIntegrationRouter $router,
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
	 * Named group this integration belongs to (AD-16).
	 *
	 * @return string|null
	 */
	public function getGroup(): ?string {
		return 'external';
	}//end getGroup()

	/**
	 * Nextcloud app that must be installed for this integration to
	 * function — OpenConnector carries the `kvk` source + credentials.
	 *
	 * @return string|null
	 */
	public function getRequiredApp(): ?string {
		return self::REQUIRED_APP;
	}//end getRequiredApp()

	/**
	 * Storage strategy (AD-22) — `external`: no local link table; this is
	 * a stateless company-lookup leaf routed through OpenConnector.
	 *
	 * @return string
	 */
	public function getStorageStrategy(): string {
		return 'external';
	}//end getStorageStrategy()

	/**
	 * OpenConnector source id this provider routes all calls through (AD-4).
	 *
	 * @return string|null
	 */
	public function getOpenConnectorSource(): ?string {
		return self::SOURCE_ID;
	}//end getOpenConnectorSource()

	/**
	 * Whether the integration is available — true iff OpenConnector is
	 * installed (it owns the `kvk` source + credentials). The router still
	 * degrades gracefully if the source itself is missing or KvK is down.
	 *
	 * @return bool
	 */
	public function isEnabled(): bool {
		return $this->appManager->isInstalled(self::REQUIRED_APP);
	}//end isEnabled()

	/**
	 * Auth requirements descriptor. `type: 'external'` — the KvK API key is
	 * configured on the OpenConnector `kvk` source (an `apikey` request
	 * header), not here. OpenRegister's admin UI surfaces the source's auth
	 * status and links out to OpenConnector to configure it.
	 *
	 * @return array<string,mixed>
	 *
	 * @spec openspec/changes/integration-kvk-opencorporates/tasks.md
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
	 * This leaf is object-independent (company lookup is not bound to an OR
	 * object), so the register/schema/objectId context is ignored; the
	 * `_search` filter carries the query. Mirrors the KvK `/zoeken` surface
	 * pipelinq's `KvkApiClient::search()` hits. Returns a flat list of raw
	 * KvK result rows; the consumer maps them (pipelinq `KvkResultMapper`).
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
	 * @spec openspec/changes/integration-kvk-opencorporates/tasks.md
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) register/schema/objectId
	 *   are mandated by the IntegrationProvider contract; this leaf is
	 *   object-independent so they are intentionally unused.
	 */
	public function list(string $register, string $schema, string $objectId, array $filters = []): array {
		$query = [];
		$search = (string)($filters['_search'] ?? '');
		if ($search !== '') {
			$query['naam'] = $search;
		}

		$limit = (int)($filters['_limit'] ?? 0);
		if ($limit > 0) {
			$query['aantal'] = (string)min(100, $limit);
		}

		$page = (int)($filters['_page'] ?? 0);
		if ($page > 0) {
			$query['pagina'] = (string)$page;
		}

		$response = $this->router->call(
			provider: $this,
			method: 'GET',
			path: 'zoeken',
			options: ['query' => $query, 'headers' => ['Accept' => 'application/json']]
		);

		return $this->extractRows(response: $response);
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
	 * @spec openspec/changes/integration-kvk-opencorporates/tasks.md
	 */
	public function lookupByKvkNumber(string $kvkNumber): array {
		$kvkNumber = trim($kvkNumber);
		if ($kvkNumber === '') {
			return ['results' => [], 'total' => 0];
		}

		try {
			$response = $this->router->call(
				provider: $this,
				method: 'GET',
				path: 'zoeken',
				options: [
					'query' => ['kvkNummer' => $kvkNumber],
					'headers' => ['Accept' => 'application/json'],
				]
			);
		} catch (ProviderUnavailableException $e) {
			return $this->degraded(cause: $e->getCause());
		} catch (Throwable $e) {
			$this->logger->warning('KvkProvider::lookupByKvkNumber failed: ' . $e->getMessage());
			return $this->degraded(cause: ProviderUnavailableException::CAUSE_UPSTREAM_SERVICE_DOWN);
		}

		$rows = $this->extractRows(response: $response);

		return ['results' => $rows, 'total' => count($rows)];
	}//end lookupByKvkNumber()

	/**
	 * Free-text company search against the KvK Handelsregister.
	 *
	 * Object-independent read surface a consuming app uses to find companies
	 * by name (or other KvK search criteria carried in `$criteria`). Degrades
	 * null-safely to `{ unavailable, cause }` rather than throwing (AD-23).
	 *
	 * @param string $query Free-text company-name query.
	 * @param array<string,mixed> $criteria Extra KvK query params (e.g.
	 *                                      `plaats`, `type`, `sbiHoofdActiviteit`).
	 * @param int $limit Max results (clamped 1..100).
	 * @param int $page 1-based page (clamped >= 1).
	 *
	 * @return array<string,mixed> `{ results, total, limit, page }` on
	 *                             success, or `{ unavailable, cause, ... }`
	 *                             when the source is unconfigured/down.
	 *
	 * @spec openspec/changes/integration-kvk-opencorporates/tasks.md
	 */
	public function searchCompanies(string $query, array $criteria = [], int $limit = 25, int $page = 1): array {
		$limit = max(1, min(100, $limit));
		$page = max(1, $page);

		$params = $criteria;
		$query = trim($query);
		if ($query !== '') {
			$params['naam'] = $query;
		}

		$params['aantal'] = (string)$limit;
		$params['pagina'] = (string)$page;

		try {
			$response = $this->router->call(
				provider: $this,
				method: 'GET',
				path: 'zoeken',
				options: ['query' => $params, 'headers' => ['Accept' => 'application/json']]
			);
		} catch (ProviderUnavailableException $e) {
			return $this->degraded(cause: $e->getCause(), limit: $limit, page: $page);
		} catch (Throwable $e) {
			$this->logger->warning('KvkProvider::searchCompanies failed: ' . $e->getMessage());
			return $this->degraded(cause: ProviderUnavailableException::CAUSE_UPSTREAM_SERVICE_DOWN, limit: $limit, page: $page);
		}

		$rows = $this->extractRows(response: $response);

		return [
			'results' => $rows,
			'total' => count($rows),
			'limit' => $limit,
			'page' => $page,
		];
	}//end searchCompanies()

	/**
	 * Health descriptor — defers to the router's probe so admin UI / OCS
	 * capabilities report the same status runtime callers would see.
	 *
	 * @return array{status: string, authStatus: string, message: ?string}
	 *
	 * @spec exclude Thin delegation to ExternalIntegrationRouter::probe
	 *              (annotated to pluggable-integration-registry task-4);
	 *              carries no provider-specific health behaviour.
	 */
	public function health(): array {
		return $this->router->probe(provider: $this);
	}//end health()

	/**
	 * Build the degraded envelope mirroring the unconfigured-source
	 * descriptor so the lookup shape stays stable across success +
	 * degraded paths (AD-23).
	 *
	 * @param string $cause One of the ProviderUnavailableException CAUSE_* values.
	 * @param int $limit Optional resolved limit (search paths).
	 * @param int $page Optional resolved page (search paths).
	 *
	 * @return array<string,mixed>
	 */
	private function degraded(string $cause, int $limit = 0, int $page = 0): array {
		$state = [
			'unavailable' => true,
			'cause' => $cause,
			'results' => [],
			'total' => 0,
		];

		if ($limit > 0) {
			$state['limit'] = $limit;
			$state['page'] = $page;
		}

		return $state;
	}//end degraded()

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
		$rows = [];
		if (isset($response['resultaten']) === true && is_array($response['resultaten']) === true) {
			$rows = $response['resultaten'];
		} elseif (array_is_list($response) === true) {
			$rows = $response;
		}

		$out = [];
		foreach ($rows as $row) {
			if (is_array($row) === true) {
				$out[] = $row;
			}
		}

		return $out;
	}//end extractRows()
}//end class
