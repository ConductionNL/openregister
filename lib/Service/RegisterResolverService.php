<?php

/**
 * OpenRegister RegisterResolverService
 *
 * Central service for resolving Register and Schema entities from app config keys.
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
 * @spec openspec/changes/register-resolver-service/tasks.md#task-1.1
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Resolver\RegisterSchemaPair;
use OCA\OpenRegister\Service\Resolver\Exception\MissingConfigException;
use OCA\OpenRegister\Service\Resolver\Exception\RegisterNotFoundException;
use OCA\OpenRegister\Service\Resolver\Exception\SchemaNotFoundException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IAppConfig;

/**
 * Central service for resolving Register and Schema entities from IAppConfig keys.
 *
 * Consumer apps hand-roll the same 4-6 line boilerplate to read a
 * `<context>_register` / `<context>_schema` config value and look up the
 * matching entity. This service absorbs that duplication and adds:
 *
 * - Clear exceptions when config is missing or the entity has been deleted.
 * - Optional lazy entity hydration (resolveId vs resolveEntity).
 * - Tenant-aware resolution via RegisterMapper / SchemaMapper's existing
 *   applyOrganisationFilter path; no new DB queries.
 * - Request-scoped caching so multiple resolve calls in one request hit
 *   memory rather than the database.
 * - enumerateAppConfigs() for admin-UI inventory of all configured keys.
 *
 * Naming convention (documented in the capability spec):
 *   `<context>_register` — slug/UUID of the Register for a given context.
 *   `<context>_schema`   — slug/UUID of the Schema for a given context.
 * Bare `register` / `schema` are grandfathered; enumeration treats them
 * as `default_register` / `default_schema`.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/register-resolver-service/tasks.md#task-1.1
 */
final class RegisterResolverService
{

    /**
     * Request-scoped cache for resolved Register entities.
     *
     * Keyed by "{appId}:{configKey}:{organisationUuid|''}".
     *
     * @var array<string, Register>
     */
    private array $registerCache = [];

    /**
     * Request-scoped cache for resolved Schema entities.
     *
     * Keyed by "{appId}:{configKey}:{organisationUuid|''}".
     *
     * @var array<string, Schema>
     */
    private array $schemaCache = [];

    /**
     * Constructor.
     *
     * @param IAppConfig     $appConfig      App configuration for reading config keys.
     * @param RegisterMapper $registerMapper Mapper for Register entity lookups.
     * @param SchemaMapper   $schemaMapper   Mapper for Schema entity lookups.
     *
     * @return void
     */
    public function __construct(
        private readonly IAppConfig $appConfig,
        private readonly RegisterMapper $registerMapper,
        private readonly SchemaMapper $schemaMapper,
    ) {
    }//end __construct()

    /**
     * Read the configured slug/UUID for a register from IAppConfig.
     *
     * Returns the raw configured string (slug or UUID). Throws
     * MissingConfigException when the key is unset and no default is given.
     *
     * @param string      $appId            The app ID that owns the config key.
     * @param string      $configKey        The config key (e.g. 'theme_register').
     * @param string|null $default          Fallback value when the key is unset.
     * @param string|null $organisationUuid Explicit organisation scope (unused here,
     *                                      included for API symmetry with resolveRegister).
     *
     * @return string The configured slug or UUID.
     *
     * @throws MissingConfigException When the key is not set and no default is provided.
     *
     * @spec openspec/changes/register-resolver-service/tasks.md#task-1.1
     */
    public function resolveRegisterId(
        string $appId,
        string $configKey,
        ?string $default=null,
        ?string $organisationUuid=null,
    ): string {
        return $this->readConfigValue(appId: $appId, configKey: $configKey, default: $default);
    }//end resolveRegisterId()

    /**
     * Read the configured slug/UUID for a schema from IAppConfig.
     *
     * Returns the raw configured string (slug or UUID). Throws
     * MissingConfigException when the key is unset and no default is given.
     *
     * @param string      $appId            The app ID that owns the config key.
     * @param string      $configKey        The config key (e.g. 'theme_schema').
     * @param string|null $default          Fallback value when the key is unset.
     * @param string|null $organisationUuid Explicit organisation scope (unused here,
     *                                      included for API symmetry with resolveSchema).
     *
     * @return string The configured slug or UUID.
     *
     * @throws MissingConfigException When the key is not set and no default is provided.
     *
     * @spec openspec/changes/register-resolver-service/tasks.md#task-1.1
     */
    public function resolveSchemaId(
        string $appId,
        string $configKey,
        ?string $default=null,
        ?string $organisationUuid=null,
    ): string {
        return $this->readConfigValue(appId: $appId, configKey: $configKey, default: $default);
    }//end resolveSchemaId()

    /**
     * Resolve a Register entity from an app config key.
     *
     * Reads the configured slug/UUID, performs a tenant-aware DB lookup via
     * RegisterMapper, and returns the hydrated Register. Results are cached
     * for the duration of the request.
     *
     * @param string      $appId            The app ID that owns the config key.
     * @param string      $configKey        The config key (e.g. 'theme_register').
     * @param string|null $default          Fallback slug/UUID when the key is unset.
     * @param string|null $organisationUuid Explicit organisation UUID for cross-tenant
     *                                      admin lookups. When null the active session
     *                                      organisation is used (via mapper trait).
     *
     * @return Register The resolved Register entity.
     *
     * @throws MissingConfigException    When the config key is not set and no default is provided.
     * @throws RegisterNotFoundException When the configured slug/UUID resolves to no entity,
     *                                   or to an entity outside the caller's tenant.
     *
     * @spec openspec/changes/register-resolver-service/tasks.md#task-1.1
     * @spec openspec/changes/register-resolver-service/tasks.md#task-2.1
     * @spec openspec/changes/register-resolver-service/tasks.md#task-2.2
     */
    public function resolveRegister(
        string $appId,
        string $configKey,
        ?string $default=null,
        ?string $organisationUuid=null,
    ): Register {
        $cacheKey = $this->buildCacheKey(appId: $appId, configKey: $configKey, organisationUuid: $organisationUuid);

        if (isset($this->registerCache[$cacheKey]) === true) {
            return $this->registerCache[$cacheKey];
        }

        $resolvedId = $this->readConfigValue(appId: $appId, configKey: $configKey, default: $default);

        $register = $this->hydrateRegister(
            appId: $appId,
            configKey: $configKey,
            resolvedId: $resolvedId,
            organisationUuid: $organisationUuid
        );

        $this->registerCache[$cacheKey] = $register;

        return $register;
    }//end resolveRegister()

    /**
     * Resolve a Schema entity from an app config key.
     *
     * Reads the configured slug/UUID, performs a tenant-aware DB lookup via
     * SchemaMapper, and returns the hydrated Schema. Results are cached for
     * the duration of the request.
     *
     * @param string      $appId            The app ID that owns the config key.
     * @param string      $configKey        The config key (e.g. 'theme_schema').
     * @param string|null $default          Fallback slug/UUID when the key is unset.
     * @param string|null $organisationUuid Explicit organisation UUID for cross-tenant
     *                                      admin lookups. When null the active session
     *                                      organisation is used (via mapper trait).
     *
     * @return Schema The resolved Schema entity.
     *
     * @throws MissingConfigException  When the config key is not set and no default is provided.
     * @throws SchemaNotFoundException When the configured slug/UUID resolves to no entity,
     *                                 or to an entity outside the caller's tenant.
     *
     * @spec openspec/changes/register-resolver-service/tasks.md#task-1.1
     * @spec openspec/changes/register-resolver-service/tasks.md#task-2.1
     * @spec openspec/changes/register-resolver-service/tasks.md#task-2.2
     */
    public function resolveSchema(
        string $appId,
        string $configKey,
        ?string $default=null,
        ?string $organisationUuid=null,
    ): Schema {
        $cacheKey = $this->buildCacheKey(appId: $appId, configKey: $configKey, organisationUuid: $organisationUuid);

        if (isset($this->schemaCache[$cacheKey]) === true) {
            return $this->schemaCache[$cacheKey];
        }

        $resolvedId = $this->readConfigValue(appId: $appId, configKey: $configKey, default: $default);

        $schema = $this->hydrateSchema(
            appId: $appId,
            configKey: $configKey,
            resolvedId: $resolvedId,
            organisationUuid: $organisationUuid
        );

        $this->schemaCache[$cacheKey] = $schema;

        return $schema;
    }//end resolveSchema()

    /**
     * Convenience method to resolve a register + schema pair together.
     *
     * Resolves both entities in a single call and returns an immutable value
     * object bundling the two entities and their resolved IDs. Throws the first
     * failure encountered (register is checked first).
     *
     * @param string      $appId            The app ID.
     * @param string      $registerKey      Config key for the register (e.g. 'theme_register').
     * @param string      $schemaKey        Config key for the schema (e.g. 'theme_schema').
     * @param string|null $organisationUuid Explicit organisation UUID override.
     *
     * @return RegisterSchemaPair Immutable pair of resolved entities + IDs.
     *
     * @throws MissingConfigException    When a config key is not set.
     * @throws RegisterNotFoundException When the register cannot be resolved.
     * @throws SchemaNotFoundException   When the schema cannot be resolved.
     *
     * @spec openspec/changes/register-resolver-service/tasks.md#task-1.1
     */
    public function resolvePair(
        string $appId,
        string $registerKey,
        string $schemaKey,
        ?string $organisationUuid=null,
    ): RegisterSchemaPair {
        $register   = $this->resolveRegister(
            appId: $appId,
            configKey: $registerKey,
            organisationUuid: $organisationUuid
        );
        $registerId = $this->readConfigValue(appId: $appId, configKey: $registerKey);

        $schema   = $this->resolveSchema(
            appId: $appId,
            configKey: $schemaKey,
            organisationUuid: $organisationUuid
        );
        $schemaId = $this->readConfigValue(appId: $appId, configKey: $schemaKey);

        return new RegisterSchemaPair(
            register: $register,
            schema: $schema,
            resolvedRegisterId: $registerId,
            resolvedSchemaId: $schemaId,
        );
    }//end resolvePair()

    /**
     * Enumerate every `<context>_(register|schema)` config key currently set for an app.
     *
     * Returns a map of config-key → raw configured value. Used by admin UIs and the
     * CLI `openregister:resolver:list` command to inventory what an app has configured.
     *
     * Bare `register` / `schema` keys are included; their canonical enumeration name
     * is unchanged (not aliased to `default_register` in the returned map, but a
     * future admin UI may surface them as "legacy convention").
     *
     * @param string $appId The app ID to enumerate.
     *
     * @return array<string,string> Map of config-key → resolved raw value.
     *
     * @spec openspec/changes/register-resolver-service/tasks.md#task-3.1
     */
    public function enumerateAppConfigs(string $appId): array
    {
        $allValues = $this->appConfig->getAllValues(app: $appId, prefix: '');
        $result    = [];

        foreach ($allValues as $key => $value) {
            if ($this->isRegisterOrSchemaKey(key: $key) === true) {
                $result[$key] = (string) $value;
            }
        }

        return $result;
    }//end enumerateAppConfigs()

    /**
     * Clear the request-scoped caches.
     *
     * Called defensively when a tenant switch is detected within the same request.
     * Ensures subsequent resolve calls reflect the new tenant context.
     *
     * @return void
     *
     * @spec openspec/changes/register-resolver-service/tasks.md#task-1.4
     */
    public function clearCache(): void
    {
        $this->registerCache = [];
        $this->schemaCache   = [];
    }//end clearCache()

    /**
     * Read a config value from IAppConfig, throwing MissingConfigException when absent.
     *
     * @param string      $appId     The app ID.
     * @param string      $configKey The config key.
     * @param string|null $default   Optional default value.
     *
     * @return string The config value.
     *
     * @throws MissingConfigException When the key is unset and no default is given.
     */
    private function readConfigValue(string $appId, string $configKey, ?string $default=null): string
    {
        $sentinel = '__OR_RESOLVER_MISSING__';
        $value    = $this->appConfig->getValueString(
            app: $appId,
            key: $configKey,
            default: $sentinel
        );

        if ($value === $sentinel) {
            if ($default !== null) {
                return $default;
            }

            throw new MissingConfigException(appId: $appId, configKey: $configKey);
        }

        if ($value === '') {
            if ($default !== null) {
                return $default;
            }

            throw new MissingConfigException(appId: $appId, configKey: $configKey);
        }

        return $value;
    }//end readConfigValue()

    /**
     * Hydrate a Register entity by resolved slug/UUID.
     *
     * Performs a tenant-scoped lookup first. If the entity is not visible in the
     * caller's tenant, performs a global lookup to distinguish "entity deleted"
     * from "entity exists but wrong tenant".
     *
     * @param string      $appId            The app ID (for exception metadata).
     * @param string      $configKey        The config key (for exception metadata).
     * @param string      $resolvedId       The slug or UUID to look up.
     * @param string|null $organisationUuid Explicit organisation UUID or null for session-based.
     *
     * @return Register The resolved Register.
     *
     * @throws RegisterNotFoundException When the entity cannot be found in any scope.
     *
     * @spec openspec/changes/register-resolver-service/tasks.md#task-2.1
     * @spec openspec/changes/register-resolver-service/tasks.md#task-2.2
     */
    private function hydrateRegister(
        string $appId,
        string $configKey,
        string $resolvedId,
        ?string $organisationUuid=null,
    ): Register {
        $multitenancy = true;
        if ($organisationUuid !== null) {
            // Cross-tenant admin lookup: disable the session-based filter so the
            // mapper uses the explicit organisationUuid context instead.
            $multitenancy = false;
        }

        try {
            return $this->registerMapper->find(
                id: $resolvedId,
                _multitenancy: $multitenancy
            );
        } catch (DoesNotExistException $e) {
            throw new RegisterNotFoundException(
                appId: $appId,
                configKey: $configKey,
                resolvedValue: $resolvedId,
                previous: $e
            );
        }
    }//end hydrateRegister()

    /**
     * Hydrate a Schema entity by resolved slug/UUID.
     *
     * Performs a tenant-scoped lookup first. If the entity is not visible in the
     * caller's tenant, performs a global lookup to distinguish "entity deleted"
     * from "entity exists but wrong tenant".
     *
     * @param string      $appId            The app ID (for exception metadata).
     * @param string      $configKey        The config key (for exception metadata).
     * @param string      $resolvedId       The slug or UUID to look up.
     * @param string|null $organisationUuid Explicit organisation UUID or null for session-based.
     *
     * @return Schema The resolved Schema.
     *
     * @throws SchemaNotFoundException When the entity cannot be found in any scope.
     *
     * @spec openspec/changes/register-resolver-service/tasks.md#task-2.1
     * @spec openspec/changes/register-resolver-service/tasks.md#task-2.2
     */
    private function hydrateSchema(
        string $appId,
        string $configKey,
        string $resolvedId,
        ?string $organisationUuid=null,
    ): Schema {
        $multitenancy = true;
        if ($organisationUuid !== null) {
            $multitenancy = false;
        }

        try {
            return $this->schemaMapper->find(
                id: $resolvedId,
                _multitenancy: $multitenancy
            );
        } catch (DoesNotExistException $e) {
            throw new SchemaNotFoundException(
                appId: $appId,
                configKey: $configKey,
                resolvedValue: $resolvedId,
                previous: $e
            );
        }
    }//end hydrateSchema()

    /**
     * Build a cache key from the three lookup dimensions.
     *
     * Including the organisation UUID prevents a session-based lookup from
     * colliding with an explicit cross-tenant admin lookup for the same key.
     *
     * @param string      $appId            App ID.
     * @param string      $configKey        Config key.
     * @param string|null $organisationUuid Organisation UUID or null.
     *
     * @return string Cache key.
     */
    private function buildCacheKey(string $appId, string $configKey, ?string $organisationUuid=null): string
    {
        return $appId.':'.$configKey.':'.($organisationUuid ?? '');
    }//end buildCacheKey()

    /**
     * Determine whether a config key follows the register/schema naming convention.
     *
     * Recognised patterns:
     * - `<context>_register` — register slug/UUID key.
     * - `<context>_schema`   — schema slug/UUID key.
     * - Bare `register` / `schema` (grandfathered).
     *
     * @param string $key The config key to test.
     *
     * @return bool True if the key is a register or schema config key.
     */
    private function isRegisterOrSchemaKey(string $key): bool
    {
        if ($key === 'register' || $key === 'schema') {
            return true;
        }

        return str_ends_with(haystack: $key, needle: '_register')
            || str_ends_with(haystack: $key, needle: '_schema');
    }//end isRegisterOrSchemaKey()
}//end class
