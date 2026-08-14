<?php

/**
 * BrpPersoonProvider — person lookup against the Dutch BRP (basisregistratie
 * personen) via the RvIG HaalCentraal Personen v2.0 API, exposed through the
 * IntegrationProvider contract.
 *
 * Storage strategy is `external` (AD-4 / AD-22): there is no local link
 * table — this is a stateless, read-only person-lookup leaf. Every upstream
 * call is delegated to {@see ExternalIntegrationRouter}, which resolves the
 * declared OpenConnector source (`brp-haalcentraal`), makes the call, and
 * surfaces structured failures via {@see ProviderUnavailableException}
 * (AD-23). The provider never carries an HTTP client and never handles
 * credentials or certificates — the OAuth2 client_credentials secret AND the
 * PKIoverheid mutual-TLS client certificate live on the OpenConnector
 * `brp-haalcentraal` source (`auth: oauth`, with a `Bearer {{ oauthToken(source) }}`
 * Authorization header rendered per-call and `configuration.cert` /
 * `configuration.ssl_key` written to temp files for the Guzzle TLS handshake —
 * AD-15). OpenConnector's `CallService` already supports both transports
 * natively, so no bespoke gov-gateway code leaks into OpenRegister.
 *
 * Centralises pipelinq's bespoke `OCA\Pipelinq\Service\HaalCentraalClient`
 * (OAuth2 token acquisition + mTLS + base URL) onto the canonical
 * OR/OpenConnector path (ADR-022). The BRP→domain field mapping
 * (`naam` / `geboorte` / `verblijfplaats` normalisation, geslacht-code
 * mapping, BSN validation / elfproef, BSN masking for logs) stays in the
 * consuming app (pipelinq `HaalCentraalClient::normalisePerson` +
 * `BsnValidationService`); this leaf round-trips the raw HAL+JSON person
 * object exactly as HaalCentraal returns it.
 *
 * Surfaces one read operation on top of the registry contract:
 *   - {@see lookupByBsn()} — POST a `RaadpleegMetBurgerservicenummer`
 *     query to the HaalCentraal `/personen` endpoint and return the first
 *     matched raw person object. Degrades null-safely to
 *     `{ unavailable, cause }` rather than throwing (AD-23).
 *
 * Privacy: the BSN is carried in the request BODY only (never the path → no
 * SSRF surface, no BSN in upstream access logs), and this leaf never logs the
 * BSN or the request/response body — only the cause vocabulary on a degraded
 * path. The consuming app is responsible for elfproef validation and for
 * masking the BSN before any of its own logging.
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
 * @spec openspec/changes/integration-brp-haalcentraal/tasks.md
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
 * BRP HaalCentraal person-lookup integration provider — external,
 * OpenConnector-backed (OAuth2 + mTLS on the source).
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Composes the external
 *   router + app manager + l10n + logger + the ProviderUnavailableException
 *   cause vocabulary; each is required for the degrade-don't-throw lookup
 *   surface (AD-23).
 */
class BrpPersoonProvider extends AbstractIntegrationProvider {

	/**
	 * OpenConnector source id this provider routes through.
	 *
	 * @var string
	 */
	public const SOURCE_ID = 'brp-haalcentraal';

	/**
	 * NC app that must be installed for this integration to function
	 * (it carries the OpenConnector source + credentials + cert).
	 *
	 * @var string
	 */
	private const REQUIRED_APP = 'openconnector';

	/**
	 * Default field set requested from HaalCentraal. The BRP API requires an
	 * explicit `fields` list on every query. Mirrors the field set pipelinq's
	 * `HaalCentraalClient` requests so the consuming app's normaliser receives
	 * the subtrees it expects.
	 *
	 * @var array<int,string>
	 */
	private const DEFAULT_FIELDS = [
		'burgerservicenummer',
		'naam.voornamen',
		'naam.voorletters',
		'naam.voorvoegsel',
		'naam.geslachtsnaam',
		'naam.adellijkeTitelPredicaat',
		'geboorte.datum',
		'geboorte.plaats',
		'geboorte.land',
		'geslacht',
		'verblijfplaats',
		'indicatieGeheim',
	];

	/**
	 * Constructor.
	 *
	 * @param ExternalIntegrationRouter $router External-call router.
	 * @param IAppManager $appManager NC app manager (isEnabled check).
	 * @param IL10N $l10n Localisation.
	 * @param LoggerInterface $logger Logger for degraded paths (never receives a BSN).
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
		return 'brp-haalcentraal';
	}//end getId()

	/**
	 * Human-readable label shown in the admin UI.
	 *
	 * @return string
	 */
	public function getLabel(): string {
		return $this->l10n->t('BRP Person Register (HaalCentraal)');
	}//end getLabel()

	/**
	 * MDI icon name for the tab / widget.
	 *
	 * @return string
	 */
	public function getIcon(): string {
		return 'AccountSearch';
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
	 * function — OpenConnector carries the `brp-haalcentraal` source +
	 * OAuth credentials + PKIoverheid client certificate.
	 *
	 * @return string|null
	 */
	public function getRequiredApp(): ?string {
		return self::REQUIRED_APP;
	}//end getRequiredApp()

	/**
	 * Storage strategy (AD-22) — `external`: no local link table; this is
	 * a stateless person-lookup leaf routed through OpenConnector.
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
	 * installed (it owns the `brp-haalcentraal` source + credentials + cert).
	 * The router still degrades gracefully if the source itself is missing or
	 * HaalCentraal is down.
	 *
	 * @return bool
	 */
	public function isEnabled(): bool {
		return $this->appManager->isInstalled(self::REQUIRED_APP);
	}//end isEnabled()

	/**
	 * Auth requirements descriptor. `type: 'external'` — the OAuth2
	 * client_credentials secret AND the PKIoverheid mutual-TLS client
	 * certificate are configured on the OpenConnector `brp-haalcentraal`
	 * source, not here. OpenRegister's admin UI surfaces the source's auth
	 * status and links out to OpenConnector to configure it. `supports`
	 * advertises the two transports `CallService` applies for this source.
	 *
	 * @return array<string,mixed>
	 *
	 * @spec openspec/changes/integration-brp-haalcentraal/tasks.md
	 */
	public function authRequirements(): array {
		return [
			'type' => 'external',
			'configuredVia' => 'openconnector',
			'source' => self::SOURCE_ID,
			'supports' => ['oauth2_client_credentials', 'mtls'],
		];
	}//end authRequirements()

	/**
	 * List persons matching a BSN — the registry read-path. This leaf is
	 * object-independent (person lookup is not bound to an OR object), so the
	 * register/schema/objectId context is ignored; the `_search` filter
	 * carries the BSN. Returns a flat list of raw HaalCentraal person objects;
	 * the consumer maps + validates them (pipelinq `HaalCentraalClient` +
	 * `BsnValidationService`).
	 *
	 * @param string $register Ignored (object-independent leaf).
	 * @param string $schema Ignored (object-independent leaf).
	 * @param string $objectId Ignored (object-independent leaf).
	 * @param array<string,mixed> $filters `_search` carries the BSN.
	 *
	 * @return array<int,array<string,mixed>>
	 *
	 * @throws ProviderUnavailableException When the source is missing/down —
	 *                                      the controller maps the cause.
	 *
	 * @spec openspec/changes/integration-brp-haalcentraal/tasks.md
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) register/schema/objectId
	 *   are mandated by the IntegrationProvider contract; this leaf is
	 *   object-independent so they are intentionally unused.
	 */
	public function list(string $register, string $schema, string $objectId, array $filters = []): array {
		$bsn = trim((string)($filters['_search'] ?? ''));
		if ($bsn === '') {
			return [];
		}

		$response = $this->router->call(
			provider: $this,
			method: 'POST',
			path: 'personen',
			options: [
				'body' => $this->buildQueryBody(bsn: $bsn),
				'headers' => ['Accept' => 'application/hal+json', 'Content-Type' => 'application/json'],
			]
		);

		return $this->extractPersonen(response: $response);
	}//end list()

	/**
	 * Look up a single person by Burgerservicenummer (BSN).
	 *
	 * Issues a `RaadpleegMetBurgerservicenummer` POST to the HaalCentraal
	 * `/personen` endpoint (the same surface pipelinq's `HaalCentraalClient`
	 * uses) and returns the raw HAL+JSON person objects. Degrades null-safely
	 * to `{ unavailable, cause }` rather than throwing, so a consuming app
	 * never fatals on a missing/down source (AD-23).
	 *
	 * The BSN is carried in the request body only and is NEVER logged here —
	 * elfproef validation + BSN masking are the consuming app's responsibility.
	 *
	 * @param string $bsn The 9-digit Burgerservicenummer.
	 *
	 * @return array<string,mixed> `{ results, total, meta }` on success
	 *                             (results is the raw HaalCentraal person
	 *                             object list — 0 or 1 entries; `meta` carries
	 *                             the Wet-BRP audit metadata
	 *                             `{ correlationId, durationMs, status }`), or
	 *                             `{ unavailable, cause, results, total }`
	 *                             when the source is unconfigured/down.
	 *
	 * @spec openspec/changes/integration-brp-audit-metadata/tasks.md
	 */
	public function lookupByBsn(string $bsn): array {
		$bsn = trim($bsn);
		if ($bsn === '') {
			return ['results' => [], 'total' => 0, 'meta' => $this->emptyMeta()];
		}

		try {
			$envelope = $this->router->callWithMeta(
				provider: $this,
				method: 'POST',
				path: 'personen',
				options: [
					'body' => $this->buildQueryBody(bsn: $bsn),
					'headers' => ['Accept' => 'application/hal+json', 'Content-Type' => 'application/json'],
				]
			);
		} catch (ProviderUnavailableException $e) {
			return $this->degraded(cause: $e->getCause());
		} catch (Throwable $e) {
			// Never include the BSN or the message body — only that a lookup failed.
			$this->logger->warning('BrpPersoonProvider::lookupByBsn failed (transport).');
			return $this->degraded(cause: ProviderUnavailableException::CAUSE_UPSTREAM_SERVICE_DOWN);
		}

		$personen = $this->extractPersonen(response: ($envelope['body'] ?? []));

		return [
			'results' => $personen,
			'total' => count($personen),
			'meta' => $this->shapeMeta(meta: ($envelope['meta'] ?? [])),
		];
	}//end lookupByBsn()

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
	 * Build the `RaadpleegMetBurgerservicenummer` query body for the
	 * HaalCentraal `/personen` POST. The BSN list + the explicit `fields`
	 * set are exactly what the API requires.
	 *
	 * @param string $bsn The 9-digit BSN to query.
	 *
	 * @return array<string,mixed> The decoded body (router/CallService
	 *                             JSON-encodes it for the request).
	 */
	private function buildQueryBody(string $bsn): array {
		return [
			'type' => 'RaadpleegMetBurgerservicenummer',
			'burgerservicenummer' => [$bsn],
			'fields' => self::DEFAULT_FIELDS,
		];
	}//end buildQueryBody()

	/**
	 * Shape the router's transport metadata into the leaf's stable `meta`
	 * contract consumed by the calling app's Wet-BRP audit record:
	 *   - `correlationId` ← the upstream `X-Correlation-ID` response header
	 *     (consumer persists it as `haalcentraalCorrelationId`)
	 *   - `durationMs`    ← the OpenConnector-measured round-trip duration
	 *     (consumer persists it as `responseDuurMs`)
	 *   - `status`        ← the upstream HTTP status code
	 *     (consumer persists it as the response status / `responseCode`)
	 *
	 * The BSN is never present in `meta` — only transport metadata.
	 *
	 * @param array<string,mixed> $meta The router's raw meta array.
	 *
	 * @return array{correlationId: ?string, durationMs: int, status: int}
	 */
	private function shapeMeta(array $meta): array {
		$correlationId = ($meta['correlationId'] ?? null);
		if ($correlationId !== null) {
			$correlationId = (string)$correlationId;
		}

		return [
			'correlationId' => $correlationId,
			'durationMs' => (int)($meta['durationMs'] ?? 0),
			'status' => (int)($meta['status'] ?? 0),
		];
	}//end shapeMeta()

	/**
	 * The empty `meta` shape used on the no-BSN short-circuit (no upstream
	 * call was made, so there is no correlation id / duration / status).
	 *
	 * @return array{correlationId: ?string, durationMs: int, status: int}
	 */
	private function emptyMeta(): array {
		return [
			'correlationId' => null,
			'durationMs' => 0,
			'status' => 0,
		];
	}//end emptyMeta()

	/**
	 * Build the degraded envelope mirroring the unconfigured-source
	 * descriptor so the lookup shape stays stable across success +
	 * degraded paths (AD-23).
	 *
	 * @param string $cause One of the ProviderUnavailableException CAUSE_* values.
	 *
	 * @return array<string,mixed>
	 */
	private function degraded(string $cause): array {
		return [
			'unavailable' => true,
			'cause' => $cause,
			'results' => [],
			'total' => 0,
		];
	}//end degraded()

	/**
	 * Pull the person objects out of a HaalCentraal response envelope.
	 * HaalCentraal v2 returns the matches under `personen` (or
	 * `_embedded.personen` on some gateways); a bare list is used as-is;
	 * anything else yields an empty list.
	 *
	 * @param array<string,mixed> $response Decoded source response (HAL+JSON).
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function extractPersonen(array $response): array {
		$list = [];
		if (isset($response['personen']) === true && is_array($response['personen']) === true) {
			$list = $response['personen'];
		} elseif (isset($response['_embedded']['personen']) === true
			&& is_array($response['_embedded']['personen']) === true
		) {
			$list = $response['_embedded']['personen'];
		} elseif (array_is_list($response) === true) {
			$list = $response;
		}

		$out = [];
		foreach ($list as $row) {
			if (is_array($row) === true) {
				$out[] = $row;
			}
		}

		return $out;
	}//end extractPersonen()
}//end class
