<?php

/**
 * OpenRegister SearchResultShaper
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/zoeken-filteren/spec.md
 */

namespace OCA\OpenRegister\Service\Object;

use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCP\AppFramework\IAppContainer;

/**
 * Turns the mapper result of QueryHandler::searchObjects() into its answer: a count, the plain objects,
 * or the objects rendered for `_extend`, `_fields`, `_filter`, `_unset` and property authorization.
 *
 * @spec openspec/specs/zoeken-filteren/spec.md
 */
final class SearchResultShaper {

	/**
	 * Constructor.
	 *
	 * @param RenderObject  $renderHandler The renderer that applies extends, field selection and property authorization.
	 * @param IAppContainer $container     The container the schema mapper is taken from.
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md
	 */
	public function __construct(
		private readonly RenderObject $renderHandler,
		private readonly IAppContainer $container,
	) {
	}//end __construct()

	/**
	 * Answer a `_count` query with the count, otherwise render the objects when the query or a schema asks for it.
	 *
	 * @param array|int           $result  The mapper's search result.
	 * @param SearchRenderRequest $request The query and flags of the search.
	 *
	 * @return array|int The count, the objects, or the rendered objects.
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md
	 */
	public function shape(array|int $result, SearchRenderRequest $request): array|int {
		$query = $request->query;
		if (($query['_count'] ?? false) === true || ($query['_count'] ?? false) === 'true') {
			return count($result);
		}

		if (self::requestsRendering(query: $query) === false && $this->anySchemaHasPropertyAuthorization(result: $result) === false) {
			return $result;
		}

		return $this->rendered(result: $result, request: $request);
	}//end shape()

	/**
	 * Apply complex rendering (extend, fields, filter, unset) under the request's RBAC and multitenancy.
	 *
	 * @param array|int           $result  The search result.
	 * @param SearchRenderRequest $request The query and the RBAC and multitenancy flags.
	 *
	 * @return array
	 */
	private function rendered(array|int $result, SearchRenderRequest $request): array {
		$query = $request->query;

		return $this->renderHandler->renderEntities(
			entities: $result,
			_extend: $query['_extend'] ?? [],
			_filter: $query['_filter'] ?? null,
			_fields: $query['_fields'] ?? null,
			_unset: $query['_unset'] ?? null,
			_rbac: $request->rbac,
			_multitenancy: $request->multitenancy
		);
	}//end rendered()

	/**
	 * Whether the query asks for `_extend`, `_fields`, `_filter` or `_unset`.
	 *
	 * @param array $query The search query.
	 *
	 * @return bool True when the objects must be rendered.
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md
	 */
	private static function requestsRendering(array $query): bool {
		return empty($query['_extend'] ?? []) === false
			|| empty($query['_fields'] ?? null) === false
			|| empty($query['_filter'] ?? null) === false
			|| empty($query['_unset'] ?? null) === false;
	}//end requestsRendering()

	/**
	 * Whether the schema of any found object has property-level authorization, so the render must filter properties.
	 *
	 * Each schema is looked up once; the probe stops at the first schema that has it.
	 *
	 * @param array|int $result The mapper's search result.
	 *
	 * @return bool True when the objects must be rendered.
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md
	 */
	private function anySchemaHasPropertyAuthorization(array|int $result): bool {
		if (is_array($result) === false || empty($result) === true) {
			return false;
		}

		$schemaMapper = $this->container->get(SchemaMapperInterface::class);
		foreach (self::schemaIdsOf(result: $result) as $schemaId) {
			if (self::schemaHasPropertyAuthorization(schemaMapper: $schemaMapper, schemaId: $schemaId) === true) {
				return true;
			}
		}

		return false;
	}//end anySchemaHasPropertyAuthorization()

	/**
	 * The schema ids of a result's rows, each once, in the order they first appear.
	 *
	 * @param array $result The search result rows.
	 *
	 * @return array<int, mixed>
	 */
	private static function schemaIdsOf(array $result): array {
		$schemaIds = [];
		foreach ($result as $object) {
			$schemaId = $object->getSchema();
			$key = ($schemaId ?? '');
			if (array_key_exists($key, $schemaIds) === false) {
				$schemaIds[$key] = $schemaId;
			}
		}

		return array_values($schemaIds);
	}//end schemaIdsOf()

	/**
	 * Whether one schema has property-level authorization; a schema that cannot be loaded has none.
	 *
	 * @param SchemaMapperInterface $schemaMapper The schema mapper.
	 * @param mixed                             $schemaId     The object's schema id.
	 *
	 * @return bool True when a property of the schema carries authorization.
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md
	 */
	private static function schemaHasPropertyAuthorization(SchemaMapperInterface $schemaMapper, mixed $schemaId): bool {
		try {
			return $schemaMapper->find($schemaId)->hasPropertyAuthorization();
		} catch (\Exception $e) {
			return false;
		}
	}//end schemaHasPropertyAuthorization()
}//end class
