<?php

/**
 * Object Vectorization Strategy
 *
 * Strategy for vectorizing OpenRegister objects.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Vectorization
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

namespace OCA\OpenRegister\Service\Vectorization\Strategies;

use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Service\SettingsServiceInterface;
use Psr\Log\LoggerInterface;

/**
 * ObjectVectorizationStrategy
 *
 * Handles object-specific vectorization logic.
 *
 * OPTIONS:
 * - views: array|null - View IDs to filter (null = all views)
 * - batch_size: int - Number of objects per batch
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Vectorization
 */
class ObjectVectorizationStrategy implements ObjectVectorizationStrategyInterface {

	/**
	 * Constructor
	 *
	 * @param ObjectOperationsInterface $objectService Object service
	 * @param SettingsServiceInterface $settingsService Settings service
	 * @param LoggerInterface $logger Logger
	 */
	public function __construct(
		private readonly ObjectOperationsInterface $objectService,
		private readonly SettingsServiceInterface $settingsService,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Fetch objects to vectorize based on views
	 *
	 * @param array $options Options: views, batch_size
	 *
	 * @return \OCA\OpenRegister\Db\ObjectEntity[]
	 *
	 * @psalm-return list<\OCA\OpenRegister\Db\ObjectEntity>
	 *
	 * @spec openspec/specs/vector-embeddings/spec.md
	 */
	public function fetchEntities(array $options): array {
		$views = $options['views'] ?? null;
		$limit = $options['batch_size'] ?? 25;

		$this->logFetching(views: $views, limit: $limit);

		$objects = $this->asObjectList(result: $this->searchViewObjects(views: $views, limit: $limit));

		$this->logFetchedCount(count: count($objects));

		return $objects;
	}//end fetchEntities()

	/**
	 * Search the database for one batch of objects, scoped to the given views.
	 *
	 * @param mixed $views The view IDs to apply, null for all objects
	 * @param mixed $limit The batch size
	 *
	 * @return mixed The search result: a list of objects, or a count.
	 */
	private function searchViewObjects(mixed $views, mixed $limit): mixed {
		// Get objects using ObjectService with view support.
		return $this->objectService->searchObjects(
			query: [
				'_limit' => $limit,
				'_source' => 'database',
			],
			_rbac: false,
			_multitenancy: false,
			ids: null,
			uses: null,
			views: $views,
			// The job has no session, so ViewMapper::find() denied, the view was
			// logged and skipped, and the vectoriser indexed whatever the
			// unbounded query returned instead of the view it was told to index.
			// With the flag the view resolves exempt - a background job carries its
			// own authorization exactly as a publication link does - and a view
			// that cannot be applied fails the run rather than silently widening it.
			_viewScopeRequired: true
		);
	}//end searchViewObjects()

	/**
	 * The search result as a list of objects.
	 *
	 * @param mixed $result The search result
	 *
	 * @return array The objects, empty when the search returned a count.
	 */
	private function asObjectList(mixed $result): array {
		// SearchObjects can return array|int, but we need array for vectorization (@var array $objects).
		if (is_array($result) === true) {
			return $result;
		}

		return [];
	}//end asObjectList()

	/**
	 * Log the start of a fetch.
	 *
	 * @param mixed $views The view IDs to apply
	 * @param mixed $limit The batch size
	 *
	 * @return void
	 */
	private function logFetching(mixed $views, mixed $limit): void {
		$this->logger->debug(
			message: '[ObjectVectorizationStrategy] Fetching objects',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'views' => $views,
				'limit' => $limit,
			]
		);
	}//end logFetching()

	/**
	 * Log how many objects a fetch returned.
	 *
	 * @param int $count The number of objects fetched
	 *
	 * @return void
	 */
	private function logFetchedCount(int $count): void {
		$this->logger->debug(
			message: '[ObjectVectorizationStrategy] Fetched objects',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'count' => $count,
			]
		);
	}//end logFetchedCount()

	/**
	 * Extract text from object by serializing it
	 *
	 * @param mixed $entity ObjectEntity
	 *
	 * @return (int|string)[][] Array with single item containing serialized object
	 *
	 * @psalm-return list{array{text: string, index: 0}}
	 *
	 * @spec openspec/specs/vector-embeddings/spec.md
	 */
	public function extractVectorizationItems($entity): array {
		// Get object data.
		$objectData = $this->objectDataOf(entity: $entity);

		// Get vectorization config.
		$config = $this->settingsService->getObjectSettingsOnly();

		// Serialize object to text.
		$text = $this->serializeObject(object: $objectData, config: $config);

		// Objects produce a single vectorization item.
		return [
			[
				'text' => $text,
				'index' => 0,
			],
		];
	}//end extractVectorizationItems()

	/**
	 * Prepare metadata for object vector
	 *
	 * @param mixed $entity ObjectEntity
	 * @param array $item Vectorization item
	 *
	 * @return ((mixed|null|string)[]|int|string)[] Metadata for storage
	 *
	 * @psalm-return array{
	 *     entity_type: 'object',
	 *     entity_id: string,
	 *     chunk_index: 0,
	 *     total_chunks: 1,
	 *     chunk_text: string,
	 *     additional_metadata: array{
	 *         object_id: 'unknown'|mixed,
	 *         object_title: mixed|string,
	 *         title: mixed|string,
	 *         name: mixed|string,
	 *         description: ''|mixed,
	 *         register: mixed|null,
	 *         register_id: mixed|null,
	 *         schema: mixed|null,
	 *         schema_id: mixed|null,
	 *         uuid: mixed|null,
	 *         uri: mixed|null
	 *     }
	 * }
	 *
	 * @spec openspec/specs/vector-embeddings/spec.md
	 */
	public function prepareVectorMetadata($entity, array $item): array {
		$objectData = $this->objectDataOf(entity: $entity);
		$objectId = $this->objectIdOf(objectData: $objectData);

		$this->logMetadataInput(objectData: $objectData, objectId: $objectId);

		return [
			'entity_type' => 'object',
			'entity_id' => (string)$objectId,
			'chunk_index' => 0,
			'total_chunks' => 1,
			'chunk_text' => substr($item['text'], 0, 500),
			'additional_metadata' => $this->describingMetadata(objectData: $objectData, objectId: $objectId)
				+ $this->placementMetadata(objectData: $objectData),
		];
	}//end prepareVectorMetadata()

	/**
	 * The object data of an entity: its serialization, or the array itself.
	 *
	 * @param mixed $entity ObjectEntity or object data array
	 *
	 * @return mixed The object data.
	 */
	private function objectDataOf($entity): mixed {
		$objectData = $entity->jsonSerialize();
		if (is_array($entity) === true) {
			$objectData = $entity;
		}

		return $objectData;
	}//end objectDataOf()

	/**
	 * The object's id, or 'unknown' when it has none.
	 *
	 * @param mixed $objectData Object data
	 *
	 * @return mixed The object id.
	 */
	private function objectIdOf(mixed $objectData): mixed {
		if (($objectData['id'] ?? null) !== null) {
			return $objectData['id'];
		}

		return 'unknown';
	}//end objectIdOf()

	/**
	 * Log the object data metadata is being prepared from.
	 *
	 * @param array $objectData Object data
	 * @param mixed $objectId   The object id
	 *
	 * @return void
	 */
	private function logMetadataInput(array $objectData, mixed $objectId): void {
		$this->logger->debug(
			message: '[ObjectVectorizationStrategy] Preparing metadata',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'object_id' => $objectId,
				'has_@self' => isset($objectData['@self']) === true,
				'@self_keys' => $this->extractSelfKeys(objectData: $objectData),
				'register_direct' => $objectData['_register'] ?? $objectData['register'] ?? 'none',
				'register_@self' => $objectData['@self']['register'] ?? 'none',
			]
		);
	}//end logMetadataInput()

	/**
	 * The metadata that describes the object: id, title and description.
	 *
	 * @param array $objectData Object data
	 * @param mixed $objectId   The object id
	 *
	 * @return array The describing metadata.
	 */
	private function describingMetadata(array $objectData, mixed $objectId): array {
		$title = $this->titleOf(objectData: $objectData, objectId: $objectId);

		return [
			'object_id' => $objectId,
			'object_title' => $title,
			'title' => $title,
			'name' => $title,
			'description' => $this->descriptionOf(objectData: $objectData),
		];
	}//end describingMetadata()

	/**
	 * The object's title: a title field, else its first short string, else its id.
	 *
	 * @param array $objectData Object data
	 * @param mixed $objectId   The object id
	 *
	 * @return mixed The title.
	 */
	private function titleOf(array $objectData, mixed $objectId): mixed {
		// Extract title/name - check multiple possible fields.
		$title = $objectData['title'] ?? $objectData['name'] ?? $objectData['_name'] ?? $objectData['summary'];
		if ($title === null) {
			$title = $this->extractFirstStringField(objectData: $objectData);
		}

		if ($title === null) {
			$title = 'Object #' . $objectId;
		}

		return $title;
	}//end titleOf()

	/**
	 * The object's description, checking the common variants.
	 *
	 * @param array $objectData Object data
	 *
	 * @return mixed The description, '' when there is none.
	 */
	private function descriptionOf(array $objectData): mixed {
		$description = $objectData['description'] ?? $objectData['_description'] ?? $objectData['Beschrijving'];
		if ($description === null) {
			$description = $objectData['beschrijving'] ?? $objectData['summary'] ?? $objectData['_summary'] ?? '';
		}

		return $description;
	}//end descriptionOf()

	/**
	 * Where the object lives: register, schema, uuid and uri, from the object or its @self block.
	 *
	 * @param array $objectData Object data
	 *
	 * @return array The placement metadata.
	 */
	private function placementMetadata(array $objectData): array {
		$selfData = $objectData['@self'] ?? [];
		$registerId = $objectData['_register'] ?? $objectData['register'] ?? $selfData['register'] ?? null;
		$schemaId = $objectData['_schema'] ?? $objectData['schema'] ?? $selfData['schema'] ?? null;

		return [
			'register' => $registerId,
			'register_id' => $registerId,
			'schema' => $schemaId,
			'schema_id' => $schemaId,
			'uuid' => $objectData['uuid'] ?? $objectData['_uuid'] ?? $selfData['id'] ?? null,
			'uri' => $objectData['uri'] ?? $objectData['_uri'] ?? $selfData['uri'] ?? null,
		];
	}//end placementMetadata()

	/**
	 * Extract @self keys from object data
	 *
	 * @param array $objectData Object data
	 *
	 * @return array<string> Array of @self keys
	 *
	 * @spec openspec/specs/vector-embeddings/spec.md
	 */
	private function extractSelfKeys(array $objectData): array {
		if (isset($objectData['@self']) === false || is_array($objectData['@self']) === false) {
			return [];
		}

		return array_keys($objectData['@self']);
	}//end extractSelfKeys()

	/**
	 * Extract the first suitable string field from object data
	 *
	 * This is a fallback when standard title/name fields don't exist.
	 * Looks for short, meaningful string values that could serve as identifiers.
	 *
	 * @param array $objectData Object data
	 *
	 * @return string|null First suitable string field value
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) Multiple field type checks required
	 * @SuppressWarnings(PHPMD.NPathComplexity)      Multiple field validation paths
	 *
	 * @spec openspec/specs/vector-embeddings/spec.md
	 */
	private function extractFirstStringField(array $objectData): ?string {
		// Skip metadata fields (prefixed with _ or @).
		// Look for short, meaningful strings (< 100 chars).
		foreach ($objectData as $key => $value) {
			// Skip metadata and system fields.
			if (str_starts_with($key, '_') === true || str_starts_with($key, '@') === true) {
				continue;
			}

			// Skip known non-title fields.
			$skipFields = ['id', 'uuid', 'description', 'Beschrijving', 'beschrijving', 'content', 'text'];
			if (in_array(strtolower($key), array_map('strtolower', $skipFields), true) === true) {
				continue;
			}

			// Check if it's a short string (likely a title/identifier).
			if (is_string($value) === true && strlen($value) > 0 && strlen($value) < 100) {
				return $value;
			}
		}

		return null;
	}//end extractFirstStringField()

	/**
	 * Get object ID as identifier
	 *
	 * @param mixed $entity ObjectEntity
	 *
	 * @return string|int Object ID
	 *
	 * @spec openspec/specs/vector-embeddings/spec.md
	 */
	public function getEntityIdentifier($entity) {
		return $this->objectIdOf(objectData: $this->objectDataOf(entity: $entity));
	}//end getEntityIdentifier()

	/**
	 * Serialize object to text for vectorization
	 *
	 * @param array $object Object data
	 * @param array $config Vectorization configuration
	 *
	 * @return false|string Serialized text
	 *
	 * @spec openspec/specs/vector-embeddings/spec.md
	 */
	private function serializeObject(array $object, array $config): string|false {
		// TODO: Implement configurable serialization.
		// For now, just JSON encode with pretty print for readability.
		$this->logSerializing(object: $object, config: $config);

		// Simple JSON serialization.
		// Future enhancement: smart serialization based on schema.
		return json_encode($object, JSON_PRETTY_PRINT);
	}//end serializeObject()

	/**
	 * Log the object being serialized and the serialization options in effect.
	 *
	 * @param array $object Object data
	 * @param array $config Vectorization configuration
	 *
	 * @return void
	 */
	private function logSerializing(array $object, array $config): void {
		$this->logger->debug(
			message: '[ObjectVectorizationStrategy] Serializing object',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'objectId' => $object['id'] ?? 'unknown',
				'includeMetadata' => $config['includeMetadata'] ?? true,
				'includeRelations' => $config['includeRelations'] ?? true,
				'maxNestingDepth' => $config['maxNestingDepth'] ?? 10,
			]
		);
	}//end logSerializing()
}//end class
