<?php

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Exception\ValidationException;

/**
 * Adapter that exposes a mapper-like API over ObjectService.
 *
 * Allows external apps (e.g. OpenConnector) to interact with OpenRegister
 * objects through a familiar mapper contract without depending on ObjectService
 * internals. Register and schema context are injected once at construction time.
 */
class ObjectServiceMapperAdapter
{
    public function __construct(
        private readonly ObjectService $objectService,
        private readonly int|string|null $register = null,
        private readonly int|string|null $schema = null
    ) {
    }

    public function find(int|string $identifier, ?array $extend = null): ?ObjectEntity
    {
        return $this->objectService->find(
            id: $identifier,
            _extend: $extend ?? [],
            register: $this->register,
            schema: $this->schema
        );
    }

    public function findByUuid(int|string $identifier): ?ObjectEntity
    {
        return $this->find(identifier: $identifier);
    }

    public function findAll(
        array $config = [],
        ?array $filters = null,
        ?array $ids = null,
        ?int $limit = null,
        ?int $offset = null,
        ?array $sort = null,
        ?array $extend = null,
        ?string $search = null
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

        if ($this->register !== null && !isset($config['filters']['register'])) {
            $config['filters']['register'] = $this->register;
        }

        if ($this->schema !== null && !isset($config['filters']['schema'])) {
            $config['filters']['schema'] = $this->schema;
        }

        return $this->objectService->findAll(config: $config);
    }

    public function createFromArray(array $object): ObjectEntity
    {
        return $this->objectService->saveObject(
            object: $object,
            register: $this->register,
            schema: $this->schema
        );
    }

    public function updateFromArray(
        int|string $id,
        array $object,
        bool $validate = true,
        bool $patch = false
    ): ObjectEntity {
        if ($patch === true) {
            return $this->objectService->patchObject((string) $id, $object);
        }

        return $this->objectService->updateObject((string) $id, $object);
    }

    public function update(ObjectEntity $object): ObjectEntity
    {
        return $this->objectService->saveObject(
            object: $object,
            register: $this->register,
            schema: $this->schema
        );
    }

    public function delete(array $criteria): bool
    {
        $id = $criteria['id'] ?? null;
        if ($id === null) {
            throw new ValidationException('No id given to delete');
        }

        return $this->objectService->deleteObject((string) $id);
    }

    public function getSchema(): ?int
    {
        return $this->schema !== null ? (int) $this->schema : null;
    }

    public function getRegister(): ?int
    {
        return $this->register !== null ? (int) $this->register : null;
    }

    /**
     * Return a paginated list of objects matching the given request parameters.
     *
     * Injects the adapter's register and schema into the query when not already
     * present, then delegates to ObjectService::searchObjectsPaginated().
     *
     * @param array $requestParams Raw query parameters (limit, page, filters, etc.).
     *
     * @return array{results: array, total: int, page: int, pages: int}
     */
    public function findAllPaginated(array $requestParams = []): array
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
            'total'   => $result['total']   ?? 0,
            'page'    => $result['page']     ?? 1,
            'pages'   => $result['pages']    ?? 1,
        ];
    }

    /**
     * Return the object-validation handler from the underlying ObjectService.
     *
     * @return mixed
     */
    public function getValidateHandler(): mixed
    {
        return $this->objectService->getValidateHandler();
    }
}
