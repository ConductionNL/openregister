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
 * @spec openspec/changes/register-resolver-service/specs/register-resolver-service/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Resolver\Exception\MissingConfigException;
use OCA\OpenRegister\Service\Resolver\Exception\PropertyNotFoundException;
use OCA\OpenRegister\Service\Resolver\Exception\RegisterNotFoundException;
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
 * @spec openspec/changes/register-resolver-service/specs/register-resolver-service/spec.md
 */
final class RegisterResolverService
{

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
     * @param IAppConfig          $appConfig           The app config store.
     * @param RegisterMapper      $registerMapper      Register lookup mapper.
     * @param SchemaMapper        $schemaMapper        Schema lookup mapper.
     * @param OrganisationService $organisationService Active-tenant resolution.
     * @param LoggerInterface     $logger              Logger for diagnostics.
     */
    public function __construct(
        private readonly IAppConfig $appConfig,
        private readonly RegisterMapper $registerMapper,
        private readonly SchemaMapper $schemaMapper,
        private readonly OrganisationService $organisationService,
        private readonly LoggerInterface $logger,
    ) {

    }//end __construct()

    /**
     * Read the configured slug/UUID for a register from app config.
     *
     * Honours the documented naming convention (`<context>_register`
     * preferred; bare `register` grandfathered).
     *
     * @param string      $appId            Consumer app id.
     * @param string      $configKey        Config key to read.
     * @param string|null $default          Fallback value if config unset.
     * @param string|null $organisationUuid Optional org override (unused here, kept for signature parity).
     *
     * @return string The configured slug or UUID.
     *
     * @throws MissingConfigException If the config is unset and no default was provided.
     *
     * @spec openspec/changes/register-resolver-service/specs/register-resolver-service/spec.md
     *   (Requirement: resolveRegisterId)
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter) The organisationUuid parameter is reserved for
     * signature parity with resolveRegister; pure id reads never need it but the unified contract
     * lets callers pass the same tuple through.
     */
    public function resolveRegisterId(
        string $appId,
        string $configKey,
        ?string $default=null,
        ?string $organisationUuid=null,
    ): string {
        $value = $this->appConfig->getValueString($appId, $configKey, '');
        if ($value !== '') {
            return $value;
        }

        if ($default !== null && $default !== '') {
            return $default;
        }

        throw new MissingConfigException($appId, $configKey);

    }//end resolveRegisterId()

    /**
     * Read the configured slug/UUID for a schema from app config.
     *
     * @param string      $appId            Consumer app id.
     * @param string      $configKey        Config key to read.
     * @param string|null $default          Fallback value if config unset.
     * @param string|null $organisationUuid Optional org override (unused here, kept for signature parity).
     *
     * @return string The configured slug or UUID.
     *
     * @throws MissingConfigException If the config is unset and no default was provided.
     *
     * @spec openspec/changes/register-resolver-service/specs/register-resolver-service/spec.md
     *   (Requirement: resolveSchemaId)
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter) Same parity rationale as resolveRegisterId.
     */
    public function resolveSchemaId(
        string $appId,
        string $configKey,
        ?string $default=null,
        ?string $organisationUuid=null,
    ): string {
        $value = $this->appConfig->getValueString($appId, $configKey, '');
        if ($value !== '') {
            return $value;
        }

        if ($default !== null && $default !== '') {
            return $default;
        }

        throw new MissingConfigException($appId, $configKey);

    }//end resolveSchemaId()

    /**
     * Read the configured slug/UUID/name for a schema property from app config.
     *
     * Honours the documented naming convention (`<context>_property`
     * preferred). Apps storing a property identifier (slug, UUID, or
     * canonical name) in app config can resolve it through the same
     * resolver surface they already use for registers and schemas.
     *
     * @param string      $appId            Consumer app id.
     * @param string      $configKey        Config key to read (e.g. `swc_type_property`).
     * @param string|null $default          Fallback value if config unset.
     * @param string|null $organisationUuid Optional org override (unused here, kept for signature parity).
     *
     * @return string The configured slug, UUID, or name.
     *
     * @throws MissingConfigException If the config is unset and no default was provided.
     *
     * @spec openspec/changes/register-resolver-service/specs/register-resolver-service/spec.md
     *   (Requirement: resolvePropertyId)
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter) Same parity rationale as resolveRegisterId.
     */
    public function resolvePropertyId(
        string $appId,
        string $configKey,
        ?string $default=null,
        ?string $organisationUuid=null,
    ): string {
        $value = $this->appConfig->getValueString($appId, $configKey, '');
        if ($value !== '') {
            return $value;
        }

        if ($default !== null && $default !== '') {
            return $default;
        }

        throw new MissingConfigException($appId, $configKey);

    }//end resolvePropertyId()

    /**
     * Resolve `<context>_register` config to a hydrated Register entity.
     *
     * @param string      $appId            Consumer app id.
     * @param string      $configKey        Config key to read.
     * @param string|null $default          Fallback slug/UUID if config unset.
     * @param string|null $organisationUuid Optional explicit-tenant override.
     *
     * @return Register The hydrated Register entity.
     *
     * @throws MissingConfigException    If config is unset and no default.
     * @throws RegisterNotFoundException If the value does not resolve in the caller tenant.
     *
     * @spec openspec/changes/register-resolver-service/specs/register-resolver-service/spec.md
     *   (Requirement: resolveRegister)
     */
    public function resolveRegister(
        string $appId,
        string $configKey,
        ?string $default=null,
        ?string $organisationUuid=null,
    ): Register {
        $this->maybeFlushOnTenantSwitch();

        $slugOrUuid = $this->resolveRegisterId($appId, $configKey, $default, $organisationUuid);
        $cacheKey   = $this->cacheKey($appId, $configKey, $organisationUuid);

        if (isset($this->registerCache[$cacheKey]) === true) {
            return $this->registerCache[$cacheKey];
        }

        try {
            // When an explicit org override was passed, bypass multi-tenancy
            // filtering at the mapper layer and verify membership ourselves
            // after fetch; otherwise rely on the mapper's implicit caller-
            // tenant scoping via applyOrganisationFilter().
            if ($organisationUuid !== null) {
                $register = $this->registerMapper->find(id: $slugOrUuid, _multitenancy: false);
                if ($register->getOrganisation() !== null
                    && $register->getOrganisation() !== $organisationUuid
                ) {
                    throw new RegisterNotFoundException($appId, $configKey, $slugOrUuid);
                }
            } else {
                $register = $this->registerMapper->find(id: $slugOrUuid);
            }
        } catch (DoesNotExistException | MultipleObjectsReturnedException $e) {
            throw new RegisterNotFoundException($appId, $configKey, $slugOrUuid, $e);
        }

        $this->registerCache[$cacheKey] = $register;

        return $register;

    }//end resolveRegister()

    /**
     * Resolve `<context>_schema` config to a hydrated Schema entity.
     *
     * @param string      $appId            Consumer app id.
     * @param string      $configKey        Config key to read.
     * @param string|null $default          Fallback slug/UUID if config unset.
     * @param string|null $organisationUuid Optional explicit-tenant override.
     *
     * @return Schema The hydrated Schema entity.
     *
     * @throws MissingConfigException  If config is unset and no default.
     * @throws SchemaNotFoundException If the value does not resolve in the caller tenant.
     *
     * @spec openspec/changes/register-resolver-service/specs/register-resolver-service/spec.md
     *   (Requirement: resolveSchema)
     */
    public function resolveSchema(
        string $appId,
        string $configKey,
        ?string $default=null,
        ?string $organisationUuid=null,
    ): Schema {
        $this->maybeFlushOnTenantSwitch();

        $slugOrUuid = $this->resolveSchemaId($appId, $configKey, $default, $organisationUuid);
        $cacheKey   = $this->cacheKey($appId, $configKey, $organisationUuid);

        if (isset($this->schemaCache[$cacheKey]) === true) {
            return $this->schemaCache[$cacheKey];
        }

        try {
            if ($organisationUuid !== null) {
                $schema = $this->schemaMapper->find(id: $slugOrUuid, _multitenancy: false);
                if ($schema->getOrganisation() !== null
                    && $schema->getOrganisation() !== $organisationUuid
                ) {
                    throw new SchemaNotFoundException($appId, $configKey, $slugOrUuid);
                }
            } else {
                $schema = $this->schemaMapper->find(id: $slugOrUuid);
            }
        } catch (DoesNotExistException | MultipleObjectsReturnedException $e) {
            throw new SchemaNotFoundException($appId, $configKey, $slugOrUuid, $e);
        }

        $this->schemaCache[$cacheKey] = $schema;

        return $schema;

    }//end resolveSchema()

    /**
     * Resolve a schema property by configured identifier.
     *
     * Combines `resolvePropertyId` with a schema lookup, then locates
     * the matching property on the resolved schema. The configured
     * property identifier is matched against the property's array key,
     * its `slug`, its `name`, or its `id` (in that order) so apps can
     * store any stable identifier they prefer.
     *
     * Pattern mirrors `resolveRegister` / `resolveSchema`: read config,
     * hydrate, throw if absent. Returned shape is the raw property
     * definition array from `Schema::getProperties()[$key]` — callers
     * read whatever field they need (`id`, `type`, `format`, etc.).
     *
     * @param string      $appId             Consumer app id.
     * @param string      $schemaConfigKey   Config key for the schema (e.g. `swc_schema`).
     * @param string      $propertyConfigKey Config key for the property (e.g. `swc_type_property`).
     * @param string|null $propertyDefault   Fallback property id if config unset.
     * @param string|null $schemaDefault     Fallback schema id if config unset.
     * @param string|null $organisationUuid  Optional explicit-tenant override.
     *
     * @return array{0: string, 1: array<string, mixed>} Tuple of the resolved property key + the property
     *                                                   definition array. The key is the property's array
     *                                                   key on `Schema::getProperties()`, which is also
     *                                                   the canonical name used in object payloads.
     *
     * @throws MissingConfigException    If either config is unset and no default given.
     * @throws SchemaNotFoundException   If the schema id does not resolve.
     * @throws PropertyNotFoundException If the property id does not match any property on the schema.
     *
     * @spec openspec/changes/register-resolver-service/specs/register-resolver-service/spec.md
     *   (Requirement: resolveProperty)
     */
    public function resolveProperty(
        string $appId,
        string $schemaConfigKey,
        string $propertyConfigKey,
        ?string $propertyDefault=null,
        ?string $schemaDefault=null,
        ?string $organisationUuid=null,
    ): array {
        $schema     = $this->resolveSchema($appId, $schemaConfigKey, $schemaDefault, $organisationUuid);
        $propertyId = $this->resolvePropertyId($appId, $propertyConfigKey, $propertyDefault, $organisationUuid);

        $properties = $schema->getProperties();
        $schemaId   = (string) ($schema->getSlug() ?? $schema->getUuid() ?? '');

        // Match by array key first (canonical name in property maps).
        if (isset($properties[$propertyId]) === true && is_array($properties[$propertyId]) === true) {
            return [$propertyId, $properties[$propertyId]];
        }

        // Fall back to scanning by slug / name / id.
        foreach ($properties as $key => $definition) {
            if (is_array($definition) === false) {
                continue;
            }

            $candidates = [
                (string) ($definition['slug'] ?? ''),
                (string) ($definition['name'] ?? ''),
                (string) ($definition['id'] ?? ''),
            ];

            if (in_array($propertyId, $candidates, true) === true) {
                return [(string) $key, $definition];
            }
        }

        throw new PropertyNotFoundException(
            appId: $appId,
            configKey: $propertyConfigKey,
            resolvedValue: $propertyId,
            schemaIdentifier: $schemaId,
        );

    }//end resolveProperty()

    /**
     * Convenience: resolve a register and schema in a single call.
     *
     * @param string      $appId            Consumer app id.
     * @param string      $registerKey      Config key for the register.
     * @param string      $schemaKey        Config key for the schema.
     * @param string|null $organisationUuid Optional explicit-tenant override.
     *
     * @return RegisterSchemaPair Pair of resolved entities + raw ids.
     *
     * @throws MissingConfigException    If either config is unset.
     * @throws RegisterNotFoundException If the register cannot be resolved.
     * @throws SchemaNotFoundException   If the schema cannot be resolved.
     *
     * @spec openspec/changes/register-resolver-service/specs/register-resolver-service/spec.md
     *   (Requirement: resolvePair)
     */
    public function resolvePair(
        string $appId,
        string $registerKey,
        string $schemaKey,
        ?string $organisationUuid=null,
    ): RegisterSchemaPair {
        $registerId = $this->resolveRegisterId($appId, $registerKey, null, $organisationUuid);
        $schemaId   = $this->resolveSchemaId($appId, $schemaKey, null, $organisationUuid);
        $register   = $this->resolveRegister($appId, $registerKey, null, $organisationUuid);
        $schema     = $this->resolveSchema($appId, $schemaKey, null, $organisationUuid);

        return new RegisterSchemaPair(
            registerId: $registerId,
            schemaId: $schemaId,
            register: $register,
            schema: $schema,
        );

    }//end resolvePair()

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
     * @spec openspec/changes/register-resolver-service/specs/register-resolver-service/spec.md
     *   (Requirement: enumerateAppConfigs)
     */
    public function enumerateAppConfigs(string $appId): array
    {
        $allValues = $this->appConfig->getAllValues($appId);
        $matches   = [];
        foreach ($allValues as $key => $value) {
            $resolved = $this->matchResolverKey($key);
            if ($resolved === null) {
                continue;
            }

            // Skip empty values; an empty IAppConfig string means "unset"
            // in NC and we don't want to advertise it as a configured key.
            $stringValue = (string) $value;
            if ($stringValue === '') {
                continue;
            }

            $matches[$resolved] = $stringValue;
        }

        ksort($matches);

        return $matches;

    }//end enumerateAppConfigs()

    /**
     * Clear request-scoped caches. Test hook + defensive tenant-switch path.
     *
     * @return void
     *
     * @spec openspec/changes/register-resolver-service/specs/register-resolver-service/spec.md
     *   (Requirement: service MUST cache resolved entities at the request scope — clear hook used
     *   by maybeFlushOnTenantSwitch + by test suites needing a fresh cache between cases.)
     */
    public function clearCache(): void
    {
        $this->registerCache = [];
        $this->schemaCache   = [];

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
    private function matchResolverKey(string $key): ?string
    {
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
     * Build the per-tuple cache key used by both internal caches.
     *
     * @param string      $appId            Consumer app id.
     * @param string      $configKey        Config key being resolved.
     * @param string|null $organisationUuid Optional explicit-tenant override.
     *
     * @return string Cache key string.
     */
    private function cacheKey(string $appId, string $configKey, ?string $organisationUuid): string
    {
        return $appId.':'.$configKey.':'.($organisationUuid ?? '');

    }//end cacheKey()

    /**
     * Defensive cache flush when the active tenant changes mid-request.
     *
     * Rare in practice (admin tooling switching scopes) but cheap to
     * check and prevents the cache from returning an entity scoped to
     * the previous tenant.
     *
     * @return void
     */
    private function maybeFlushOnTenantSwitch(): void
    {
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

        $currentUuid = ($active !== null) ? $active->getUuid() : null;
        if ($this->lastTenantUuid !== null && $currentUuid !== $this->lastTenantUuid) {
            $this->clearCache();
        }

        $this->lastTenantUuid = $currentUuid;

    }//end maybeFlushOnTenantSwitch()
}//end class
