<?php

/**
 * OpenRegister AppHost — Local Store Registry
 *
 * Reads a store from this instance's own register, for a store whose
 * `registry_url` is `local`. There is no HTTP request, so the SSRF guard of
 * the remote path has nothing to guard: the read goes through the object
 * service under the caller's RBAC. Organisation scoping is off, because a
 * catalogue is shared by the whole instance.
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

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Service\ObjectService;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Reads store items from a register on this instance.
 */
class LocalStoreRegistry {

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container Resolves the object service when a read happens.
	 * @param LoggerInterface    $logger    Server-side diagnostics only.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The objects of one schema in one register, as arrays.
	 *
	 * A missing register or schema reads as unreachable, never as an empty
	 * store. When `slug` is asked for, it is matched here as well as passed
	 * to the search, so a filter that is not applied cannot hand back another
	 * item.
	 *
	 * @param string               $register The register slug.
	 * @param string               $schema   The schema slug.
	 * @param array<string, mixed> $params   The query a remote registry would get (`_limit`, `_search`, `kind`, `slug`).
	 * @param int                  $limit    The most results when `_limit` is not given.
	 *
	 * @return array{outcome: string, results: array<int, mixed>}
	 *
	 * @spec openspec/specs/apphost-store-plane/spec.md
	 */
	public function read(string $register, string $schema, array $params, int $limit): array {
		$limit = (int)($params['_limit'] ?? $limit);
		unset($params['_limit']);

		try {
			// The concrete class: not every app's container aliases the interface.
			$objects = $this->container->get(ObjectService::class);
			if ($objects instanceof ObjectServiceInterface === false) {
				return ['outcome' => GenericStoreService::OUTCOME_UNREACHABLE, 'results' => []];
			}

			$found = $objects->searchObjectsBySlug(
				registerSlug: $register,
				schemaSlug: $schema,
				filters: $params,
				_rbac: true,
				_multitenancy: false
			);
		} catch (Throwable $e) {
			$this->logger->warning('AppHost store: local registry read failed: '.$e->getMessage());
			return ['outcome' => GenericStoreService::OUTCOME_UNREACHABLE, 'results' => []];
		}

		$results = [];
		foreach ($this->asArrays(found: $found) as $object) {
			if (isset($params['slug']) === true && (string) ($object['slug'] ?? '') !== (string) $params['slug']) {
				continue;
			}

			$results[] = $object;
		}

		return ['outcome' => GenericStoreService::OUTCOME_OK, 'results' => array_slice($results, 0, $limit)];
	}//end read()

	/**
	 * Write a published item into the register: the object that carries the
	 * same slug is updated, else one is created.
	 *
	 * The body is the filtered publish body (the slug plus the declared
	 * fields), so nothing the descriptor did not name is written. A failed
	 * write answers unreachable, never ok.
	 *
	 * @param string               $register The register slug.
	 * @param string               $schema   The schema slug.
	 * @param array<string, mixed> $body     The publish body; carries `slug`.
	 *
	 * @return array{outcome: string, slug: string}
	 *
	 * @spec openspec/specs/apphost-store-plane/spec.md
	 */
	public function write(string $register, string $schema, array $body): array {
		$slug = (string) ($body['slug'] ?? '');

		$existing = $this->read(register: $register, schema: $schema, params: ['slug' => $slug], limit: 1);
		if ($existing['outcome'] !== GenericStoreService::OUTCOME_OK) {
			return ['outcome' => $existing['outcome'], 'slug' => ''];
		}

		$uuid = $this->idOf(object: ($existing['results'][0] ?? []));

		try {
			$objects = $this->container->get(ObjectService::class);
			if ($objects instanceof ObjectServiceInterface === false) {
				return ['outcome' => GenericStoreService::OUTCOME_UNREACHABLE, 'slug' => ''];
			}

			$objects->saveObject($body, [], $register, $schema, $uuid);
		} catch (Throwable $e) {
			$this->logger->warning('AppHost store: local registry write failed: '.$e->getMessage());
			return ['outcome' => GenericStoreService::OUTCOME_UNREACHABLE, 'slug' => ''];
		}

		return ['outcome' => GenericStoreService::OUTCOME_OK, 'slug' => $slug];
	}//end write()

	/**
	 * The id of a stored object, or null.
	 *
	 * @param mixed $object The object as an array.
	 *
	 * @return string|null
	 */
	private function idOf(mixed $object): ?string {
		if (is_array($object) === false) {
			return null;
		}

		$id = ($object['id'] ?? ($object['@self']['id'] ?? null));
		if (is_string($id) === false || $id === '') {
			return null;
		}

		return $id;
	}//end idOf()

	/**
	 * The search answer as a list of arrays: entities are serialised, anything
	 * else is dropped.
	 *
	 * @param mixed $found What the object service returned.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function asArrays(mixed $found): array {
		if (is_array($found) === false) {
			return [];
		}

		$rows = [];
		foreach ($found as $object) {
			if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {
				$object = $object->jsonSerialize();
			}

			if (is_array($object) === true) {
				$rows[] = $object;
			}
		}

		return $rows;
	}//end asArrays()
}//end class
