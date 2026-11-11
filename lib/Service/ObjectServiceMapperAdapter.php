<?php

/**
 * Adapter that exposes a mapper-like API over ObjectService.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Service
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use OCA\OpenRegister\Kernel\Contracts\ListQuery;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Exception\ValidationException;
use OCA\OpenRegister\Service\Object\ValidateObjectInterface;
use OCA\OpenRegister\Service\Object\ObjectDeletion;
use OCA\OpenRegister\Service\Object\ObjectFindTarget;

/**
 * Adapter that exposes a mapper-like API over ObjectService.
 *
 * Allows external apps (e.g. OpenConnector) to interact with OpenRegister
 * objects through a familiar mapper contract without depending on ObjectService
 * internals. Register and schema context are injected once at construction time.
 */
class ObjectServiceMapperAdapter {
	/**
	 * Constructor.
	 *
	 * @param ObjectService $objectService The underlying object service.
	 * @param int|string|null $register Register ID to scope all calls to.
	 * @param int|string|null $schema Schema ID to scope all calls to.
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly int|string|null $register = null,
		private readonly int|string|null $schema = null,
	) {
	}//end __construct()

	/**
	 * Find a single object by its ID or UUID.
	 *
	 * @param int|string $identifier Object ID or UUID.
	 * @param array|null $extend Relations to expand inline.
	 *
	 * @return ObjectEntity|null
	 *
	 * @spec exclude Facade plumbing: mapper-shaped adapter delegating to ObjectService::find with bound register/schema; no standalone contract.
	 */
	public function find(int|string $identifier, ?array $extend = null): ?ObjectEntity {
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
	 * @param array $config Full config array (filters, limit, offset, sort, extend, search, ids).
	 *
	 * @return array
	 *
	 * @spec exclude Facade plumbing: register/schema injection then delegate to ObjectService::findAll;
	 *              no standalone contract. findAllFor() takes a ListQuery instead of config keys.
	 */
	public function findAll(array $config = []): array {
		return $this->findAllConfigured(config: $config);
	}//end findAll()

	/**
	 * Return a list of objects for one listing query.
	 *
	 * The query's limit, filters and sort replace the config's; its offset and
	 * search do so when set. The adapter's register and schema are injected as
	 * in findAll().
	 *
	 * @param ListQuery $query  The limit, offset, filters, sort and search of the listing.
	 * @param array     $config Further config (ids, extend, ...).
	 *
	 * @return array
	 *
	 * @spec exclude Facade plumbing: the ListQuery form of findAll(); no standalone contract.
	 */
	public function findAllFor(ListQuery $query, array $config = []): array {
		$overrides = ['filters' => $query->filters, 'limit' => $query->limit, 'offset' => $query->offset];
		$overrides += ['sort' => $query->sort, 'search' => $query->search];
		return $this->findAllConfigured(config: $this->withOverrides(config: $config, overrides: $overrides));
	}//end findAllFor()

	/**
	 * List the objects of a finished config, in the bound register and schema.
	 *
	 * @param array $config The config with the caller's arguments applied.
	 *
	 * @return array
	 */
	private function findAllConfigured(array $config): array {
		return $this->objectService->findAll(config: $this->withBoundScope(config: $config));
	}//end findAllConfigured()

	/**
	 * Overlay each non-null argument on the config, in argument order.
	 *
	 * @param array $config The caller's config.
	 * @param array<string, mixed> $overrides The shorthand arguments by config key.
	 *
	 * @return array The config with every given argument applied.
	 */
	private function withOverrides(array $config, array $overrides): array {
		foreach ($overrides as $key => $value) {
			if ($value !== null) {
				$config[$key] = $value;
			}
		}

		return $config;
	}//end withOverrides()

	/**
	 * Fill the config's register/schema filters from the bound scope, unless the caller set them.
	 *
	 * @param array $config The config.
	 *
	 * @return array The config with filters always present.
	 */
	private function withBoundScope(array $config): array {
		$config['filters'] ??= [];

		if ($this->register !== null && isset($config['filters']['register']) === false) {
			$config['filters']['register'] = $this->register;
		}

		if ($this->schema !== null && isset($config['filters']['schema']) === false) {
			$config['filters']['schema'] = $this->schema;
		}

		return $config;
	}//end withBoundScope()

	/**
	 * Create a new object from a plain data array.
	 *
	 * The adapter's register and schema are applied automatically.
	 *
	 * @param array $object Raw object data.
	 *
	 * @return ObjectEntity
	 *
	 * @spec exclude Facade plumbing: delegate to ObjectService::saveObject with bound register/schema; no standalone contract.
	 */
	public function createFromArray(array $object): ObjectEntity {
		return $this->objectService->saveObject(
			object: $object,
			register: $this->register,
			schema: $this->schema
		);
	}//end createFromArray()

	/**
	 * Replace an object with the given data (PUT).
	 *
	 * @param int|string $id     Object ID or UUID.
	 * @param array      $object The full object data.
	 *
	 * @return ObjectEntity
	 *
	 * @spec exclude Facade plumbing: PUT; delegates to ObjectService::saveObject.
	 */
	public function replaceFromArray(int|string $id, array $object): ObjectEntity {
		return $this->objectService->saveObject(
			object: array_merge($object, ['id' => (string)$id]),
			register: $this->register,
			schema: $this->schema
		);
	}//end replaceFromArray()

	/**
	 * Merge the given data onto the existing object (PATCH).
	 *
	 * The existing object is read in the bound scope first; nothing is written
	 * when that read finds nothing.
	 *
	 * @param int|string $id     Object ID or UUID.
	 * @param array      $object The partial object data.
	 *
	 * @return ObjectEntity
	 *
	 * @throws ValidationException When the scoped read finds nothing.
	 *
	 * @spec exclude Facade plumbing: PATCH; delegates to ObjectService::saveObject.
	 */
	public function patchFromArray(int|string $id, array $object): ObjectEntity {
		return $this->replaceFromArray(id: $id, object: array_merge($this->existingObject(id: $id)->getObject(), $object));
	}//end patchFromArray()

	/**
	 * The object a PATCH merges onto, read in the bound scope as an audit lookup.
	 *
	 * @param int|string $id Object ID or UUID.
	 *
	 * @return ObjectEntity The existing object.
	 *
	 * @throws ValidationException When the scoped read finds nothing; nothing is written then.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) WriteCause::asLookup() is the ambient audit-cause frame; there is no instance to inject.
	 */
	private function existingObject(int|string $id): ObjectEntity {
		$existing = WriteCause::asLookup(fn () => $this->objectService->find(
			id: (string)$id,
			register: $this->register,
			schema: $this->schema
		));
		if ($existing === null) {
			throw new ValidationException(
				message: sprintf('Object "%s" not found or not accessible', $id)
			);
		}

		return $existing;
	}//end existingObject()

	/**
	 * Persist an already-hydrated ObjectEntity.
	 *
	 * The adapter's register and schema are applied; if the entity already
	 * belongs to a different register/schema this will override that context.
	 *
	 * @param ObjectEntity $object The entity to save.
	 *
	 * @return ObjectEntity
	 *
	 * @spec exclude Facade plumbing: delegate to ObjectService::saveObject with bound register/schema; no standalone contract.
	 */
	public function update(ObjectEntity $object): ObjectEntity {
		return $this->objectService->saveObject(
			object: $object,
			register: $this->register,
			schema: $this->schema
		);
	}//end update()

	/**
	 * Delete an object by criteria array.
	 *
	 * The array must contain an 'id' key with the object ID or UUID. The
	 * adapter's bound `(register, schema)` is forwarded to
	 * `ObjectService::deleteObject()` so the deletion is scoped to the
	 * adapter's magic table — a UUID that lives in a different
	 * `(register, schema)` magic table raises `DoesNotExistException`
	 * instead of being silently deleted (see #1638).
	 *
	 * @param array $criteria Must contain key 'id' with the object ID or UUID.
	 *
	 * @return bool
	 *
	 * @throws ValidationException When no 'id' key is present in $criteria.
	 * @throws \OCP\AppFramework\Db\DoesNotExistException When the adapter is
	 *                                                    bound to a specific `(register, schema)` and the UUID is not
	 *                                                    present in that magic table.
	 *
	 * @spec exclude Facade plumbing: id-extraction then delegate to ObjectService::deleteObject (scoped delete
	 *              owned by ObjectService); no standalone contract.
	 */
	public function delete(array $criteria): bool {
		$id = $criteria['id'] ?? null;
		if ($id === null) {
			throw new ValidationException(message: 'No id given to delete');
		}

		return $this->objectService->deleteObject(
			deletion: new ObjectDeletion(
				uuid: (string)$id,
				target: new ObjectFindTarget(register: $this->register, schema: $this->schema)
			)
		);
	}//end delete()

	/**
	 * Return the schema ID this adapter is scoped to, or null for unconstrained.
	 *
	 * @return int|null
	 */
	public function getSchema(): ?int {
		if ($this->schema !== null) {
			return (int)$this->schema;
		}

		return null;
	}//end getSchema()

	/**
	 * Return the register ID this adapter is scoped to, or null for unconstrained.
	 *
	 * @return int|null
	 */
	public function getRegister(): ?int {
		if ($this->register !== null) {
			return (int)$this->register;
		}

		return null;
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
	 *
	 * @spec exclude Facade plumbing: register/schema injection then delegate to ObjectService::searchObjectsPaginated; no standalone contract.
	 */
	public function findAllPaginated(array $requestParams = []): array {
		if ($this->register !== null && isset($requestParams['_register']) === false) {
			$requestParams['_register'] = $this->register;
		}

		if ($this->schema !== null && isset($requestParams['_schema']) === false) {
			$requestParams['_schema'] = $this->schema;
		}

		$result = $this->objectService->searchObjectsPaginated(query: $requestParams);

		return [
			'results' => $result['results'] ?? [],
			'total' => $result['total'] ?? 0,
			'page' => $result['page'] ?? 1,
			'pages' => $result['pages'] ?? 1,
		];
	}//end findAllPaginated()

	/**
	 * Return the object-validation handler from the underlying ObjectService.
	 *
	 * @return ValidateObjectInterface
	 */
	public function getValidateHandler(): ValidateObjectInterface {
		return $this->objectService->getValidateHandler();
	}//end getValidateHandler()
}//end class
