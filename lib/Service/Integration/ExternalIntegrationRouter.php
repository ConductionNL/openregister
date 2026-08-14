<?php

/**
 * ExternalIntegrationRouter — dispatch sub-resource calls for
 * `storage='external'` providers through OpenConnector.
 *
 * Per AD-4 external providers don't carry their own HTTP client.
 * They declare an OpenConnector source via `getOpenConnectorSource()`,
 * and this router resolves the source, makes the call, and surfaces
 * structured failures via `ProviderUnavailableException` per AD-23.
 *
 * Cause classification:
 *   - openconnector-down            — OpenConnector NC app is disabled or missing.
 *   - openconnector-source-missing  — the declared source id can't be found
 *                                     (typo, deleted, never created).
 *   - upstream-service-down         — the OpenConnector source exists but the
 *                                     remote service it points at is unreachable.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Integration
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
 * @spec openspec/changes/pluggable-integration-registry/tasks.md#task-4
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Integration;

use LogicException;
use OCA\OpenRegister\Exception\ProviderUnavailableException;
use OCP\App\IAppManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Routes external integrations' CRUD calls through OpenConnector.
 *
 * The router is intentionally thin: it knows how to talk to
 * OpenConnector's CallService / SourceMapper, classify the failure
 * mode when something goes wrong, and that's it. Per-provider
 * specifics (URL paths, payload shapes) live in the provider
 * implementations themselves — the router just provides the safe
 * transport.
 */
class ExternalIntegrationRouter {

	/**
	 * Cached availability flag for the OpenConnector NC app.
	 *
	 * Null means "not yet checked" — the first call resolves it.
	 *
	 * @var boolean|null
	 */
	private ?bool $connectorAvailable = null;

	/**
	 * Constructor.
	 *
	 * @param IAppManager $appManager NC app manager — used to
	 *                                detect whether OpenConnector
	 *                                is installed + enabled.
	 * @param ContainerInterface $container DI container — used to
	 *                                      lazily resolve OpenConnector's
	 *                                      SourceMapper / CallService.
	 * @param LoggerInterface $logger Logger for failure traces.
	 *
	 * @return void
	 */
	public function __construct(
		private IAppManager $appManager,
		private ContainerInterface $container,
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Dispatch a CRUD call against the provider's OpenConnector source.
	 *
	 * @param IntegrationProvider $provider The provider making the call.
	 *                                      MUST declare storage='external'
	 *                                      and a non-null
	 *                                      getOpenConnectorSource().
	 * @param string $method HTTP method ('GET' / 'POST' /
	 *                       'PUT' / 'PATCH' / 'DELETE').
	 * @param string $path Path relative to the source's
	 *                     base URL.
	 * @param array<string,mixed> $options Optional call options:
	 *                                     - query: array of query params
	 *                                     - body:  scalar or array body
	 *                                     - headers: extra request headers
	 *
	 * @return array<string,mixed> The decoded response body.
	 *
	 * @throws ProviderUnavailableException When OpenConnector or the
	 *                                      upstream service is
	 *                                      unavailable. Use
	 *                                      `getCause()` /
	 *                                      `getDetails()` to pick
	 *                                      the right user message.
	 *
	 * @spec openspec/changes/pluggable-integration-registry/tasks.md#task-4
	 */
	public function call(
		IntegrationProvider $provider,
		string $method,
		string $path,
		array $options = [],
	): array {
		$this->assertProviderIsExternal(provider: $provider);
		$this->assertOpenConnectorAvailable();

		$sourceId = (string)$provider->getOpenConnectorSource();
		$source = $this->loadSource(sourceId: $sourceId, providerId: $provider->getId());

		// Mock mode: when the resolved source is flagged
		// `configuration.mock === true`, short-circuit and return the canned
		// `configuration.mockResponse` body WITHOUT performing a real HTTP
		// call — so the KvK / OpenCorporates / BRP / SMS / WhatsApp leaves are
		// demonstrably functional end-to-end without real credentials. The
		// real path below stays 100% intact for non-mock sources; mock is
		// opt-in per source. {@see resolveMockBody()} for the resolution.
		$config = $this->readSourceConfiguration(source: $source);
		if (($config['mock'] ?? false) === true) {
			return $this->resolveMockBody(config: $config, sourceId: $sourceId);
		}

		try {
			return $this->invoke(source: $source, method: $method, path: $path, options: $options);
		} catch (ProviderUnavailableException $e) {
			// Already classified — surface as-is.
			throw $e;
		} catch (\Throwable $e) {
			// Anything else surfacing from OpenConnector / the upstream
			// is treated as an upstream failure. The original throwable
			// is wrapped so the caller can introspect if needed.
			$this->logger->error(
				sprintf(
					'[ExternalIntegrationRouter] upstream call failed for provider %s %s %s',
					$provider->getId(),
					$method,
					$path
				),
				['exception' => $e]
			);
			throw new ProviderUnavailableException(
				message: sprintf(
					'Upstream service for integration "%s" is unreachable.',
					$provider->getId()
				),
				cause: ProviderUnavailableException::CAUSE_UPSTREAM_SERVICE_DOWN,
				previous: $e
			);
		}//end try
	}//end call()

	/**
	 * Dispatch a call like {@see call()} but additionally surface the
	 * upstream response metadata (HTTP status, round-trip duration in
	 * milliseconds, and the response headers) alongside the decoded body.
	 *
	 * This is a general, foundation-safe superset of {@see call()}: any
	 * external leaf that needs to relay audit metadata to its consuming app
	 * (e.g. the BRP/HaalCentraal leaf, whose consumer persists the
	 * Wet-BRP-required `X-Correlation-ID` + response duration into its
	 * `brpLookupVerzoek` audit record) can route through this method instead
	 * of `call()`. `call()` is intentionally left untouched so existing
	 * leaves keep their lean body-only contract.
	 *
	 * The returned envelope is:
	 *   - `body` : the decoded upstream response body (identical to what
	 *              `call()` returns)
	 *   - `meta` : `{ status, durationMs, correlationId, headers }` — the
	 *              upstream HTTP status, the OpenConnector-measured round-trip
	 *              duration in milliseconds, the first `X-Correlation-ID`
	 *              response header (case-insensitive; null when absent), and a
	 *              flattened copy of the response headers.
	 *
	 * No request/response body or BSN is read into `meta` — only transport
	 * metadata. The same failure classification as `call()` applies.
	 *
	 * @param IntegrationProvider $provider The provider making the call.
	 * @param string $method HTTP method.
	 * @param string $path Path relative to the source base URL.
	 * @param array<string,mixed> $options Optional call options (query/body/headers).
	 *
	 * @return array{body: array<string,mixed>, meta: array{status: int, durationMs: int, correlationId: ?string, headers: array<string,string>}}
	 *
	 * @throws ProviderUnavailableException When OpenConnector or the upstream
	 *                                      service is unavailable.
	 *
	 * @spec openspec/changes/integration-brp-audit-metadata/tasks.md
	 */
	public function callWithMeta(
		IntegrationProvider $provider,
		string $method,
		string $path,
		array $options = [],
	): array {
		$this->assertProviderIsExternal(provider: $provider);
		$this->assertOpenConnectorAvailable();

		$sourceId = (string)$provider->getOpenConnectorSource();
		$source = $this->loadSource(sourceId: $sourceId, providerId: $provider->getId());

		// Mock mode: short-circuit a flagged source with the canned body PLUS a
		// synthesized `meta` envelope (fake correlationId / durationMs /
		// status:200) so a meta-consuming leaf (e.g. the BRP/HaalCentraal leaf,
		// which persists the Wet-BRP `X-Correlation-ID` + duration into its
		// audit record) gets a fully-shaped response without a real call.
		$config = $this->readSourceConfiguration(source: $source);
		if (($config['mock'] ?? false) === true) {
			return [
				'body' => $this->resolveMockBody(config: $config, sourceId: $sourceId),
				'meta' => $this->mockMeta(config: $config),
			];
		}

		try {
			return $this->invokeWithMeta(source: $source, method: $method, path: $path, options: $options);
		} catch (ProviderUnavailableException $e) {
			// Already classified — surface as-is.
			throw $e;
		} catch (\Throwable $e) {
			$this->logger->error(
				sprintf(
					'[ExternalIntegrationRouter] upstream call (with meta) failed for provider %s %s %s',
					$provider->getId(),
					$method,
					$path
				),
				['exception' => $e]
			);
			throw new ProviderUnavailableException(
				message: sprintf(
					'Upstream service for integration "%s" is unreachable.',
					$provider->getId()
				),
				cause: ProviderUnavailableException::CAUSE_UPSTREAM_SERVICE_DOWN,
				previous: $e
			);
		}//end try
	}//end callWithMeta()

	/**
	 * Cheap "is the connector reachable at all" check.
	 *
	 * Used by `IntegrationProvider::health()` implementations on
	 * external providers when admin UI / OCS capabilities request
	 * status. Never throws — returns a descriptor instead.
	 *
	 * @param IntegrationProvider $provider Provider to check.
	 *
	 * @return array{status: string, authStatus: string, message: ?string}
	 *
	 * @spec openspec/changes/pluggable-integration-registry/tasks.md#task-4
	 */
	public function probe(IntegrationProvider $provider): array {
		if ($provider->getStorageStrategy() !== 'external') {
			return [
				'status' => 'ok',
				'authStatus' => 'configured',
				'message' => null,
			];
		}

		if ($this->isOpenConnectorAvailable() === false) {
			return [
				'status' => 'unavailable',
				'authStatus' => 'missing',
				'message' => 'OpenConnector app is not installed or enabled.',
			];
		}

		$sourceId = (string)$provider->getOpenConnectorSource();
		try {
			$this->loadSource(sourceId: $sourceId, providerId: $provider->getId());
		} catch (ProviderUnavailableException $e) {
			return [
				'status' => 'unavailable',
				'authStatus' => 'missing',
				'message' => $e->getMessage(),
			];
		}

		return [
			'status' => 'ok',
			'authStatus' => 'configured',
			'message' => null,
		];
	}//end probe()

	/**
	 * Reject non-external providers — they should not reach the router.
	 *
	 * @param IntegrationProvider $provider Provider under inspection.
	 *
	 * @return void
	 *
	 * @throws \LogicException When called with a non-external provider.
	 */
	private function assertProviderIsExternal(IntegrationProvider $provider): void {
		if ($provider->getStorageStrategy() !== 'external') {
			throw new LogicException(
				sprintf(
					'ExternalIntegrationRouter::call() invoked with non-external provider %s (storage=%s)',
					$provider->getId(),
					$provider->getStorageStrategy()
				)
			);
		}

		if ($provider->getOpenConnectorSource() === null) {
			throw new ProviderUnavailableException(
				message: sprintf(
					'External provider "%s" did not declare an OpenConnector source.',
					$provider->getId()
				),
				cause: ProviderUnavailableException::CAUSE_OPENCONNECTOR_SOURCE_MISSING
			);
		}
	}//end assertProviderIsExternal()

	/**
	 * Throw `ProviderUnavailableException` when the OpenConnector app
	 * is disabled or missing.
	 *
	 * @return void
	 *
	 * @throws ProviderUnavailableException With cause openconnector-down.
	 */
	private function assertOpenConnectorAvailable(): void {
		if ($this->isOpenConnectorAvailable() === true) {
			return;
		}

		throw new ProviderUnavailableException(
			message: 'OpenConnector app is not installed or enabled.',
			cause: ProviderUnavailableException::CAUSE_OPENCONNECTOR_DOWN
		);
	}//end assertOpenConnectorAvailable()

	/**
	 * Cached lookup of OpenConnector app installation.
	 *
	 * @return bool
	 */
	private function isOpenConnectorAvailable(): bool {
		if ($this->connectorAvailable === null) {
			$this->connectorAvailable = $this->appManager->isInstalled('openconnector')
				&& $this->appManager->isEnabledForUser('openconnector');
		}

		return $this->connectorAvailable;
	}//end isOpenConnectorAvailable()

	/**
	 * Resolve an OpenConnector source by id.
	 *
	 * Tries OpenConnector's SourceMapper. When the source isn't found
	 * (or the mapper isn't loaded), surfaces a
	 * `openconnector-source-missing` exception so the UI shows
	 * "Reconfigure connector" rather than a generic 500.
	 *
	 * @param string $sourceId Source identifier declared by the provider.
	 * @param string $providerId Provider id (for error messages only).
	 *
	 * @return mixed The resolved Source entity (OpenConnector-shaped).
	 *
	 * @throws ProviderUnavailableException When the source is missing.
	 */
	private function loadSource(string $sourceId, string $providerId) {
		try {
			$mapper = $this->container->get('OCA\\OpenConnector\\Db\\SourceMapper');
			$source = null;

			// OpenConnector's SourceMapper supports a stringy slug
			// lookup via `findByReference()` or `find(<id>)`. We try
			// both because the public API surface has evolved.
			if (method_exists($mapper, 'findByReference') === true) {
				$source = $mapper->findByReference($sourceId);
			} elseif (method_exists($mapper, 'find') === true) {
				$source = $mapper->find($sourceId);
			}

			if ($source === null) {
				throw new RuntimeException(sprintf('OpenConnector source "%s" not found', $sourceId));
			}

			return $source;
		} catch (\Throwable $e) {
			throw new ProviderUnavailableException(
				message: sprintf(
					'OpenConnector source "%s" for integration "%s" is missing or unreadable.',
					$sourceId,
					$providerId
				),
				cause: ProviderUnavailableException::CAUSE_OPENCONNECTOR_SOURCE_MISSING,
				previous: $e
			);
		}//end try
	}//end loadSource()

	/**
	 * Read the `configuration` array off a resolved OpenConnector source,
	 * whatever concrete shape the SourceMapper handed back. OpenConnector
	 * sources are OpenRegister `ObjectEntity` objects whose payload lives
	 * under `getObject()`; older builds returned a plain array or a
	 * `jsonSerialize`-able entity. This reads the `configuration` sub-array
	 * (where the mock flag + canned fixture live) defensively across all
	 * three shapes, returning an empty array when there is none.
	 *
	 * This is the ONLY place the router introspects the source body — and it
	 * reads transport configuration only (never a credential), so the mock
	 * short-circuit stays foundation-safe and additive.
	 *
	 * @param mixed $source The resolved source entity.
	 *
	 * @return array<string,mixed> The source's `configuration` array (possibly empty).
	 *
	 * @spec openspec/changes/integration-mock-mode/tasks.md
	 */
	private function readSourceConfiguration($source): array {
		$data = null;

		if (is_array($source) === true) {
			$data = $source;
		} elseif (is_object($source) === true && method_exists($source, 'getObject') === true) {
			$data = $source->getObject();
		} elseif (is_object($source) === true && method_exists($source, 'jsonSerialize') === true) {
			$data = $source->jsonSerialize();
		} elseif (is_object($source) === true && method_exists($source, 'getConfiguration') === true) {
			$config = $source->getConfiguration();
			if (is_array($config) === true) {
				return $config;
			}

			return [];
		}

		if (is_array($data) === false) {
			return [];
		}

		$config = ($data['configuration'] ?? []);
		if (is_array($config) === true) {
			return $config;
		}

		return [];
	}//end readSourceConfiguration()

	/**
	 * Resolve the canned mock body for a flagged source.
	 *
	 * The realistic, upstream-shaped fixture is taken from
	 * `configuration.mockResponse` on the source (so each leaf's fixture lives
	 * with its source fragment). When a source is flagged `mock:true` but
	 * carries no `mockResponse`, an empty `{}` body is returned — the leaf's
	 * own extractor then yields an empty result set rather than fataling, so
	 * mock mode never produces a 500.
	 *
	 * @param array<string,mixed> $config The source's `configuration` array.
	 * @param string $sourceId The source slug (diagnostics only).
	 *
	 * @return array<string,mixed> The canned upstream-shaped body.
	 *
	 * @spec openspec/changes/integration-mock-mode/tasks.md
	 */
	private function resolveMockBody(array $config, string $sourceId): array {
		$body = ($config['mockResponse'] ?? []);
		if (is_array($body) === false) {
			$this->logger->warning(
				sprintf(
					'[ExternalIntegrationRouter] mock source "%s" has a non-array mockResponse; returning empty body.',
					$sourceId
				)
			);
			return [];
		}

		return $body;
	}//end resolveMockBody()

	/**
	 * Synthesize the `meta` envelope for a mock `callWithMeta()` response. A
	 * source may override any field via `configuration.mockMeta`; otherwise a
	 * realistic default is produced — `status:200`, a small non-zero
	 * `durationMs`, and a fresh fake `correlationId` (so a BRP-style consumer
	 * that persists the Wet-BRP `X-Correlation-ID` always has a value). No real
	 * call is made, so the `headers` map carries only the synthesized
	 * correlation header.
	 *
	 * @param array<string,mixed> $config The source's `configuration` array.
	 *
	 * @return array{status: int, durationMs: int, correlationId: ?string, headers: array<string,string>}
	 *
	 * @spec openspec/changes/integration-mock-mode/tasks.md
	 */
	private function mockMeta(array $config): array {
		$override = ($config['mockMeta'] ?? []);
		if (is_array($override) === false) {
			$override = [];
		}

		$correlationId = ($override['correlationId'] ?? ('MOCK-CID-' . bin2hex(random_bytes(6))));
		if ($correlationId !== null) {
			$correlationId = (string)$correlationId;
		}

		$status = (int)($override['status'] ?? 200);
		$durationMs = (int)($override['durationMs'] ?? 12);

		$headers = ($override['headers'] ?? []);
		if (is_array($headers) === false) {
			$headers = [];
		}

		if ($correlationId !== null && isset($headers['X-Correlation-ID']) === false) {
			$headers['X-Correlation-ID'] = $correlationId;
		}

		return [
			'status' => $status,
			'durationMs' => $durationMs,
			'correlationId' => $correlationId,
			'headers' => $this->flattenHeaders(headers: $headers),
		];
	}//end mockMeta()

	/**
	 * Invoke the upstream call via OpenConnector's CallService.
	 *
	 * The CallService API has varied across OpenConnector versions;
	 * this method tries the canonical method names and falls back to
	 * an exception that the caller wraps as upstream-down.
	 *
	 * @param mixed $source Resolved source entity.
	 * @param string $method HTTP method.
	 * @param string $path Path relative to source base URL.
	 * @param array<string,mixed> $options Call options (query / body / headers).
	 *
	 * @return array<string,mixed> Decoded response body.
	 *
	 * @throws \RuntimeException When CallService is unreachable. The
	 *                           caller wraps this as ProviderUnavailableException.
	 */
	private function invoke($source, string $method, string $path, array $options): array {
		$callService = $this->container->get('OCA\\OpenConnector\\Service\\CallService');

		if (method_exists($callService, 'call') === true) {
			$response = $callService->call($source, $path, $method, $options);
			$this->assertUpstreamOk(response: $response);
			return $this->decodeResponse(response: $response);
		}

		if (method_exists($callService, 'request') === true) {
			$response = $callService->request($source, $method, $path, $options);
			$this->assertUpstreamOk(response: $response);
			return $this->decodeResponse(response: $response);
		}

		throw new RuntimeException(
			'OpenConnector\\Service\\CallService does not expose a known call/request method.'
		);
	}//end invoke()

	/**
	 * Invoke the upstream call like {@see invoke()} but return both the
	 * decoded body and the extracted response metadata. Keeps the same
	 * CallService method-name fallback + >= 400 status assertion.
	 *
	 * @param mixed $source Resolved source entity.
	 * @param string $method HTTP method.
	 * @param string $path Path relative to source base URL.
	 * @param array<string,mixed> $options Call options (query / body / headers).
	 *
	 * @return array{body: array<string,mixed>, meta: array{status: int, durationMs: int, correlationId: ?string, headers: array<string,string>}}
	 *
	 * @throws \RuntimeException When CallService is unreachable. The caller
	 *                           wraps this as ProviderUnavailableException.
	 */
	private function invokeWithMeta($source, string $method, string $path, array $options): array {
		$callService = $this->container->get('OCA\\OpenConnector\\Service\\CallService');

		if (method_exists($callService, 'call') === true) {
			$response = $callService->call($source, $path, $method, $options);
			$this->assertUpstreamOk(response: $response);
			return [
				'body' => $this->decodeResponse(response: $response),
				'meta' => $this->extractMeta(response: $response),
			];
		}

		if (method_exists($callService, 'request') === true) {
			$response = $callService->request($source, $method, $path, $options);
			$this->assertUpstreamOk(response: $response);
			return [
				'body' => $this->decodeResponse(response: $response),
				'meta' => $this->extractMeta(response: $response),
			];
		}

		throw new RuntimeException(
			'OCA\\OpenConnector\\Service\\CallService does not expose a known call/request method.'
		);
	}//end invokeWithMeta()

	/**
	 * Extract transport metadata from a CallService response (OpenConnector
	 * CallLog). Reads ONLY the HTTP status, the OpenConnector-measured
	 * round-trip duration (`responseTime`, milliseconds), and the response
	 * headers — never the request/response body, so no BSN or payload data
	 * ever lands in `meta`.
	 *
	 * The CallLog's `getResponse()` payload is
	 * `{ statusCode, responseTime, headers, body, encoding, … }`. The
	 * `X-Correlation-ID` response header (case-insensitive) is surfaced as
	 * `correlationId`. Headers are flattened to `array<string,string>`
	 * (Guzzle returns `array<string,string[]>`).
	 *
	 * @param mixed $response The raw return from CallService.
	 *
	 * @return array{status: int, durationMs: int, correlationId: ?string, headers: array<string,string>}
	 */
	private function extractMeta($response): array {
		$meta = [
			'status' => 0,
			'durationMs' => 0,
			'correlationId' => null,
			'headers' => [],
		];

		if (is_object($response) === true && method_exists($response, 'getStatusCode') === true) {
			$meta['status'] = (int)$response->getStatusCode();
		}

		$payload = null;
		if (is_object($response) === true && method_exists($response, 'getResponse') === true) {
			$payload = $response->getResponse();
		} elseif (is_array($response) === true) {
			$payload = $response;
		}

		if (is_array($payload) === false) {
			return $meta;
		}

		if ($meta['status'] === 0 && isset($payload['statusCode']) === true) {
			$meta['status'] = (int)$payload['statusCode'];
		}

		if (isset($payload['responseTime']) === true && is_numeric($payload['responseTime']) === true) {
			$meta['durationMs'] = (int)round((float)$payload['responseTime']);
		}

		$headers = ($payload['headers'] ?? []);
		if (is_array($headers) === true) {
			$meta['headers'] = $this->flattenHeaders(headers: $headers);
			$meta['correlationId'] = $this->firstHeaderValue(headers: $headers, name: 'X-Correlation-ID');
		}

		return $meta;
	}//end extractMeta()

	/**
	 * Flatten Guzzle-style `array<string,string[]>` response headers to
	 * `array<string,string>` (first value per header).
	 *
	 * @param array<string,mixed> $headers Raw headers.
	 *
	 * @return array<string,string>
	 */
	private function flattenHeaders(array $headers): array {
		$out = [];
		foreach ($headers as $name => $value) {
			if (is_array($value) === true) {
				$value = ($value[0] ?? '');
			}

			$out[(string)$name] = (string)$value;
		}

		return $out;
	}//end flattenHeaders()

	/**
	 * Case-insensitive lookup of the first value of a named response header.
	 *
	 * @param array<string,mixed> $headers Raw (possibly array-valued) headers.
	 * @param string $name Header name to find.
	 *
	 * @return string|null The first value, or null when the header is absent/empty.
	 */
	private function firstHeaderValue(array $headers, string $name): ?string {
		$needle = strtolower($name);
		foreach ($headers as $headerName => $value) {
			if (strtolower((string)$headerName) !== $needle) {
				continue;
			}

			if (is_array($value) === true) {
				$value = ($value[0] ?? null);
			}

			if ($value === null || $value === '') {
				return null;
			}

			return (string)$value;
		}

		return null;
	}//end firstHeaderValue()

	/**
	 * Treat a >= 400 upstream status (carried on the CallLog OpenConnector
	 * returns) as an upstream failure rather than letting an error page
	 * leak through as "rows". A 401/403 specifically is re-flagged as
	 * `provider-auth` so the UI shows the "reconnect connector" banner.
	 *
	 * @param mixed $response The CallService return value.
	 *
	 * @return void
	 *
	 * @throws ProviderUnavailableException When the upstream answered >= 400.
	 */
	private function assertUpstreamOk($response): void {
		if (is_object($response) === false || method_exists($response, 'getStatusCode') === false) {
			return;
		}

		$status = (int)$response->getStatusCode();
		if ($status < 400) {
			return;
		}

		$cause = ProviderUnavailableException::CAUSE_UPSTREAM_SERVICE_DOWN;
		if ($status === 401 || $status === 403) {
			$cause = ProviderUnavailableException::CAUSE_PROVIDER_AUTH;
		}

		throw new ProviderUnavailableException(
			message: sprintf('Upstream service answered HTTP %d.', $status),
			cause: $cause
		);
	}//end assertUpstreamOk()

	/**
	 * Normalise a CallService response into a decoded array.
	 *
	 * OpenConnector's CallService returns a `CallLog` whose
	 * `getResponse()` is `{ statusCode, headers, body, encoding, … }` —
	 * the actual upstream payload is the (usually JSON) `body` string
	 * (base64-encoded when the upstream wasn't UTF-8). We unwrap that,
	 * JSON-decode it, and hand the caller the upstream body directly.
	 * A raw array / scalar string from an older CallService is decoded
	 * in place; non-arrays are wrapped under a `body` key so callers
	 * have a stable shape to introspect.
	 *
	 * @param mixed $response The raw return from CallService.
	 *
	 * @return array<string,mixed>
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) Handles four distinct OpenConnector response shapes
	 * (CallLog, array, jsonSerialize, string) across multiple OC versions; each branch is a required
	 * shape check.
	 * @SuppressWarnings(PHPMD.NPathComplexity)      Handles four distinct OpenConnector response shapes
	 * (CallLog, array, jsonSerialize, string) across multiple OC versions; each branch is a required
	 * shape check.
	 */
	private function decodeResponse($response): array {
		if (is_array($response) === true) {
			return $response;
		}

		// CallLog (OpenConnector) — pull the upstream body out of getResponse().
		if (is_object($response) === true && method_exists($response, 'getResponse') === true) {
			$payload = $response->getResponse();
			if (is_array($payload) === true && array_key_exists('body', $payload) === true) {
				$body = $payload['body'];
				if (($payload['encoding'] ?? null) === 'base64' && is_string($body) === true) {
					$body = (string)base64_decode($body, true);
				}

				return $this->decodeResponse(response: $body);
			}

			if (is_array($payload) === true) {
				return $payload;
			}
		}

		if (is_object($response) === true && method_exists($response, 'jsonSerialize') === true) {
			$data = $response->jsonSerialize();
			if (is_array($data) === true) {
				return $data;
			}

			return ['body' => $data];
		}

		if (is_string($response) === true) {
			$decoded = json_decode($response, true);
			if (is_array($decoded) === true) {
				return $decoded;
			}

			return ['body' => $response];
		}

		return ['body' => $response];
	}//end decodeResponse()
}//end class
