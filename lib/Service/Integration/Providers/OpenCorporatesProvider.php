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
 * OpenCorporates company-search integration provider — external,
 * OpenConnector-backed.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Composes the external
 *   router + app manager + l10n + logger + the ProviderUnavailableException
 *   cause vocabulary; each is required for the degrade-don't-throw search
 *   surface (AD-23).
 */
class OpenCorporatesProvider extends AbstractIntegrationProvider {

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
	 * Named group this integration belongs to (AD-16).
	 *
	 * @return string|null
	 */
	public function getGroup(): ?string {
		return 'external';
	}//end getGroup()

	/**
	 * Nextcloud app that must be installed for this integration to
	 * function — OpenConnector carries the `opencorporates` source +
	 * credentials.
	 *
	 * @return string|null
	 */
	public function getRequiredApp(): ?string {
		return self::REQUIRED_APP;
	}//end getRequiredApp()

	/**
	 * Storage strategy (AD-22) — `external`: no local link table; this is
	 * a stateless company-search leaf routed through OpenConnector.
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
	 * installed (it owns the `opencorporates` source + credentials). The
	 * router still degrades gracefully if the source itself is missing or
	 * OpenCorporates is down.
	 *
	 * @return bool
	 */
	public function isEnabled(): bool {
		return $this->appManager->isInstalled(self::REQUIRED_APP);
	}//end isEnabled()

	/**
	 * Auth requirements descriptor. `type: 'external'` — the OpenCorporates
	 * API token is configured on the OpenConnector `opencorporates` source
	 * (an `api_token` query parameter), not here. OpenRegister's admin UI
	 * surfaces the source's auth status and links out to OpenConnector to
	 * configure it.
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
			$query['q'] = $search;
		}

		$limit = (int)($filters['_limit'] ?? 0);
		if ($limit > 0) {
			$query['per_page'] = (string)min(100, $limit);
		}

		$page = (int)($filters['_page'] ?? 0);
		if ($page > 0) {
			$query['page'] = (string)$page;
		}

		$response = $this->router->call(
			provider: $this,
			method: 'GET',
			path: 'companies/search',
			options: ['query' => $query, 'headers' => ['Accept' => 'application/json']]
		);

		return $this->extractRows(response: $response);
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
	 * @spec openspec/changes/integration-kvk-opencorporates/tasks.md
	 */
	public function searchCompanies(string $query, ?string $jurisdiction = null, int $limit = 30, int $page = 1): array {
		$limit = max(1, min(100, $limit));
		$page = max(1, $page);

		$params = [
			'per_page' => (string)$limit,
			'page' => (string)$page,
			'order' => 'score',
		];

		$query = trim($query);
		if ($query !== '') {
			$params['q'] = $query;
		}

		if ($jurisdiction !== null && trim($jurisdiction) !== '') {
			$params['jurisdiction_code'] = trim($jurisdiction);
		}

		try {
			$response = $this->router->call(
				provider: $this,
				method: 'GET',
				path: 'companies/search',
				options: ['query' => $params, 'headers' => ['Accept' => 'application/json']]
			);
		} catch (ProviderUnavailableException $e) {
			return $this->degraded(cause: $e->getCause(), limit: $limit, page: $page);
		} catch (Throwable $e) {
			$this->logger->warning('OpenCorporatesProvider::searchCompanies failed: ' . $e->getMessage());
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
	 * descriptor so the search shape stays stable across success +
	 * degraded paths (AD-23).
	 *
	 * @param string $cause One of the ProviderUnavailableException CAUSE_* values.
	 * @param int $limit Resolved limit.
	 * @param int $page Resolved page.
	 *
	 * @return array<string,mixed>
	 */
	private function degraded(string $cause, int $limit, int $page): array {
		return [
			'unavailable' => true,
			'cause' => $cause,
			'results' => [],
			'total' => 0,
			'limit' => $limit,
			'page' => $page,
		];
	}//end degraded()

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
		$companies = $response['results']['companies'] ?? null;
		if (is_array($companies) === true) {
			$out = [];
			foreach ($companies as $entry) {
				if (is_array($entry) === false) {
					continue;
				}

				if (isset($entry['company']) === true && is_array($entry['company']) === true) {
					$out[] = $entry['company'];
				} else {
					$out[] = $entry;
				}
			}

			return $out;
		}

		if (array_is_list($response) === true) {
			$out = [];
			foreach ($response as $row) {
				if (is_array($row) === true) {
					$out[] = $row;
				}
			}

			return $out;
		}

		return [];
	}//end extractRows()
}//end class
