<?php
/**
 * Adapter that exposes a mapper-like API over ObjectService.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Service
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Exception\ValidationException;
use OCA\OpenRegister\Service\Object\ValidateObject;

/**
 * Adapter that exposes a mapper-like API over ObjectService.
 *
 * Allows external apps (e.g. OpenConnector) to interact with OpenRegister
 * objects through a familiar mapper contract without depending on ObjectService
 * internals. Register and schema context are injected once at construction time.
 */
class ObjectServiceMapperAdapter
{
    /**
     * Constructor.
     *
     * @param ObjectService   $objectService The underlying object service.
     * @param int|string|null $register      Register ID to scope all calls to.
     * @param int|string|null $schema        Schema ID to scope all calls to.
     */
    public function __construct(
        private readonly ObjectService $objectService,
        private readonly int|string|null $register=null,
        private readonly int|string|null $schema=null
    ) {
    }//end __construct()

    /**
     * Find a single object by its ID or UUID.
     *
     * @param int|string $identifier Object ID or UUID.
     * @param array|null $extend     Relations to expand inline.
     *
     * @return ObjectEntity|null
     */
    public function find(int|string $identifier, ?array $extend=null): ?ObjectEntity
    {
        return $this->objectService->find(
            id: $identifier,
            _extend: $extend ?? [],
            register: $this->register,
            schema: $this->schema
        );
    }//end find()

    /**
     * Return a list of objects matching the given criteria.
     *
     * The adapter's register and schema are injected into $config['filters']
     * automatically unless already set by the caller. Context is passed via
     * the $config['filters']['register'] and $config['filters']['schema'] keys —
     * distinct from the _register/_schema underscore-prefixed keys used by
     * findAllPaginated().
     *
     * @param array       $config  Full config array (filters, limit, offset, sort, extend, search, ids).
     * @param array|null  $filters Shorthand filter map; merged into $config['filters'].
     * @param array|null  $ids     Restrict results to these object IDs.
     * @param int|null    $limit   Maximum number of results.
     * @param int|null    $offset  Number of results to skip.
     * @param array|null  $sort    Sort specification.
     * @param array|null  $extend  Relations to expand inline.
     * @param string|null $search  Full-text search term.
     *
     * @return array
     */
    public function findAll(
        array $config=[],
        ?array $filters=null,
        ?array $ids=null,
        ?int $limit=null,
        ?int $offset=null,
        ?array $sort=null,
        ?array $extend=null,
        ?string $search=null
    ): array {
        if ($filters !== null) {
            $config['filters'] = $filters;
        }

        if ($ids !== null) {
            $config['ids'] = $ids;
        }

        if ($limit !== null) {
            $config['limit'] = $limit;
        }

        if ($offset !== null) {
            $config['offset'] = $offset;
        }

        if ($sort !== null) {
            $config['sort'] = $sort;
        }

        if ($extend !== null) {
            $config['extend'] = $extend;
        }

        if ($search !== null) {
            $config['search'] = $search;
        }

        $config['filters'] ??= [];

        if ($this->register !== null && isset($config['filters']['register']) === false) {
            $config['filters']['register'] = $this->register;
        }

        if ($this->schema !== null && isset($config['filters']['schema']) === false) {
            $config['filters']['schema'] = $this->schema;
        }

        return $this->objectService->findAll(config: $config);
    }//end findAll()

    /**
     * Create a new object from a plain data array.
     *
     * The adapter's register and schema are applied automatically.
     *
     * @param array $object Raw object data.
     *
     * @return ObjectEntity
     */
    public function createFromArray(array $object): ObjectEntity
    {
        return $this->objectService->saveObject(
            object: $object,
            register: $this->register,
            schema: $this->schema
        );
    }//end createFromArray()

    /**
     * Update an existing object from a plain data array.
     *
     * When $patch is true, only the supplied fields are changed (PATCH semantics):
     * the existing object is fetched, the incoming fields are merged on top, and
     * the result is saved. When false, the full object is replaced (PUT semantics).
     *
     * Both paths route through saveObject() with the adapter's register/schema
     * context so the correct dynamic table is targeted.
     *
     * Note: the $validate parameter is accepted for interface compatibility but
     * validation is always performed by the underlying ObjectService.
     *
     * @param int|string $id       Object ID or UUID.
     * @param array      $object   New or partial object data.
     * @param bool       $validate Accepted for interface compatibility; has no effect.
     * @param bool       $patch    When true, perform a partial update (PATCH).
     *
     * @return ObjectEntity
     */
    public function updateFromArray(
        int|string $id,
        array $object,
        bool $validate=true,
        bool $patch=false
    ): ObjectEntity {
        if ($patch === true) {
            $existing = $this->objectService->find(
                id: (string) $id,
                register: $this->register,
                schema: $this->schema
            );
            if ($existing === null) {
                throw new ValidationException(
                    message: sprintf('Object "%s" not found or not accessible', $id)
                );
            }
			
            $object = array_merge($existing->getObject(), $object);
        }

        return $this->objectService->saveObject(
            object: array_merge($object, ['id' => (string) $id]),
            register: $this->register,
            schema: $this->schema
        );
    }//end updateFromArray()

    /**
     * Persist an already-hydrated ObjectEntity.
     *
     * The adapter's register and schema are applied; if the entity already
     * belongs to a different register/schema this will override that context.
     *
     * @param ObjectEntity $object The entity to save.
     *
     * @return ObjectEntity
     */
    public function update(ObjectEntity $object): ObjectEntity
    {
        return $this->objectService->saveObject(
            object: $object,
            register: $this->register,
            schema: $this->schema
        );
    }//end update()

    /**
     * Delete an object by criteria array.
     *
     * The array must contain an 'id' key with the object ID or UUID.
     *
     * @param array $criteria Must contain key 'id' with the object ID or UUID.
     *
     * @return bool
     *
     * @throws ValidationException When no 'id' key is present in $criteria.
     */
    public function delete(array $criteria): bool
    {
        $id = $criteria['id'] ?? null;
        if ($id === null) {
            throw new ValidationException(message: 'No id given to delete');
        }

        return $this->objectService->deleteObject((string) $id);
    }//end delete()

    /**
     * Return the schema ID this adapter is scoped to, or null for unconstrained.
     *
     * @return int|null
     */
    public function getSchema(): ?int
    {
        return $this->schema !== null ? (int) $this->schema : null;
    }//end getSchema()

    /**
     * Return the register ID this adapter is scoped to, or null for unconstrained.
     *
     * @return int|null
     */
    public function getRegister(): ?int
    {
        return $this->register !== null ? (int) $this->register : null;
    }//end getRegister()

    /**
     * Return a paginated list of objects matching the given request parameters.
     *
     * Injects the adapter's register and schema into the query using the
     * underscore-prefixed keys (_register, _schema) expected by
     * ObjectService::searchObjectsPaginated() — distinct from the dot-notation
     * keys used by findAll().
     *
     * @param array $requestParams Raw query parameters (e.g. _limit, page, _search).
     *
     * @return array{results: array, total: int, page: int, pages: int}
     */
    public function findAllPaginated(array $requestParams=[]): array
    {
        if ($this->register !== null && isset($requestParams['_register']) === false) {
            $requestParams['_register'] = $this->register;
        }

        if ($this->schema !== null && isset($requestParams['_schema']) === false) {
            $requestParams['_schema'] = $this->schema;
        }

        $result = $this->objectService->searchObjectsPaginated(query: $requestParams);

        return [
            'results' => $result['results'] ?? [],
            'total'   => $result['total'] ?? 0,
            'page'    => $result['page'] ?? 1,
            'pages'   => $result['pages'] ?? 1,
        ];
    }//end findAllPaginated()

    /**
     * Return the object-validation handler from the underlying ObjectService.
     *
     * @return ValidateObject
     */
    public function getValidateHandler(): ValidateObject
    {
        return $this->objectService->getValidateHandler();
    }//end getValidateHandler()
}//end class
