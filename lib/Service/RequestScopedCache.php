<?php

/**
 * OpenRegister RequestScopedCache
 *
 * Provides a shared in-memory cache that persists for the duration of a single
 * HTTP request. Nextcloud DI registers services as shared by default, so all
 * service injections within one request receive the same instance.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

/**
 * Request-scoped in-memory cache shared across all services within one HTTP request
 *
 * This cache holds entities (schemas, registers, objects, organisations) so that
 * repeated lookups within a single request hit memory instead of the database.
 * The cache is automatically discarded when the request ends (PHP process exit).
 *
 * Usage: inject this service into mappers/services that do repeated lookups.
 * Namespaces prevent key collisions between entity types.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author  Conduction Development Team <info@conduction.nl>
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version 1.0.0
 *
 * @link https://www.OpenRegister.app
 */
class RequestScopedCache
{

    /**
     * Cache storage indexed by namespace and key
     *
     * @var array<string, array<string, mixed>>
     */
    private array $cache = [];

    /**
     * Retrieve a value from the cache
     *
     * @param string $namespace The cache namespace (e.g. 'schema', 'register')
     * @param string $key       The cache key (e.g. entity id, uuid, or slug)
     *
     * @return mixed The cached value, or null if not found
     */
    public function get(string $namespace, string $key): mixed
    {
        return $this->cache[$namespace][$key] ?? null;
    }//end get()

    /**
     * Store a value in the cache
     *
     * @param string $namespace The cache namespace
     * @param string $key       The cache key
     * @param mixed  $value     The value to cache
     *
     * @return void
     */
    public function set(string $namespace, string $key, mixed $value): void
    {
        $this->cache[$namespace][$key] = $value;
    }//end set()

    /**
     * Check whether a key exists in the cache
     *
     * @param string $namespace The cache namespace
     * @param string $key       The cache key
     *
     * @return bool True if the key exists (even if value is null)
     */
    public function has(string $namespace, string $key): bool
    {
        return array_key_exists(key: $namespace, array: $this->cache)
            && array_key_exists(key: $key, array: $this->cache[$namespace]);
    }//end has()

    /**
     * Retrieve multiple values from the cache at once
     *
     * @param string   $namespace The cache namespace
     * @param string[] $keys      The cache keys to look up
     *
     * @return array<string, mixed> Map of key => value for found entries only
     */
    public function getMultiple(string $namespace, array $keys): array
    {
        $results = [];
        foreach ($keys as $key) {
            if ($this->has(namespace: $namespace, key: $key) === true) {
                $results[$key] = $this->cache[$namespace][$key];
            }
        }

        return $results;
    }//end getMultiple()

    /**
     * Clear a specific namespace or the entire cache
     *
     * @param string|null $namespace Namespace to clear, or null to clear everything
     *
     * @return void
     */
    public function clear(?string $namespace=null): void
    {
        if ($namespace !== null) {
            unset($this->cache[$namespace]);
            return;
        }

        $this->cache = [];
    }//end clear()
}//end class
