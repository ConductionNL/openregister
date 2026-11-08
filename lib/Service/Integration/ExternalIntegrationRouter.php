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
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Exception\ProviderUnavailableException;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Support\FleetAppId;
use OCP\App\IAppManager;
use OCP\AppFramework\Db\DoesNotExistException;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Routes external integrations' CRUD calls through OpenConnector.
 *
 * The router is intentionally thin. It reads the declared source from the
 * connector's register, hands it to the connector's CallService, and
 * classifies the failure mode when something goes wrong. Per-provider
 * specifics (URL paths, payload shapes) live in the provider
 * implementations themselves. The router only provides the safe transport.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) The transport meets the app
 * manager, the container, the object service, the source entity, the fleet id
 * resolver and the connector's call signature; each is one side of the join.
 */
class ExternalIntegrationRouter implements ExternalIntegrationRouterInterface {
	use ConnectorRequestTrait;


	/**
	 * The schema slug the connector stores its sources under.
	 *
	 * @var string
	 */
	private const SOURCE_SCHEMA = 'source';

	/**
	 * The probe descriptor of a provider that is reachable (or not external).
	 *
	 * @var array{status: string, authStatus: string, message: ?string}
	 */
	private const PROBE_OK = [
		'status' => 'ok',
		'authStatus' => 'configured',
		'message' => null,
	];

	/**
	 * Cached availability flag for the OpenConnector NC app.
	 *
	 * Null means "not yet checked" — the first call resolves it.
	 *
	 * @var boolean|null
	 */
	private ?bool $connectorAvailable = null;

	/**
	 * Reads the body, status and metadata out of what the connector answers.
	 *
	 * @var ConnectorResponseReader
	 */
	private readonly ConnectorResponseReader $responses;

	/**
	 * Constructor.
	 *
	 * @param IAppManager $appManager NC app manager — used to
	 *                                detect whether OpenConnector
	 *                                is installed + enabled.
	 * @param ContainerInterface $container DI container, used to lazily
	 *                                      resolve ObjectService (to read
	 *                                      the source) and the connector's
	 *                                      CallService.
	 * @param LoggerInterface $logger Logger for failure traces.
	 *
	 * @return void
	 */
	public function __construct(
		private IAppManager $appManager,
		private ContainerInterface $container,
		private LoggerInterface $logger,
	) {
		$this->responses = new ConnectorResponseReader();
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
	public function call(IntegrationProvider $provider, string $method, string $path, array $options = []): array {
		$target = $this->callTarget(provider: $provider);

		// Mock mode: when the resolved source is flagged
		// `configuration.mock === true`, short-circuit and return the canned
		// `configuration.mockResponse` body WITHOUT performing a real HTTP
		// call — so the KvK / OpenCorporates / BRP / SMS / WhatsApp leaves are
		// demonstrably functional end-to-end without real credentials. The
		// real path below stays 100% intact for non-mock sources; mock is
		// opt-in per source. {@see resolveMockBody()} for the resolution.
		if (($target['config']['mock'] ?? false) === true) {
			return $this->resolveMockBody(config: $target['config'], sourceId: $target['sourceId']);
		}

		$request = new UpstreamRequest(method: $method, path: $path, options: $options);
		try {
			return $this->invoke(source: $target['source'], request: $request);
		} catch (ProviderUnavailableException $e) {
			// Already classified — surface as-is.
			throw $e;
		} catch (\Throwable $e) {
			// Anything else surfacing from OpenConnector / the upstream is
			// treated as an upstream failure, wrapping the original throwable.
			throw $this->upstreamFailure(provider: $provider, kind: 'call', request: $request->describe(), previous: $e);
		}
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
	public function callWithMeta(IntegrationProvider $provider, string $method, string $path, array $options = []): array {
		$target = $this->callTarget(provider: $provider);

		// Mock mode: short-circuit a flagged source with the canned body PLUS a
		// synthesized `meta` envelope (fake correlationId / durationMs /
		// status:200) so a meta-consuming leaf (e.g. the BRP/HaalCentraal leaf,
		// which persists the Wet-BRP `X-Correlation-ID` + duration into its
		// audit record) gets a fully-shaped response without a real call.
		if (($target['config']['mock'] ?? false) === true) {
			$config = $target['config'];
			return ['body' => $this->resolveMockBody(config: $config, sourceId: $target['sourceId']), 'meta' => $this->mockMeta(config: $config)];
		}

		$request = new UpstreamRequest(method: $method, path: $path, options: $options);
		try {
			return $this->invokeWithMeta(source: $target['source'], request: $request);
		} catch (ProviderUnavailableException $e) {
			// Already classified — surface as-is.
			throw $e;
		} catch (\Throwable $e) {
			throw $this->upstreamFailure(provider: $provider, kind: 'call (with meta)', request: $request->describe(), previous: $e);
		}
	}//end callWithMeta()

	/**
	 * Check the provider and the connector, then load the provider's source and its configuration.
	 *
	 * @param IntegrationProvider $provider The provider making the call.
	 *
	 * @return array{sourceId: string, source: ObjectEntity, config: array<string,mixed>}
	 *
	 * @throws ProviderUnavailableException When the connector or the source is missing.
	 */
	private function callTarget(IntegrationProvider $provider): array {
		$this->assertProviderIsExternal(provider: $provider);
		$this->assertOpenConnectorAvailable();

		$sourceId = (string)$provider->getOpenConnectorSource();
		$source   = $this->loadSource(sourceId: $sourceId, providerId: $provider->getId());

		return ['sourceId' => $sourceId, 'source' => $source, 'config' => $this->readSourceConfiguration(source: $source)];
	}//end callTarget()

	/**
	 * Log an unclassified upstream failure and classify it as upstream-service-down.
	 *
	 * @param IntegrationProvider $provider The provider making the call.
	 * @param string              $kind     `call` or `call (with meta)`, for the log line.
	 * @param string              $request  The method and path, for the log line.
	 * @param \Throwable          $previous The failure.
	 *
	 * @return ProviderUnavailableException The exception to throw.
	 */
	private function upstreamFailure(IntegrationProvider $provider, string $kind, string $request, \Throwable $previous): ProviderUnavailableException {
		$this->logger->error(
			sprintf('[ExternalIntegrationRouter] upstream %s failed for provider %s %s', $kind, $provider->getId(), $request),
			['exception' => $previous]
		);

		return new ProviderUnavailableException(
			message: sprintf('Upstream service for integration "%s" is unreachable.', $provider->getId()),
			cause: ProviderUnavailableException::CAUSE_UPSTREAM_SERVICE_DOWN,
			previous: $previous
		);
	}//end upstreamFailure()

	/**
	 * The `configuration` array of the provider's connector source, so a
	 * provider can ask only what its source allows.
	 *
	 * @param IntegrationProvider $provider The provider whose source to read.
	 *
	 * @return array<string,mixed> The source's `configuration` (possibly empty).
	 *
	 * @throws ProviderUnavailableException When the connector or the source is missing.
	 *
	 * @spec openspec/specs/integration-person-lookup/spec.md#requirement-a-call-may-be-recorded-without-its-bodies
	 */
	public function sourceConfiguration(IntegrationProvider $provider): array {
		$this->assertProviderIsExternal(provider: $provider);
		$this->assertOpenConnectorAvailable();

		$source = $this->loadSource(sourceId: (string)$provider->getOpenConnectorSource(), providerId: $provider->getId());

		return $this->readSourceConfiguration(source: $source);
	}//end sourceConfiguration()

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
			return self::PROBE_OK;
		}

		if ($this->isOpenConnectorAvailable() === false) {
			return $this->probeUnavailable(message: 'OpenConnector app is not installed or enabled.');
		}

		try {
			$this->loadSource(sourceId: (string)$provider->getOpenConnectorSource(), providerId: $provider->getId());
		} catch (ProviderUnavailableException $e) {
			return $this->probeUnavailable(message: $e->getMessage());
		}

		return self::PROBE_OK;
	}//end probe()

	/**
	 * The probe descriptor of an external provider that cannot be reached.
	 *
	 * @param string $message Why it cannot be reached.
	 *
	 * @return array{status: string, authStatus: string, message: ?string}
	 */
	private function probeUnavailable(string $message): array {
		return [
			'status' => 'unavailable',
			'authStatus' => 'missing',
			'message' => $message,
		];
	}//end probeUnavailable()

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

		$this->assertSourceDeclared(provider: $provider);
	}//end assertProviderIsExternal()

	/**
	 * Reject an external provider that declares no OpenConnector source.
	 *
	 * @param IntegrationProvider $provider Provider under inspection.
	 *
	 * @return void
	 *
	 * @throws ProviderUnavailableException With cause openconnector-source-missing.
	 */
	private function assertSourceDeclared(IntegrationProvider $provider): void {
		if ($provider->getOpenConnectorSource() === null) {
			throw new ProviderUnavailableException(
				message: sprintf('External provider "%s" did not declare an OpenConnector source.', $provider->getId()),
				cause: ProviderUnavailableException::CAUSE_OPENCONNECTOR_SOURCE_MISSING
			);
		}
	}//end assertSourceDeclared()

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
			// Resolved through FleetAppId because the app answers to `integriq`
			// on development and `openconnector` on beta/main. Asking for one
			// name returns false against the other — silently, with no error.
			$this->connectorAvailable = FleetAppId::isInstalled($this->appManager, 'integriq')
				&& FleetAppId::isEnabledForUser($this->appManager, 'integriq');
		}

		return $this->connectorAvailable;
	}//end isOpenConnectorAvailable()

	/**
	 * Resolve the connector source a provider declared, by uuid or by slug.
	 *
	 * The connector keeps its sources as OpenRegister objects: schema `source`
	 * in the connector's own register. OpenRegister owns ObjectService, so the
	 * router reads the source directly and needs no class from the connector.
	 * When no source answers, it raises `openconnector-source-missing` so the
	 * UI shows "Reconfigure connector" rather than a generic 500.
	 *
	 * @param string $sourceId Source uuid or slug declared by the provider.
	 * @param string $providerId Provider id (for error messages only).
	 *
	 * @return ObjectEntity The source object.
	 *
	 * @throws ProviderUnavailableException When the source is missing.
	 *
	 * @spec openspec/specs/integration-registry/spec.md#requirement-external-strategy-providers-must-route-through-openconnector
	 */
	private function loadSource(string $sourceId, string $providerId): ObjectEntity {
		try {
			$source = $this->findSource(sourceId: $sourceId);
		} catch (\Throwable $e) {
			throw $this->sourceMissing(sourceId: $sourceId, providerId: $providerId, previous: $e);
		}

		if ($source === null) {
			throw $this->sourceMissing(sourceId: $sourceId, providerId: $providerId, previous: null);
		}

		return $source;
	}//end loadSource()

	/**
	 * Look the source up in each register slug the connector may use.
	 *
	 * This seam replaces `OCA\OpenConnector\Db\SourceMapper`, which ac47457f
	 * deleted in the chain-C OpenRegister cutover (openregister#3562,
	 * openregister#3706). The connector stores a source as an object in its own
	 * register, schema `source`, and reads it with
	 * `ObjectService::find(id, register, schema, _rbac: false, _multitenancy: false)`.
	 * The router does the same.
	 *
	 * The register slug moved with the app id: integriq's `MigrateRegisterSlug`
	 * repair step renames `openconnector` to `integriq`. An instance that has
	 * not run that step still carries the old slug, so the lookup tries every
	 * spelling {@see FleetAppId::candidates()} knows, newest first.
	 *
	 * `find()` matches an object's id, uuid, slug or uri, so a provider may
	 * declare either the source uuid or its slug. This is a server-side routing
	 * read, not a user read: RBAC, multitenancy and the read audit trail are off.
	 *
	 * @param string $sourceId Source uuid or slug.
	 *
	 * @return ObjectEntity|null The source, or null when no register holds it.
	 *
	 * @throws \Throwable When ObjectOperationsInterface cannot be resolved or fails for a
	 *                    reason other than a missing register or object.
	 *
	 * @spec openspec/specs/integration-registry/spec.md#requirement-external-strategy-providers-must-route-through-openconnector
	 */
	private function findSource(string $sourceId): ?ObjectEntity {
		if ($sourceId === '') {
			return null;
		}

		$objectService = $this->objectService();
		foreach (FleetAppId::candidates(canonical: 'integriq') as $register) {
			$source = $this->absentAsNull(
				read: static fn () => $objectService->find(
					id: $sourceId,
					register: $register,
					schema: self::SOURCE_SCHEMA,
					_rbac: false,
					_multitenancy: false,
					_audit: false
				)
			);

			if ($source !== null) {
				return $source;
			}
		}

		return null;
	}//end findSource()

	/**
	 * One register's answer to the source lookup, null when it holds none.
	 *
	 * A missing register slug or a missing source both surface as
	 * DoesNotExistException; either way the next spelling may still answer.
	 *
	 * @param callable(): ?ObjectEntity $read The lookup in one register.
	 *
	 * @return ObjectEntity|null The source, or null when absent here.
	 */
	private function absentAsNull(callable $read): ?ObjectEntity {
		try {
			return $read();
		} catch (DoesNotExistException $e) {
			return null;
		}
	}//end absentAsNull()

	/**
	 * The ObjectService the source lookup reads through.
	 *
	 * @return ObjectService The object service.
	 *
	 * @throws RuntimeException When the container hands back anything else.
	 */
	private function objectService(): ObjectService {
		$objectService = $this->container->get(ObjectOperationsInterface::class);
		if ($objectService instanceof ObjectService === false) {
			throw new RuntimeException('The container did not return an ObjectService.');
		}

		return $objectService;
	}//end objectService()

	/**
	 * Build the `openconnector-source-missing` failure.
	 *
	 * @param string $sourceId Source uuid or slug declared by the provider.
	 * @param string $providerId Provider id (for the message only).
	 * @param \Throwable|null $previous The lookup failure, when there was one.
	 *
	 * @return ProviderUnavailableException The classified failure.
	 */
	private function sourceMissing(string $sourceId, string $providerId, ?\Throwable $previous): ProviderUnavailableException {
		return new ProviderUnavailableException(
			message: sprintf(
				'OpenConnector source "%s" for integration "%s" is missing or unreadable.',
				$sourceId,
				$providerId
			),
			cause: ProviderUnavailableException::CAUSE_OPENCONNECTOR_SOURCE_MISSING,
			previous: $previous
		);
	}//end sourceMissing()

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
		if (is_array($source) === true) {
			return $this->configurationOf(data: $source);
		}

		if (is_object($source) === false) {
			return [];
		}

		return $this->objectConfiguration(source: $source);
	}//end readSourceConfiguration()

	/**
	 * The configuration of a source object: through getObject(), jsonSerialize()
	 * or getConfiguration(), in that order of preference.
	 *
	 * @param object $source The source object.
	 *
	 * @return array<string,mixed> The configuration (possibly empty).
	 */
	private function objectConfiguration(object $source): array {
		if (method_exists($source, 'getObject') === true) {
			return $this->configurationOf(data: $source->getObject());
		}

		if (method_exists($source, 'jsonSerialize') === true) {
			return $this->configurationOf(data: $source->jsonSerialize());
		}

		if (method_exists($source, 'getConfiguration') === true) {
			return $this->arrayOrEmpty(value: $source->getConfiguration());
		}

		return [];
	}//end objectConfiguration()

	/**
	 * The `configuration` of serialized source data.
	 *
	 * @param mixed $data The source data.
	 *
	 * @return array<string,mixed> The configuration (possibly empty).
	 */
	private function configurationOf(mixed $data): array {
		if (is_array($data) === false) {
			return [];
		}

		return $this->arrayOrEmpty(value: ($data['configuration'] ?? []));
	}//end configurationOf()

	/**
	 * The value when it is an array, else an empty array.
	 *
	 * @param mixed $value The value.
	 *
	 * @return array<mixed>
	 */
	private function arrayOrEmpty(mixed $value): array {
		if (is_array($value) === true) {
			return $value;
		}

		return [];
	}//end arrayOrEmpty()

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
		$override      = $this->arrayOrEmpty(value: ($config['mockMeta'] ?? []));
		$correlationId = $this->mockCorrelationId(override: $override);
		$status        = (int)($override['status'] ?? 200);

		$headers = $this->arrayOrEmpty(value: ($override['headers'] ?? []));
		if ($correlationId !== null && isset($headers['X-Correlation-ID']) === false) {
			$headers['X-Correlation-ID'] = $correlationId;
		}

		return [
			'status' => $status,
			'durationMs' => (int)($override['durationMs'] ?? 12),
			'correlationId' => $correlationId,
			'headers' => $this->responses->flattenHeaders(headers: $headers),
		];
	}//end mockMeta()

	/**
	 * The mock correlation id: the override's, else a fresh `MOCK-CID-…`.
	 *
	 * @param array<string,mixed> $override The `mockMeta` override.
	 *
	 * @return string|null
	 */
	private function mockCorrelationId(array $override): ?string {
		$correlationId = ($override['correlationId'] ?? ('MOCK-CID-' . bin2hex(random_bytes(6))));
		if ($correlationId !== null) {
			$correlationId = (string)$correlationId;
		}

		return $correlationId;
	}//end mockCorrelationId()

	/**
	 * The connector's CallService FQCN for the id this instance actually has.
	 *
	 * The class is OCA\Integriq\Service\CallService on development and
	 * OCA\OpenConnector\Service\CallService on beta/main. Falls back to the
	 * canonical spelling when neither loads, so the `get()` below still throws
	 * into the caller's catch exactly as it does today.
	 *
	 * @return string The FQCN to resolve from the container.
	 */
	private function callServiceClass(): string {
		return (FleetAppId::resolveClass('integriq', 'Service\\CallService')
			?? 'OCA\\Integriq\\Service\\CallService');
	}//end callServiceClass()

	/**
	 * Invoke the upstream call via OpenConnector's CallService.
	 *
	 * @param mixed           $source  Resolved source entity.
	 * @param UpstreamRequest $request The method, path and call options.
	 *
	 * @return array<string,mixed> Decoded response body.
	 *
	 * @throws \RuntimeException When CallService is unreachable. The
	 *                           caller wraps this as ProviderUnavailableException.
	 */
	private function invoke($source, UpstreamRequest $request): array {
		$response = $this->dispatch(source: $source, request: $request);

		return $this->responses->decode(response: $response);
	}//end invoke()

	/**
	 * Invoke the upstream call like {@see invoke()} but return both the
	 * decoded body and the extracted response metadata.
	 *
	 * @param mixed           $source  Resolved source entity.
	 * @param UpstreamRequest $request The method, path and call options.
	 *
	 * @return array{body: array<string,mixed>, meta: array{status: int, durationMs: int, correlationId: ?string, headers: array<string,string>}}
	 *
	 * @throws \RuntimeException When CallService is unreachable. The caller
	 *                           wraps this as ProviderUnavailableException.
	 */
	private function invokeWithMeta($source, UpstreamRequest $request): array {
		$response = $this->dispatch(source: $source, request: $request);

		return [
			'body' => $this->responses->decode(response: $response),
			'meta' => $this->responses->meta(response: $response),
		];
	}//end invokeWithMeta()

	/**
	 * Hand the call to the connector's CallService and assert the upstream
	 * status. The CallService API has varied across OpenConnector versions, so
	 * this tries `call` and then `request`.
	 *
	 * With the `withholdBodies` option the connector records the call without
	 * its request and response bodies. A connector whose `call` has no such
	 * parameter is not called at all, and the call is refused with its own
	 * cause (`connector-cannot-withhold-bodies`) before PHP gets the chance to
	 * refuse the unknown named argument: that would read as an outage and be
	 * logged at error level on every call of an older connector.
	 *
	 * @param mixed           $source  Resolved source entity.
	 * @param UpstreamRequest $request The method, path and call options.
	 *
	 * @return mixed The raw CallService response.
	 *
	 * @throws \RuntimeException When CallService is unreachable.
	 * @throws ProviderUnavailableException When bodies are to be withheld and the connector cannot.
	 *
	 * @spec openspec/specs/integration-person-lookup/spec.md#requirement-a-call-may-be-recorded-without-its-bodies
	 */
	private function dispatch($source, UpstreamRequest $request) {
		$method  = $request->method;
		$options = $request->options;
		$withholdBodies = (($options['withholdBodies'] ?? false) === true);
		unset($options['withholdBodies']);

		$callService = $this->container->get($this->callServiceClass());
		$this->assertCanWithholdBodies(callService: $callService, withholdBodies: $withholdBodies);

		$options = $this->connectorOptions(options: $options);
		$path = $this->connectorEndpoint(source: $source, path: $request->path);

		if (method_exists($callService, 'call') === true) {
			$named = self::withholdArguments(withholdBodies: $withholdBodies);

			$response = $callService->call($source, $path, $method, $options, ...$named);
			$this->assertUpstreamOk(response: $response);
			return $response;
		}

		if (method_exists($callService, 'request') === true && $withholdBodies === false) {
			$response = $callService->request($source, $method, $path, $options);
			$this->assertUpstreamOk(response: $response);
			return $response;
		}

		throw new RuntimeException(
			'The connector CallService does not expose a known call/request method.'
		);
	}//end dispatch()

	/**
	 * Refuse a body-withholding call the connector cannot make, before it is made.
	 *
	 * @param mixed $callService    The connector CallService.
	 * @param bool  $withholdBodies Whether the call must be recorded without its bodies.
	 *
	 * @return void
	 *
	 * @throws ProviderUnavailableException When bodies are to be withheld and the connector cannot.
	 */
	private function assertCanWithholdBodies(mixed $callService, bool $withholdBodies): void {
		if ($withholdBodies === true && (new CallSignature())->accepts(service: $callService, method: 'call', name: 'withholdBodies') === false) {
			$this->logger->info(
				'[ExternalIntegrationRouter] The connector CallService cannot withhold bodies; the call is not made.'
			);
			throw new ProviderUnavailableException(
				message: 'The connector cannot record a call without its bodies, so the call is not made.',
				cause: ProviderUnavailableException::CAUSE_CONNECTOR_CANNOT_WITHHOLD_BODIES
			);
		}
	}//end assertCanWithholdBodies()

	/**
	 * The named arguments that ask the connector to withhold bodies, when asked.
	 *
	 * @param bool $withholdBodies Whether the call must be recorded without its bodies.
	 *
	 * @return array<string, bool>
	 */
	private static function withholdArguments(bool $withholdBodies): array {
		if ($withholdBodies === true) {
			return ['withholdBodies' => true];
		}

		return [];
	}//end withholdArguments()

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
	 *
	 * @spec openspec/specs/integration-registry/spec.md#requirement-external-strategy-providers-must-route-through-openconnector
	 */
	private function assertUpstreamOk($response): void {
		$status = $this->responses->status(response: $response);
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
}//end class
