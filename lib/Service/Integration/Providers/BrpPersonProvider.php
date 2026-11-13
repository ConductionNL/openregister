<?php

/**
 * BrpPersonProvider — person lookup against the Dutch BRP (basisregistratie
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
 * @spec openspec/changes/archive/2026-10-05-integration-brp-haalcentraal/tasks.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Integration\Providers;

use OCA\OpenRegister\Exception\ProviderUnavailableException;
use OCA\OpenRegister\Service\Integration\UpstreamRequest;
use OCA\OpenRegister\Kernel\Contracts\ObjectRef;
use OCA\OpenRegister\Service\Integration\AbstractIntegrationProvider;
use OCA\OpenRegister\Service\Integration\ExternalIntegrationRouterInterface;
use OCP\App\IAppManager;
use OCP\IConfig;
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
class BrpPersonProvider extends AbstractIntegrationProvider implements BrpPersonProviderInterface {

	use ConnectorRoutedProviderTrait;

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
	// The connector answers to `integriq` on development and `openconnector`
	// on beta/main; FleetAppId holds both spellings, so this names the app
	// rather than one spelling of it. Never compare it to an id directly —
	// go through FleetAppId, or the comparison is false on half the fleet.
	private const REQUIRED_APP = 'integriq';

	/**
	 * The source configuration flag that allows the residents count.
	 */
	public const RESIDENT_COUNT_SETTING = 'residentCount';

	/**
	 * Cause when the source does not allow the residents count.
	 */
	public const CAUSE_RESIDENT_COUNT_NOT_ENABLED = 'resident-count-not-enabled';

	/**
	 * Cause when an address object id is not 16 digits.
	 */
	public const CAUSE_INVALID_IDENTIFIER = 'invalid-identifier';

	/**
	 * Cause when the instance has no secret to key the family references with.
	 */
	public const CAUSE_NO_INSTANCE_SECRET = 'no-instance-secret';

	/**
	 * The relations `lookupFamily()` answers.
	 *
	 * @var array<int,string>
	 */
	public const FAMILY_RELATIONS = ['partner', 'child'];

	/**
	 * At most this many relatives are asked in the second call (design D1).
	 */
	private const MAX_RELATIVES = 20;

	/**
	 * Length of a family reference: a truncated hex HMAC (design D3).
	 */
	private const REFERENCE_LENGTH = 32;

	/**
	 * Fields of the resident call: the address and the relations (design D1).
	 *
	 * @var array<int,string>
	 */
	private const FAMILY_RESIDENT_FIELDS = [
		'burgerservicenummer',
		'verblijfplaats.adresseerbaarObjectIdentificatie',
		'partners',
		'kinderen',
	];

	/**
	 * Fields of the relatives call (design D1).
	 *
	 * @var array<int,string>
	 */
	private const FAMILY_RELATIVE_FIELDS = [
		'burgerservicenummer',
		'naam.volledigeNaam',
		'geboorte.datum',
		'overlijden',
		'verblijfplaats.adresseerbaarObjectIdentificatie',
	];

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
	 * @param ExternalIntegrationRouterInterface $router External-call router.
	 * @param IAppManager $appManager NC app manager (isEnabled check).
	 * @param IL10N $l10n Localisation.
	 * @param LoggerInterface $logger Logger for degraded paths (never receives a BSN).
	 * @param IConfig|null $config System config; its `secret` keys the family references.
	 *
	 * @return void
	 */
	public function __construct(
		private ExternalIntegrationRouterInterface $router,
		private IAppManager $appManager,
		private IL10N $l10n,
		private LoggerInterface $logger,
		private ?IConfig $config = null,
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
	 * Auth requirements descriptor. `type: 'external'` — the OAuth2
	 * client_credentials secret AND the PKIoverheid mutual-TLS client
	 * certificate are configured on the OpenConnector `brp-haalcentraal`
	 * source, not here. OpenRegister's admin UI surfaces the source's auth
	 * status and links out to OpenConnector to configure it. `supports`
	 * advertises the two transports `CallService` applies for this source.
	 *
	 * @return array<string,mixed>
	 *
	 * @spec openspec/changes/archive/2026-10-05-integration-brp-haalcentraal/tasks.md
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
	 * @param ObjectRef $ref The owning object: register, schema and uuid.
	 * @param array<string,mixed> $filters `_search` carries the BSN.
	 *
	 * @return array<int,array<string,mixed>>
	 *
	 * @throws ProviderUnavailableException When the source is missing/down —
	 *                                      the controller maps the cause.
	 *
	 * @spec openspec/changes/archive/2026-10-05-integration-brp-haalcentraal/tasks.md
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) register/schema/objectId
	 *   are mandated by the IntegrationProvider contract; this leaf is
	 *   object-independent so they are intentionally unused.
	 */
	public function list(ObjectRef $ref, array $filters = []): array {
		return $this->personenFor(bsn: trim((string)($filters['_search'] ?? '')));
	}//end list()

	/**
	 * The persons the BRP returns for one BSN; none for an empty BSN.
	 *
	 * @param string $bsn The BSN searched for, trimmed.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function personenFor(string $bsn): array {
		if ($bsn === '') {
			return [];
		}

		$response = $this->router->call(
			provider: $this,
			request: $this->personenRequest(body: $this->buildQueryBody(bsn: $bsn))
		);

		return $this->extractPersonen(response: $response);
	}//end personenFor()

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
				request: $this->personenRequest(body: $this->buildQueryBody(bsn: $bsn))
			);
		} catch (ProviderUnavailableException $e) {
			return $this->degraded(cause: $e->getCause());
		} catch (Throwable $e) {
			// Never include the BSN or the message body — only that a lookup failed.
			$this->logger->warning('BrpPersonProvider::lookupByBsn failed (transport).');
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
	 * A resident's current partner and living children, minimised.
	 *
	 * Two `RaadpleegMetBurgerservicenummer` calls (design D1): the resident
	 * with their address and relations, then the selected relatives (at most
	 * 20) with name, birth date, death and address. A partner whose
	 * partnership has ended and a deceased relative are dropped; with
	 * `$sameAddressOnly` so is a relative on another address. Per relative the
	 * answer holds an opaque reference, the display name, the relation, the
	 * birth year and `sameAddress` — never a BSN, a full birth date or an
	 * address. Degrades as `lookupByBsn()` does.
	 *
	 * @param string            $bsn             The resident's BSN.
	 * @param array<int,string> $relations       `partner` and/or `child`.
	 * @param bool              $sameAddressOnly Drop relatives on another address.
	 *
	 * @return array<string,mixed> `{ results, total, meta, relativesMeta }`, or
	 *                             `{ unavailable, cause, results, total }`.
	 *
	 * @spec openspec/specs/integration-person-lookup/spec.md
	 */
	public function lookupFamily(string $bsn, array $relations=self::FAMILY_RELATIONS, bool $sameAddressOnly=false): array {
		$bsn = trim($bsn);
		$relations = array_values(array_intersect(self::FAMILY_RELATIONS, $relations));
		if ($bsn === '' || $relations === []) {
			return ['results' => [], 'total' => 0, 'meta' => $this->emptyMeta(), 'relativesMeta' => null];
		}

		$secret = $this->instanceSecret();
		if ($secret === '') {
			return $this->degraded(cause: self::CAUSE_NO_INSTANCE_SECRET);
		}

		try {
			$residentCall = $this->familyCall(bsns: [$bsn], fields: self::FAMILY_RESIDENT_FIELDS);
			$resident = ($this->extractPersonen(response: ($residentCall['body'] ?? []))[0] ?? []);
			$candidates = array_intersect_key($this->currentRelatives(resident: $resident), array_flip($relations));
			$relationOf = $this->relationByBsn(candidates: $candidates);

			$relativesCall = null;
			$relatives = [];
			if ($relationOf !== []) {
				$relativesCall = $this->familyCall(bsns: array_map('strval', array_keys($relationOf)), fields: self::FAMILY_RELATIVE_FIELDS);
				$relatives = $this->extractPersonen(response: ($relativesCall['body'] ?? []));
			}
		} catch (ProviderUnavailableException $e) {
			return $this->degraded(cause: $e->getCause());
		} catch (Throwable $e) {
			// Never include a BSN or the message body — only that a lookup failed.
			$this->logger->warning('BrpPersonProvider::lookupFamily failed (transport).');
			return $this->degraded(cause: ProviderUnavailableException::CAUSE_UPSTREAM_SERVICE_DOWN);
		}//end try

		$results = $this->familyResults(
			residentBsn: $bsn,
			residentAddress: self::addressObjectOf(person: $resident),
			relatives: $relatives,
			relationOf: $relationOf,
			sameAddressOnly: $sameAddressOnly
		);

		$relativesMeta = null;
		if ($relativesCall !== null) {
			$relativesMeta = $this->shapeMeta(meta: ($relativesCall['meta'] ?? []));
		}

		return [
			'results' => $results,
			'total' => count($results),
			'meta' => $this->shapeMeta(meta: ($residentCall['meta'] ?? [])),
			'relativesMeta' => $relativesMeta,
		];
	}//end lookupFamily()

	/**
	 * Turn a family reference back into the relative's BSN.
	 *
	 * Repeats the resident call and recomputes the references of the current
	 * partner and children (design D3), so a reference from another resident's
	 * answer, or of someone who is no longer a relative, answers null. A
	 * source that cannot be reached answers null too.
	 *
	 * @param string $bsn       The resident's BSN.
	 * @param string $reference A reference `lookupFamily()` answered.
	 *
	 * @return string|null The relative's BSN, or null.
	 *
	 * @spec openspec/specs/integration-person-lookup/spec.md
	 */
	public function resolveFamilyReference(string $bsn, string $reference): ?string {
		$bsn = trim($bsn);
		$reference = strtolower(trim($reference));
		$secret = $this->instanceSecret();
		if ($bsn === '' || $secret === '' || preg_match('/^[0-9a-f]{' . self::REFERENCE_LENGTH . '}$/', $reference) !== 1) {
			return null;
		}

		try {
			$residentCall = $this->familyCall(bsns: [$bsn], fields: self::FAMILY_RESIDENT_FIELDS);
		} catch (Throwable $e) {
			$this->logger->warning('BrpPersonProvider::resolveFamilyReference failed (transport).');
			return null;
		}

		$resident = ($this->extractPersonen(response: ($residentCall['body'] ?? []))[0] ?? []);
		foreach (array_keys($this->relationByBsn(candidates: $this->currentRelatives(resident: $resident))) as $relativeBsn) {
			if (hash_equals($this->familyReference(residentBsn: $bsn, relativeBsn: (string)$relativeBsn, secret: $secret), $reference) === true) {
				return (string)$relativeBsn;
			}
		}

		return null;
	}//end resolveFamilyReference()

	/**
	 * One `RaadpleegMetBurgerservicenummer` call with its transport metadata.
	 *
	 * @param array<int,string> $bsns   The BSNs asked.
	 * @param array<int,string> $fields The fields asked.
	 *
	 * @return array<string,mixed> The router envelope: `body` and `meta`.
	 */
	private function familyCall(array $bsns, array $fields): array {
		return $this->router->callWithMeta(
			provider: $this,
			request: $this->personenRequest(
				body: [
					'type' => 'RaadpleegMetBurgerservicenummer',
					'burgerservicenummer' => array_values($bsns),
					'fields' => $fields,
				]
			)
		);
	}//end familyCall()

	/**
	 * The current partners and the children of a resident, by relation.
	 *
	 * A partner whose partnership has ended (`ontbindingHuwelijkPartnerschap`
	 * holds anything) is left out.
	 *
	 * @param array<string,mixed> $resident The resident as the BRP answered.
	 *
	 * @return array{partner: array<int,array<string,mixed>>, child: array<int,array<string,mixed>>}
	 */
	private function currentRelatives(array $resident): array {
		$partners = [];
		foreach ((array)($resident['partners'] ?? []) as $partner) {
			if (is_array($partner) === true && empty($partner['ontbindingHuwelijkPartnerschap']) === true) {
				$partners[] = $partner;
			}
		}

		$children = array_values(array_filter((array)($resident['kinderen'] ?? []), 'is_array'));

		return ['partner' => $partners, 'child' => $children];
	}//end currentRelatives()

	/**
	 * Each relative's BSN with its relation, at most MAX_RELATIVES, first relation wins.
	 *
	 * @param array<string,array<int,array<string,mixed>>> $candidates Relatives by relation.
	 *
	 * @return array<string,string> BSN => relation.
	 */
	private function relationByBsn(array $candidates): array {
		$relationOf = [];
		foreach ($candidates as $relation => $people) {
			foreach ($people as $person) {
				$relativeBsn = trim((string)($person['burgerservicenummer'] ?? ''));
				if ($relativeBsn === '' || isset($relationOf[$relativeBsn]) === true) {
					continue;
				}

				$relationOf[$relativeBsn] = (string)$relation;
			}
		}

		return array_slice($relationOf, 0, self::MAX_RELATIVES, true);
	}//end relationByBsn()

	/**
	 * The minimised answer rows for the relatives the second call returned.
	 *
	 * @param string                         $residentBsn     The resident's BSN (keys the references).
	 * @param string                         $residentAddress The resident's address object id, or ''.
	 * @param array<int,array<string,mixed>> $relatives       The relatives as the BRP answered.
	 * @param array<string,string>           $relationOf      BSN => relation, of the relatives asked.
	 * @param bool                           $sameAddressOnly Drop relatives on another address.
	 *
	 * @return array<int,array{reference: string, name: string, relation: string, birthYear: int|null, sameAddress: bool}>
	 */
	private function familyResults(string $residentBsn, string $residentAddress, array $relatives, array $relationOf, bool $sameAddressOnly): array {
		$secret = $this->instanceSecret();
		$results = [];
		foreach ($relatives as $relative) {
			$relativeBsn = trim((string)($relative['burgerservicenummer'] ?? ''));
			if (isset($relationOf[$relativeBsn]) === false || empty($relative['overlijden']) === false) {
				continue;
			}

			$address = self::addressObjectOf(person: $relative);
			$sameAddress = ($residentAddress !== '' && $address === $residentAddress);
			if ($sameAddressOnly === true && $sameAddress === false) {
				continue;
			}

			$results[] = [
				'reference' => $this->familyReference(residentBsn: $residentBsn, relativeBsn: $relativeBsn, secret: $secret),
				'name' => self::displayName(person: $relative),
				'relation' => $relationOf[$relativeBsn],
				'birthYear' => self::birthYearOf(person: $relative),
				'sameAddress' => $sameAddress,
			];
		}//end foreach

		return $results;
	}//end familyResults()

	/**
	 * The reference of one relative: HMAC-SHA256 over resident and relative, truncated (design D3).
	 *
	 * @param string $residentBsn The resident's BSN.
	 * @param string $relativeBsn The relative's BSN.
	 * @param string $secret      The instance secret.
	 *
	 * @return string 32 hex characters.
	 */
	private function familyReference(string $residentBsn, string $relativeBsn, string $secret): string {
		return substr(hash_hmac('sha256', $residentBsn . ':' . $relativeBsn, $secret), 0, self::REFERENCE_LENGTH);
	}//end familyReference()

	/**
	 * The instance secret that keys the family references, or '' without one.
	 *
	 * @return string
	 */
	private function instanceSecret(): string {
		if ($this->config === null) {
			return '';
		}

		return (string)$this->config->getSystemValueString('secret', '');
	}//end instanceSecret()

	/**
	 * A person's address object id, or ''.
	 *
	 * @param array<string,mixed> $person The person as the BRP answered.
	 *
	 * @return string
	 */
	private static function addressObjectOf(array $person): string {
		$place = ($person['verblijfplaats'] ?? []);
		if (is_array($place) === false) {
			return '';
		}

		$identifier = ($place['adresseerbaarObjectIdentificatie'] ?? ($place['verblijfadres']['adresseerbaarObjectIdentificatie'] ?? ''));

		return trim((string)$identifier);
	}//end addressObjectOf()

	/**
	 * A person's display name: `naam.volledigeNaam`, else the name parts.
	 *
	 * @param array<string,mixed> $person The person as the BRP answered.
	 *
	 * @return string
	 */
	private static function displayName(array $person): string {
		$name = ($person['naam'] ?? []);
		if (is_array($name) === false) {
			return trim((string)$name);
		}

		$full = trim((string)($name['volledigeNaam'] ?? ''));
		if ($full !== '') {
			return $full;
		}

		$parts = [($name['voornamen'] ?? ''), ($name['voorvoegsel'] ?? ''), ($name['geslachtsnaam'] ?? '')];

		return trim(implode(' ', array_filter(array_map('strval', $parts), static fn (string $part): bool => trim($part) !== '')));
	}//end displayName()

	/**
	 * A person's birth year, from a full or a partial BRP date, or null.
	 *
	 * @param array<string,mixed> $person The person as the BRP answered.
	 *
	 * @return int|null
	 */
	private static function birthYearOf(array $person): ?int {
		$date = ($person['geboorte']['datum'] ?? null);
		if (is_array($date) === true) {
			if (isset($date['jaar']) === true && is_numeric($date['jaar']) === true) {
				return (int)$date['jaar'];
			}

			$date = ($date['datum'] ?? null);
		}

		if (is_string($date) === true && preg_match('/^(\d{4})/', $date, $match) === 1) {
			return (int)$match[1];
		}

		return null;
	}//end birthYearOf()

	/**
	 * Count the people the BRP registers at an address object.
	 *
	 * Issues `ZoekMetAdresseerbaarObjectIdentificatie` with one field and
	 * keeps only the number of results. Asked only when the source carries
	 * `configuration.residentCount: true`; the call reaches the connector with
	 * `withholdBodies`, so the residents' numbers are never stored. Neither the
	 * answer nor the log holds a person or the address object id.
	 *
	 * @param string $addressObjectId The 16-digit adresseerbaarObjectIdentificatie.
	 *
	 * @return array<string,mixed> `{ total, meta }`, or `{ unavailable, cause }`.
	 *
	 * @spec openspec/specs/integration-person-lookup/spec.md#requirement-brp-count-of-residents-at-an-address-object
	 */
	public function countResidentsAtAddressObject(string $addressObjectId): array {
		$addressObjectId = trim($addressObjectId);
		if (preg_match('/^\d{16}$/', $addressObjectId) !== 1) {
			return ['unavailable' => true, 'cause' => self::CAUSE_INVALID_IDENTIFIER];
		}

		try {
			$configuration = $this->router->sourceConfiguration(provider: $this);
			if (($configuration[self::RESIDENT_COUNT_SETTING] ?? null) !== true) {
				return ['unavailable' => true, 'cause' => self::CAUSE_RESIDENT_COUNT_NOT_ENABLED];
			}

			$envelope = $this->router->callWithMeta(
				provider: $this,
				request: $this->personenRequest(
					body: [
						'type' => 'ZoekMetAdresseerbaarObjectIdentificatie',
						'adresseerbaarObjectIdentificatie' => $addressObjectId,
						'fields' => ['burgerservicenummer'],
					],
					extraOptions: ['withholdBodies' => true]
				)
			);
		} catch (ProviderUnavailableException $e) {
			return ['unavailable' => true, 'cause' => $e->getCause()];
		} catch (Throwable $e) {
			$this->logger->warning('BrpPersonProvider::countResidentsAtAddressObject failed (transport).');
			return ['unavailable' => true, 'cause' => ProviderUnavailableException::CAUSE_UPSTREAM_SERVICE_DOWN];
		}//end try

		return [
			'total' => count($this->extractPersonen(response: ($envelope['body'] ?? []))),
			'meta' => $this->shapeMeta(meta: ($envelope['meta'] ?? [])),
		];
	}//end countResidentsAtAddressObject()

	/**
	 * A HAL+JSON query to the BRP `personen` endpoint.
	 *
	 * @param array<string,mixed> $body         The query body.
	 * @param array<string,mixed> $extraOptions Further connector options, after the body and headers.
	 *
	 * @return UpstreamRequest
	 */
	private function personenRequest(array $body, array $extraOptions=[]): UpstreamRequest {
		return new UpstreamRequest(
			method: 'POST',
			path: 'personen',
			options: array_merge(
				[
					'body' => $body,
					'headers' => ['Accept' => 'application/hal+json', 'Content-Type' => 'application/json'],
				],
				$extraOptions
			)
		);
	}//end personenRequest()

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
		$out = [];
		foreach (self::personenList(response: $response) as $row) {
			if (is_array($row) === true) {
				$out[] = $row;
			}
		}

		return $out;
	}//end extractPersonen()

	/**
	 * The person list of a response: `personen`, else `_embedded.personen`, else a bare list, else none.
	 *
	 * @param array<string,mixed> $response Decoded source response (HAL+JSON).
	 *
	 * @return array<mixed> The rows, not yet checked to be objects.
	 */
	private static function personenList(array $response): array {
		$direct = self::listUnder(container: $response, key: 'personen');
		if ($direct !== null) {
			return $direct;
		}

		$embedded = self::listUnder(container: ($response['_embedded'] ?? null), key: 'personen');
		if ($embedded !== null) {
			return $embedded;
		}

		if (array_is_list($response) === true) {
			return $response;
		}

		return [];
	}//end personenList()

	/**
	 * The array a container holds under a key, or null when the container or the value is not an array.
	 *
	 * @param mixed  $container The envelope, or part of it.
	 * @param string $key       The key.
	 *
	 * @return array<mixed>|null
	 */
	private static function listUnder(mixed $container, string $key): ?array {
		if (is_array($container) === false || isset($container[$key]) === false || is_array($container[$key]) === false) {
			return null;
		}

		return $container[$key];
	}//end listUnder()
}//end class
