<?php

/**
 * OpenRegister RegisterResolverService
 *
 * Central public service that turns IAppConfig keys of the form
 * `<context>_register` / `<context>_schema` (or grandfathered `register`
 * / `schema`) into either bare slug/UUID strings or hydrated Register
 * and Schema entities. Replaces the ad-hoc `getValueString` + manual
 * mapper lookup pattern observed across opencatalogi, pipelinq and
 * docudesk (13 call sites at audit time, 2026-05-03).
 *
 * The service is multi-tenant aware (the underlying mappers already
 * call `applyOrganisationFilter`), supports an explicit
 * organisationUuid override for cross-tenant admin tooling, and caches
 * resolved entities at request scope so a single request only hits the
 * mapper once per (appId, configKey, organisationUuid) tuple.
 *
 * Naming convention documented in `docs/services/register-resolver.md`
 * and in `openspec/changes/register-resolver-service/spec.md`.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/register-resolver-service/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use OCA\OpenRegister\Db\Organisation;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Service\Resolver\Exception\MissingConfigException;
use OCA\OpenRegister\Service\Resolver\Exception\PropertyNotFoundException;
use OCA\OpenRegister\Service\Resolver\Exception\RegisterNotFoundException;
use OCA\OpenRegister\Service\Resolver\Exception\ResolverConfigKey;
use OCA\OpenRegister\Service\Resolver\Exception\SchemaNotFoundException;
use OCA\OpenRegister\Service\Resolver\RegisterSchemaPair;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Resolve `<context>_register` / `<context>_schema` app-config keys to
 * Register / Schema entities, with request-scoped caching and
 * multi-tenant awareness.
 *
 * @spec openspec/specs/register-resolver-service/spec.md
 */
final class RegisterResolverService implements RegisterResolverServiceInterface {

	/**
	 * Request-scoped cache for resolved Register entities.
	 *
	 * Keyed by "{appId}:{configKey}:{organisationUuid|''}". Per-request
	 * lifetime — clear on tenant switch via clearCache().
	 *
	 * @var array<string, Register>
	 */
	private array $registerCache = [];

	/**
	 * Request-scoped cache for resolved Schema entities.
	 *
	 * Keyed by "{appId}:{configKey}:{organisationUuid|''}". Per-request
	 * lifetime — clear on tenant switch via clearCache().
	 *
	 * @var array<string, Schema>
	 */
	private array $schemaCache = [];

	/**
	 * Last seen active-tenant UUID; defensive reset on tenant switch.
	 *
	 * @var string|null
	 */
	private ?string $lastTenantUuid = null;

	/**
	 * Wire the resolver against the canonical OR mappers + IAppConfig.
	 *
	 * @param IAppConfig $appConfig The app config store.
	 * @param RegisterSchemaMappers $mappers The register and schema mappers.
	 * @param OrganisationServiceInterface $organisationService Active-tenant resolution.
	 * @param LoggerInterface $logger Logger for diagnostics.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly RegisterSchemaMappers $mappers,
		private readonly OrganisationServiceInterface $organisationService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Read the configured slug/UUID/name of a lookup from app config.
	 *
	 * @param ConfigLookup $lookup The app, config key and fallback to read.
	 *
	 * @return string The configured value, else the lookup's fallback.
	 *
	 * @throws MissingConfigException If the config is unset and no default was provided.
	 *
	 * @spec openspec/specs/register-resolver-service/spec.md
	 *   (Requirement: resolveRegisterId)
	 */
	public function lookupId(ConfigLookup $lookup): string {
		$value = $this->appConfig->getValueString($lookup->appId, $lookup->configKey, '');
		if ($value !== '') {
			return $value;
		}

		return $this->defaultOrThrow(lookup: $lookup);
	}//end lookupId()

	/**
	 * The caller's fallback for an unset config key, or the missing-config failure.
	 *
	 * @param ConfigLookup $lookup The lookup whose config key was unset.
	 *
	 * @return string The non-empty fallback.
	 *
	 * @throws MissingConfigException If no non-empty fallback was provided.
	 */
	private function defaultOrThrow(ConfigLookup $lookup): string {
		if ($lookup->default !== null && $lookup->default !== '') {
			return $lookup->default;
		}

		throw new MissingConfigException(appId: $lookup->appId, configKey: $lookup->configKey);
	}//end defaultOrThrow()

	/**
	 * Resolve a configured register lookup to a hydrated Register entity, cached per request.
	 *
	 * @param ConfigLookup $lookup The app, config key, fallback and explicit tenant.
	 *
	 * @return Register The hydrated Register entity.
	 *
	 * @throws MissingConfigException If config is unset and no default.
	 * @throws RegisterNotFoundException If the value does not resolve in the caller tenant.
	 *
	 * @spec openspec/specs/register-resolver-service/spec.md
	 *   (Requirement: resolveRegister)
	 */
	public function lookupRegister(ConfigLookup $lookup): Register {
		$this->maybeFlushOnTenantSwitch();

		$slugOrUuid = $this->lookupId(lookup: $lookup);
		$cacheKey   = $lookup->cacheKey();

		if (isset($this->registerCache[$cacheKey]) === true) {
			return $this->registerCache[$cacheKey];
		}

		$register = $this->findRegister(lookup: $lookup, slugOrUuid: $slugOrUuid);
		$this->registerCache[$cacheKey] = $register;

		return $register;
	}//end lookupRegister()

	/**
	 * Fetch the configured register, in the caller's tenant or the explicitly given one.
	 *
	 * When an explicit org override was passed, bypass multi-tenancy
	 * filtering at the mapper layer and verify membership ourselves
	 * after fetch; otherwise rely on the mapper's implicit caller-
	 * tenant scoping via applyOrganisationFilter().
	 *
	 * @param ConfigLookup $lookup     The lookup: app, config key and explicit tenant.
	 * @param string       $slugOrUuid The configured slug or UUID.
	 *
	 * @return Register The register.
	 *
	 * @throws RegisterNotFoundException If it does not exist, is ambiguous, or belongs to another organisation.
	 */
	private function findRegister(ConfigLookup $lookup, string $slugOrUuid): Register {
		try {
			if ($lookup->organisationUuid !== null) {
				$register = $this->mappers->registerMapper->find(id: $slugOrUuid, _multitenancy: false);
				if ($this->ownedElsewhere(owner: $register->getOrganisation(), organisationUuid: $lookup->organisationUuid) === true) {
					throw new RegisterNotFoundException(appId: $lookup->appId, configKey: $lookup->configKey, resolvedValue: $slugOrUuid);
				}
			} else {
				$register = $this->mappers->registerMapper->find(id: $slugOrUuid);
			}
		} catch (DoesNotExistException|MultipleObjectsReturnedException $e) {
			throw new RegisterNotFoundException(appId: $lookup->appId, configKey: $lookup->configKey, resolvedValue: $slugOrUuid, previous: $e);
		}

		return $register;
	}//end findRegister()

	/**
	 * Whether an entity carries an organisation other than the explicitly requested one.
	 *
	 * An entity without an organisation is not owned elsewhere.
	 *
	 * @param string|null $owner            The entity's organisation uuid.
	 * @param string      $organisationUuid The explicitly requested organisation uuid.
	 *
	 * @return bool True when the entity belongs to another organisation.
	 */
	private function ownedElsewhere(?string $owner, string $organisationUuid): bool {
		return $owner !== null && $owner !== $organisationUuid;
	}//end ownedElsewhere()

	/**
	 * Resolve a configured schema lookup to a hydrated Schema entity, cached per request.
	 *
	 * @param ConfigLookup $lookup The app, config key, fallback and explicit tenant.
	 *
	 * @return Schema The hydrated Schema entity.
	 *
	 * @throws MissingConfigException If config is unset and no default.
	 * @throws SchemaNotFoundException If the value does not resolve in the caller tenant.
	 *
	 * @spec openspec/specs/register-resolver-service/spec.md
	 *   (Requirement: resolveSchema)
	 */
	public function lookupSchema(ConfigLookup $lookup): Schema {
		$this->maybeFlushOnTenantSwitch();

		$slugOrUuid = $this->lookupId(lookup: $lookup);
		$cacheKey   = $lookup->cacheKey();

		if (isset($this->schemaCache[$cacheKey]) === true) {
			return $this->schemaCache[$cacheKey];
		}

		$schema = $this->findSchema(lookup: $lookup, slugOrUuid: $slugOrUuid);
		$this->schemaCache[$cacheKey] = $schema;

		return $schema;
	}//end lookupSchema()

	/**
	 * Fetch the configured schema, in the caller's tenant or the explicitly given one.
	 *
	 * Same tenancy rule as {@see findRegister()}: an explicit override bypasses
	 * the mapper's multitenancy filter and is verified here after the fetch.
	 *
	 * @param ConfigLookup $lookup     The lookup: app, config key and explicit tenant.
	 * @param string       $slugOrUuid The configured slug or UUID.
	 *
	 * @return Schema The schema.
	 *
	 * @throws SchemaNotFoundException If it does not exist, is ambiguous, or belongs to another organisation.
	 */
	private function findSchema(ConfigLookup $lookup, string $slugOrUuid): Schema {
		try {
			if ($lookup->organisationUuid !== null) {
				$schema = $this->mappers->schemaMapper->find(id: $slugOrUuid, _multitenancy: false);
				if ($this->ownedElsewhere(owner: $schema->getOrganisation(), organisationUuid: $lookup->organisationUuid) === true) {
					throw new SchemaNotFoundException(appId: $lookup->appId, configKey: $lookup->configKey, resolvedValue: $slugOrUuid);
				}
			} else {
				$schema = $this->mappers->schemaMapper->find(id: $slugOrUuid);
			}
		} catch (DoesNotExistException|MultipleObjectsReturnedException $e) {
			throw new SchemaNotFoundException(appId: $lookup->appId, configKey: $lookup->configKey, resolvedValue: $slugOrUuid, previous: $e);
		}

		return $schema;
	}//end findSchema()

	/**
	 * Resolve a schema property by configured identifier: array key, then slug, name or id.
	 *
	 * @param ConfigLookup $schema   The lookup of the schema, with the explicit tenant.
	 * @param ConfigLookup $property The lookup of the property identifier on that schema.
	 *
	 * @return array{0: string, 1: array<string, mixed>} The property key and definition.
	 *
	 * @throws MissingConfigException If either config is unset and no default given.
	 * @throws SchemaNotFoundException If the schema id does not resolve.
	 * @throws PropertyNotFoundException If the property id does not match any property on the schema.
	 *
	 * @spec openspec/specs/register-resolver-service/spec.md
	 *   (Requirement: resolveProperty)
	 */
	public function lookupProperty(ConfigLookup $schema, ConfigLookup $property): array {
		return $this->findProperty(schema: $this->lookupSchema(lookup: $schema), lookup: $property);
	}//end lookupProperty()

	/**
	 * Locate the configured property on a schema by key, then by slug / name / id.
	 *
	 * @param Schema       $schema The resolved schema.
	 * @param ConfigLookup $lookup The lookup of the property identifier.
	 *
	 * @return array{0: string, 1: array<string, mixed>} The property key and definition.
	 *
	 * @throws MissingConfigException If the property config is unset and no default given.
	 * @throws PropertyNotFoundException If no property matches.
	 */
	private function findProperty(Schema $schema, ConfigLookup $lookup): array {
		$propertyId = $this->lookupId(lookup: $lookup);
		$match      = $this->matchProperty(properties: $schema->getProperties(), propertyId: $propertyId);
		if ($match !== null) {
			return $match;
		}

		throw new PropertyNotFoundException(
			config: $lookup->key(),
			resolvedValue: $propertyId,
			schemaIdentifier: $this->schemaIdentifier(schema: $schema),
		);
	}//end findProperty()

	/**
	 * Match a property by array key first (canonical name in property maps), then by slug / name / id.
	 *
	 * @param array<mixed> $properties The schema's property map.
	 * @param string       $propertyId The configured property identifier.
	 *
	 * @return array{0: string, 1: array<string, mixed>}|null The match, or null.
	 */
	private function matchProperty(array $properties, string $propertyId): ?array {
		if (isset($properties[$propertyId]) === true && is_array($properties[$propertyId]) === true) {
			return [$propertyId, $properties[$propertyId]];
		}

		return $this->scanProperties(properties: $properties, propertyId: $propertyId);
	}//end matchProperty()

	/**
	 * Fall back to scanning the property definitions by slug / name / id.
	 *
	 * @param array<mixed> $properties The schema's property map.
	 * @param string       $propertyId The configured property identifier.
	 *
	 * @return array{0: string, 1: array<string, mixed>}|null The match, or null.
	 */
	private function scanProperties(array $properties, string $propertyId): ?array {
		foreach ($properties as $key => $definition) {
			if (is_array($definition) === false) {
				continue;
			}

			if (in_array($propertyId, $this->propertyIdentifiers(definition: $definition), true) === true) {
				return [(string)$key, $definition];
			}
		}

		return null;
	}//end scanProperties()

	/**
	 * The slug, name and id of a property definition, as strings.
	 *
	 * @param array<string, mixed> $definition The property definition.
	 *
	 * @return array<int, string> The candidate identifiers.
	 */
	private function propertyIdentifiers(array $definition): array {
		return [
			(string)($definition['slug'] ?? ''),
			(string)($definition['name'] ?? ''),
			(string)($definition['id'] ?? ''),
		];
	}//end propertyIdentifiers()

	/**
	 * How a schema is named in a property-not-found diagnostic.
	 *
	 * @param Schema $schema The schema.
	 *
	 * @return string Its slug, else its UUID, else empty.
	 */
	private function schemaIdentifier(Schema $schema): string {
		return (string)($schema->getSlug() ?? $schema->getUuid() ?? '');
	}//end schemaIdentifier()

	/**
	 * Resolve a register and a schema lookup in a single call.
	 *
	 * @param ConfigLookup $register The lookup of the register.
	 * @param ConfigLookup $schema   The lookup of the schema.
	 *
	 * @return RegisterSchemaPair Pair of resolved entities + raw ids.
	 *
	 * @throws MissingConfigException If either config is unset.
	 * @throws RegisterNotFoundException If the register cannot be resolved.
	 * @throws SchemaNotFoundException If the schema cannot be resolved.
	 *
	 * @spec openspec/specs/register-resolver-service/spec.md
	 *   (Requirement: resolvePair)
	 */
	public function lookupPair(ConfigLookup $register, ConfigLookup $schema): RegisterSchemaPair {
		$registerId = $this->lookupId(lookup: $register);
		$schemaId   = $this->lookupId(lookup: $schema);

		$entity     = $this->lookupRegister(lookup: $register);

		return new RegisterSchemaPair(registerId: $registerId, schemaId: $schemaId, register: $entity, schema: $this->lookupSchema(lookup: $schema));
	}//end lookupPair()

	/**
	 * Enumerate every `<context>_(register|schema)` key set for an app.
	 *
	 * Used by admin UIs and the `openregister:resolver:list` console
	 * command for diagnostics. The bare keys `register` and `schema`
	 * are surfaced as `default_register` / `default_schema` so admin
	 * UIs can flag them as legacy convention.
	 *
	 * @param string $appId Consumer app id.
	 *
	 * @return array<string, string> Map of config-key → raw configured value.
	 *
	 * @spec openspec/specs/register-resolver-service/spec.md
	 *   (Requirement: enumerateAppConfigs)
	 */
	public function enumerateAppConfigs(string $appId): array {
		$matches = $this->resolverKeyValues(allValues: $this->appConfig->getAllValues($appId));
		ksort($matches);

		return $matches;
	}//end enumerateAppConfigs()

	/**
	 * Keep the resolver-shaped keys that carry a non-empty value, under their canonical key.
	 *
	 * @param array<mixed> $allValues Every app-config value of the app.
	 *
	 * @return array<string, string> Canonical key → raw configured value.
	 */
	private function resolverKeyValues(array $allValues): array {
		$matches = [];
		foreach ($allValues as $key => $value) {
			$resolved = $this->matchResolverKey(key: $key);
			if ($resolved === null) {
				continue;
			}

			// Skip empty values; an empty IAppConfig string means "unset"
			// in NC and we don't want to advertise it as a configured key.
			$stringValue = (string)$value;
			if ($stringValue !== '') {
				$matches[$resolved] = $stringValue;
			}
		}

		return $matches;
	}//end resolverKeyValues()

	/**
	 * Clear request-scoped caches. Test hook + defensive tenant-switch path.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/register-resolver-service/spec.md
	 *   (Requirement: service MUST cache resolved entities at the request scope — clear hook used
	 *   by maybeFlushOnTenantSwitch + by test suites needing a fresh cache between cases.)
	 */
	public function clearCache(): void {
		$this->registerCache = [];
		$this->schemaCache = [];

	}//end clearCache()

	/**
	 * Match a raw config-key string to its canonical resolver key.
	 *
	 * Returns null when the key does not look like a resolver key.
	 * Bare `register` / `schema` are surfaced as `default_register` /
	 * `default_schema` per the documented naming convention.
	 *
	 * @param string $key Raw IAppConfig key.
	 *
	 * @return string|null Canonical resolver-key form, or null if not a resolver key.
	 */
	private function matchResolverKey(string $key): ?string {
		if ($key === 'register') {
			return 'default_register';
		}

		if ($key === 'schema') {
			return 'default_schema';
		}

		if (preg_match('/^[A-Za-z][A-Za-z0-9_-]*_(register|schema|property)$/', $key) === 1) {
			return $key;
		}

		return null;
	}//end matchResolverKey()

	/**
	 * Defensive cache flush when the active tenant changes mid-request.
	 *
	 * Rare in practice (admin tooling switching scopes) but cheap to
	 * check and prevents the cache from returning an entity scoped to
	 * the previous tenant.
	 *
	 * @return void
	 */
	private function maybeFlushOnTenantSwitch(): void {
		try {
			$active = $this->organisationService->getActiveOrganisation();
		} catch (\Throwable $e) {
			// The resolver must not fail just because the active-org
			// lookup misfires; treat as "no current tenant" for the
			// purpose of switch detection.
			$this->logger->debug(
				message: '[RegisterResolverService] getActiveOrganisation threw — skipping switch check',
				context: ['file' => __FILE__, 'line' => __LINE__, 'exception' => $e->getMessage()]
			);
			return;
		}

		$this->noteTenant(active: $active);
	}//end maybeFlushOnTenantSwitch()

	/**
	 * Record the active tenant, clearing the caches when it differs from the last one seen.
	 *
	 * @param Organisation|null $active The active organisation, if any.
	 *
	 * @return void
	 */
	private function noteTenant(?Organisation $active): void {
		$currentUuid = null;
		if ($active !== null) {
			$currentUuid = $active->getUuid();
		}

		if ($this->lastTenantUuid !== null && $currentUuid !== $this->lastTenantUuid) {
			$this->clearCache();
		}

		$this->lastTenantUuid = $currentUuid;
	}//end noteTenant()
}//end class
