<?php

/**
 * OpenRegister AppHost — Generic Store Service
 *
 * Engine-owned client for the ADR-080 store plane. A "store" is a remote
 * OpenRegister instance exposing installable items over its objects API; this
 * service is the DISCOVERY half of the contract (configure / search / resolve).
 * INSTALL is deliberately NOT here — cloning an application template, enabling
 * a connector adapter and instantiating an agent template are different
 * operations with different authorization, so each app keeps its own install
 * action and calls resolve() for the payload.
 *
 * It also carries the one WRITE the plane allows: publish() sends one object
 * of the descriptor's schema to the registry, under the same guard chain as
 * discovery, so no leaf app builds an objects-API URL of its own (hydra gate
 * 62). A descriptor publishes only when it names the fields that may travel
 * and the groups that may send them; every older descriptor stays read-only.
 *
 * Generalised from openbuild's RemoteTemplateStoreService (ADR-080 Context).
 * That implementation reached OpenRegister's SSRF guard through a dynamic
 * class-string with a weaker local fallback, because it lived in the wrong app.
 * Here the guard is a direct call: in OpenRegister the class is always present,
 * so there is no degraded path to get wrong.
 *
 * Security (ADR-005 / ADR-080):
 *   - Every outbound URL is SSRF-guarded by SecurityService::assertSafeFetchUrl
 *     (rejects private/reserved/loopback hosts and non-http(s) schemes),
 *     fail-closed.
 *   - Redirects are NEVER followed. assertSafeFetchUrl validates the URL at one
 *     point in time; following a 3xx would let a public host redirect to a
 *     private/link-local/metadata address (or exploit DNS rebinding between
 *     validation and connect) with the registry Bearer token attached.
 *   - The token is sent only as a Bearer header and is never returned to
 *     callers; upstream errors are logged server-side and mapped to generic
 *     outcomes so a registry's internals never reach the browser.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\AppHost\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/specs/apphost-store-plane/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\AppHost\Service;

use OCA\OpenRegister\AppHost\Store\StorePublishRules;
use OCA\OpenRegister\Service\SecurityService;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Client for a remote OpenRegister-backed store (ADR-080): discovery, plus a
 * guarded publish for descriptors that opted in.
 *
 * @spec openspec/specs/apphost-store-plane/spec.md
 */
class GenericStoreService {
	/**
	 * Outcome: the request succeeded.
	 */
	public const OUTCOME_OK = 'ok';

	/**
	 * Outcome: no registry configured — no network call was made.
	 */
	public const OUTCOME_NOT_CONFIGURED = 'not_configured';

	/**
	 * Outcome: registry unreachable / timed out / non-2xx / redirected.
	 */
	public const OUTCOME_UNREACHABLE = 'store_unreachable';

	/**
	 * Outcome: registry returned an unparseable / unexpected body.
	 */
	public const OUTCOME_INVALID = 'store_invalid_response';

	/**
	 * Outcome: the source refused because a rate limit is in force.
	 *
	 * 🔴 NOT INTERCHANGEABLE WITH `store_unreachable`. The remedy differs and
	 * the reader acts on it: rate limited means wait, or add a credential to
	 * raise the limit; unreachable means the network or the registry is
	 * broken. Reporting the first as the second sends somebody to debug a
	 * network that is fine.
	 */
	public const OUTCOME_RATE_LIMITED = 'rate_limited';

	/**
	 * Outcome: the registry answered a publish and refused the object (4xx).
	 *
	 * Split from `store_unreachable` for the same reason `rate_limited` is:
	 * a refused object means fix the payload or the token's rights, an
	 * unreachable registry means fix the network or the server.
	 */
	public const OUTCOME_REJECTED = 'store_rejected';

	/**
	 * Outcome: the publish body is larger than the plane sends.
	 */
	public const OUTCOME_TOO_LARGE = 'too_large';

	/**
	 * Outcome: the descriptor did not opt in to publishing, or the payload
	 * carries no valid slug. No request was made.
	 */
	public const OUTCOME_NOT_PUBLISHABLE = 'not_publishable';

	/**
	 * Connect + request timeout (seconds) for every remote fetch.
	 */
	private const TIMEOUT = 10;

	/**
	 * Largest publish body, as JSON, the plane sends (20 MiB).
	 */
	private const PUBLISH_MAX_BYTES = 20971520;

	/**
	 * Maximum cards returned by a single search.
	 */
	private const SEARCH_LIMIT = 50;

	/**
	 * Constructor.
	 *
	 * @param IClientService $clientService Nextcloud HTTP client factory.
	 * @param IAppConfig $appConfig App config store (registry url / token / register).
	 * @param LoggerInterface $logger PSR logger — server-side diagnostics only.
	 * @param StorePublishRules $publishRules The pure body and outcome rules of publish().
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IClientService $clientService,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
		private readonly StorePublishRules $publishRules = new StorePublishRules(),
	) {
	}//end __construct()

	/**
	 * Whether a remote registry is configured for this store (non-empty base URL).
	 *
	 * @param StoreDescriptor $descriptor The calling app's store parameters.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/apphost-store-plane/spec.md
	 */
	public function isConfigured(StoreDescriptor $descriptor): bool {
		return trim($this->registryUrl(descriptor: $descriptor)) !== '';
	}//end isConfigured()

	/**
	 * Search the remote store.
	 *
	 * Returns `not_configured` with an empty card list and makes NO network
	 * call when no registry is set — the ADR-080 Decision 4 fallback that lets
	 * a store page render the app's built-in items instead.
	 *
	 * @param StoreDescriptor $descriptor The calling app's store parameters.
	 * @param string|null $query Optional free-text search term.
	 * @param string|null $kind Optional `kind` discriminator filter (ADR-080 Decision 5).
	 *
	 * @return array{outcome: string, cards: array<int, array<string, mixed>>}
	 *
	 * @spec openspec/specs/apphost-store-plane/spec.md
	 */
	public function search(StoreDescriptor $descriptor, ?string $query = null, ?string $kind = null): array {
		if ($this->isConfigured(descriptor: $descriptor) === false) {
			return ['outcome' => self::OUTCOME_NOT_CONFIGURED, 'cards' => []];
		}

		$params = ['_limit' => self::SEARCH_LIMIT];
		if ($query !== null && trim($query) !== '') {
			$params['_search'] = trim($query);
		}

		if ($kind !== null && trim($kind) !== '') {
			$params['kind'] = trim($kind);
		}

		$result = $this->fetch(descriptor: $descriptor, params: $params);
		if ($result['outcome'] !== self::OUTCOME_OK) {
			return ['outcome' => $result['outcome'], 'cards' => []];
		}

		$cards = [];
		foreach ($result['results'] as $object) {
			if (is_array($object) === true) {
				$cards[] = $this->normaliseCard(descriptor: $descriptor, object: $object);
			}
		}

		return ['outcome' => self::OUTCOME_OK, 'cards' => $cards];
	}//end search()

	/**
	 * Resolve a single remote item by slug, returning its FULL payload for the
	 * calling app's install action.
	 *
	 * @param StoreDescriptor $descriptor The calling app's store parameters.
	 * @param string $slug The item slug.
	 *
	 * @return array<string, mixed>|null The full remote object, or null when unresolved / on error.
	 *
	 * @spec openspec/specs/apphost-store-plane/spec.md
	 */
	public function resolve(StoreDescriptor $descriptor, string $slug): ?array {
		if ($this->isConfigured(descriptor: $descriptor) === false) {
			return null;
		}

		$result = $this->fetch(descriptor: $descriptor, params: ['slug' => $slug, '_limit' => 1]);
		if ($result['outcome'] !== self::OUTCOME_OK) {
			return null;
		}

		foreach ($result['results'] as $object) {
			// Compare the slug the registry actually returned rather than
			// trusting the filter: a registry that ignores an unknown query
			// param would otherwise hand back an arbitrary first row.
			if (is_array($object) === true && (string)($object['slug'] ?? '') === $slug) {
				return $object;
			}
		}

		return null;
	}//end resolve()

	/**
	 * Publish one object of the descriptor's schema to the configured registry.
	 *
	 * Refuses, without building a client, a descriptor that did not opt in, a
	 * payload with no valid slug, an unconfigured store and an oversized body.
	 * The body is the slug plus the descriptor's `publishFields`, never an
	 * identity key (StorePublishRules). A 2xx counts only when the object the
	 * registry returns carries the slug that was sent.
	 *
	 * WHO may publish is not decided here: the caller asks
	 * StoreActionAuthorizer::canPublish() first, as the install route asks its
	 * posture before calling the installer. This method stays session-free.
	 *
	 * @param StoreDescriptor      $descriptor The calling app's store parameters.
	 * @param array<string, mixed> $payload    The object to publish; must carry `slug`.
	 *
	 * @return array{outcome: string, slug: string} The slug is empty on every failure.
	 *
	 * @spec openspec/specs/apphost-store-plane/spec.md#requirement-a-publish-must-travel-under-the-planes-transport-rules
	 */
	public function publish(StoreDescriptor $descriptor, array $payload): array {
		$refused = ['outcome' => self::OUTCOME_NOT_PUBLISHABLE, 'slug' => ''];
		if ($descriptor->isPublishable() === false) {
			// Logged at ERROR: an app called publish() without declaring what
			// may leave or who may send it, which is a defect to fix rather
			// than a user being told no.
			$this->logger->error(
				'AppHost store (' . $descriptor->appId . '): publish refused, the descriptor names no publish fields or no publish group'
			);
			return $refused;
		}

		$json = $this->publishRules->encodedBody(descriptor: $descriptor, payload: $payload);
		if ($json === null) {
			$this->logger->warning(
				'AppHost store (' . $descriptor->appId . '): publish refused, the payload has no valid slug or does not encode as JSON'
			);
			return $refused;
		}

		if ($this->isConfigured(descriptor: $descriptor) === false) {
			return ['outcome' => self::OUTCOME_NOT_CONFIGURED, 'slug' => ''];
		}

		if (strlen($json) > self::PUBLISH_MAX_BYTES) {
			return ['outcome' => self::OUTCOME_TOO_LARGE, 'slug' => ''];
		}

		$response = $this->send(
			descriptor: $descriptor,
			method: 'POST',
			options: [
				'body' => $json,
				'headers' => ['Content-Type' => 'application/json', 'Accept' => 'application/json'],
			]
		);
		if ($response === null) {
			return ['outcome' => self::OUTCOME_UNREACHABLE, 'slug' => ''];
		}

		// The slug is valid here: encodedBody() refuses a payload without one.
		return $this->publishOutcome(descriptor: $descriptor, response: $response, slug: (string)$payload['slug']);
	}//end publish()

	/**
	 * Map the registry's answer to a publish outcome, logging every failure.
	 *
	 * @param StoreDescriptor $descriptor The calling app's store parameters.
	 * @param IResponse       $response   The registry's answer.
	 * @param string          $slug       The slug that was sent.
	 *
	 * @return array{outcome: string, slug: string}
	 *
	 * @spec openspec/specs/apphost-store-plane/spec.md#requirement-a-publish-must-verify-the-slug-the-registry-stored
	 */
	private function publishOutcome(StoreDescriptor $descriptor, IResponse $response, string $slug): array {
		$status = $response->getStatusCode();
		if ($this->publishRules->isSuccess(status: $status) === false) {
			$this->logger->warning(
				'AppHost store (' . $descriptor->appId . '): registry answered the publish with HTTP ' . $status
			);
			return ['outcome' => $this->publishRules->failureOutcome(status: $status), 'slug' => ''];
		}

		// Compare what the registry actually stored. A registry that renamed
		// the object would leave the app pointing at a slug that resolves to
		// nothing, or to somebody else's item.
		$stored = $this->publishRules->storedObject(body: (string)$response->getBody());
		$storedSlug = ($stored['slug'] ?? null);
		if ($storedSlug !== $slug) {
			$this->logger->warning(
				'AppHost store (' . $descriptor->appId . '): registry answered the publish with '
				. json_encode($storedSlug) . ' as the stored slug, not "' . $slug . '"'
			);
			return ['outcome' => self::OUTCOME_INVALID, 'slug' => ''];
		}

		return ['outcome' => self::OUTCOME_OK, 'slug' => $slug];
	}//end publishOutcome()

	/**
	 * Perform the SSRF-guarded, redirect-refusing GET against the remote
	 * store's objects API.
	 *
	 * @param StoreDescriptor $descriptor The calling app's store parameters.
	 * @param array<string, mixed> $params Query params merged into the request.
	 *
	 * @return array{outcome: string, results: array<int, mixed>}
	 */
	private function fetch(StoreDescriptor $descriptor, array $params): array {
		$response = $this->send(descriptor: $descriptor, method: 'GET', options: ['query' => $params]);
		if ($response === null) {
			return ['outcome' => self::OUTCOME_UNREACHABLE, 'results' => []];
		}

		$status = $response->getStatusCode();
		if ($status < 200 || $status >= 300) {
			$this->logger->warning(
				'AppHost store (' . $descriptor->appId . '): registry returned HTTP ' . $status
			);
			return ['outcome' => self::OUTCOME_UNREACHABLE, 'results' => []];
		}

		return $this->decodeBody(descriptor: $descriptor, body: (string)$response->getBody());
	}//end fetch()

	/**
	 * Send one request to the remote store's objects API under the plane's
	 * transport rules: SSRF guard first, no redirects, fixed timeouts, and the
	 * token only as a Bearer header. Shared by discovery and publish so the
	 * guard chain exists once.
	 *
	 * The caller's options cannot loosen the rules: the timeouts and the
	 * redirect refusal are applied after them, and so is the Authorization
	 * header.
	 *
	 * @param StoreDescriptor      $descriptor The calling app's store parameters.
	 * @param string               $method     'GET' or 'POST'.
	 * @param array<string, mixed> $options    Request options (query, body, headers).
	 *
	 * @return IResponse|null The answer, or null when the URL was refused or the request failed.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) SecurityService::assertSafeFetchUrl is
	 * static upstream, and calling it directly is the point of moving this client
	 * into OpenRegister — the previous app-local copy reached it through a dynamic
	 * class-string with a weaker fallback (ADR-080 Context).
	 *
	 * @spec openspec/specs/apphost-store-plane/spec.md#requirement-a-publish-must-travel-under-the-planes-transport-rules
	 */
	private function send(StoreDescriptor $descriptor, string $method, array $options): ?IResponse {
		try {
			$url = $this->buildUrl(descriptor: $descriptor);
			SecurityService::assertSafeFetchUrl($url);
		} catch (Throwable $e) {
			$this->logger->warning(
				'AppHost store (' . $descriptor->appId . '): rejected unsafe/invalid registry URL: ' . $e->getMessage()
			);
			return null;
		}

		$headers = (array)($options['headers'] ?? []);
		$token = trim($this->appConfig->getValueString($descriptor->appId, 'registry_token', ''));
		if ($token !== '') {
			$headers['Authorization'] = 'Bearer ' . $token;
		}

		$options['headers'] = $headers;

		$options['timeout'] = self::TIMEOUT;
		$options['connect_timeout'] = self::TIMEOUT;
		$options['allow_redirects'] = false;

		try {
			$client = $this->clientService->newClient();
			if ($method === 'POST') {
				return $client->post($url, $options);
			}

			return $client->get($url, $options);
		} catch (Throwable $e) {
			$this->logger->warning(
				'AppHost store (' . $descriptor->appId . '): registry ' . $method . ' failed: ' . $e->getMessage()
			);
			return null;
		}
	}//end send()

	/**
	 * Decode a registry response body into a result list.
	 *
	 * Split out of fetch() so neither method carries the whole guard chain:
	 * an unparseable body is a DIFFERENT outcome from an unreachable registry,
	 * and collapsing the two would make a misconfigured store look offline.
	 *
	 * @param StoreDescriptor $descriptor The calling app's store parameters.
	 * @param string $body The raw response body.
	 *
	 * @return array{outcome: string, results: array<int, mixed>}
	 */
	private function decodeBody(StoreDescriptor $descriptor, string $body): array {
		$decoded = json_decode($body, true);
		if (json_last_error() !== JSON_ERROR_NONE || is_array($decoded) === false) {
			$this->logger->warning(
				'AppHost store (' . $descriptor->appId . '): registry returned an unparseable body'
			);
			return ['outcome' => self::OUTCOME_INVALID, 'results' => []];
		}

		$results = ($decoded['results'] ?? null);
		if (is_array($results) === true) {
			return ['outcome' => self::OUTCOME_OK, 'results' => $results];
		}

		// Some OpenRegister responses are a bare list; accept that too.
		// A non-list decode is treated as no results rather than passed through:
		// callers iterate this, and handing them an associative array would
		// iterate its VALUES as if they were records.
		$results = [];
		if (array_is_list($decoded) === true) {
			$results = $decoded;
		}

		return [
			'outcome' => self::OUTCOME_OK,
			'results' => $results,
		];

	}//end decodeBody()

	/**
	 * Build the remote objects-API URL for this store's schema.
	 *
	 * @param StoreDescriptor $descriptor The calling app's store parameters.
	 *
	 * @return string
	 */
	private function buildUrl(StoreDescriptor $descriptor): string {
		$base = rtrim(trim($this->registryUrl(descriptor: $descriptor)), '/');
		$register = trim(
			$this->appConfig->getValueString($descriptor->appId, 'registry_register', $descriptor->defaultRegister)
		);
		if ($register === '') {
			$register = $descriptor->defaultRegister;
		}

		return $base
			. '/index.php/apps/openregister/api/objects/'
			. rawurlencode($register) . '/'
			. rawurlencode($descriptor->schema);

	}//end buildUrl()

	/**
	 * Flatten a remote object to a search card using the descriptor's field
	 * map. Never carries the install payload, and never a credential.
	 *
	 * @param StoreDescriptor $descriptor The calling app's store parameters.
	 * @param array<string, mixed> $object The remote object.
	 *
	 * @return array<string, mixed>
	 */
	private function normaliseCard(StoreDescriptor $descriptor, array $object): array {
		$card = [];
		foreach ($descriptor->cardFields as $field => $property) {
			$card[$field] = (string)($object[$property] ?? '');
		}

		// `kind` drives the store page's quick-filters (ADR-080 Decision 5) and
		// is always present so the frontend can group without a null check.
		$card['kind'] = (string)($object['kind'] ?? '');

		return $card;
	}//end normaliseCard()

	/**
	 * The configured registry base URL for this store.
	 *
	 * @param StoreDescriptor $descriptor The calling app's store parameters.
	 *
	 * @return string
	 */
	private function registryUrl(StoreDescriptor $descriptor): string {
		return $this->appConfig->getValueString($descriptor->appId, 'registry_url', '');
	}//end registryUrl()
}//end class
