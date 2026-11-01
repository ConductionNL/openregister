<?php

/**
 * TransformationHandler
 *
 * This file is part of the OpenRegister app for Nextcloud.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/openregister
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */

namespace OCA\OpenRegister\Service\Object\SaveObjects;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Object\SaveObject\RelationCascadeHandler;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Handles transformation of objects to database format for bulk save operations.
 *
 * This handler is responsible for:
 * - Transforming object data to database-ready format
 * - UUID generation and validation
 * - Metadata extraction and assignment
 * - Relations scanning
 * - Owner and organization assignment
 *
 * @category Service
 * @package  OCA\OpenRegister
 * @author   Conduction <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/openregister
 * @version  1.0.0
 */
class TransformationHandler {

	/**
	 * The @self fields removed from a flat row to leave its business data.
	 *
	 * @var string[]
	 */
	private const METADATA_FIELDS = [
		'@self',
		'name',
		'description',
		'summary',
		'image',
		'slug',
		'register',
		'schema',
		'organisation',
		'uuid',
		'owner',
		'created',
		'updated',
		'id',
	];

	/**
	 * Constructor for TransformationHandler.
	 *
	 * @param RelationCascadeHandler $relCascadeHandler Handler for relation operations.
	 * @param IUserSession $userSession User session for owner assignment.
	 * @param LoggerInterface $logger Logger for logging operations.
	 */
	public function __construct(
		private readonly RelationCascadeHandler $relCascadeHandler,
		// REMOVED: private readonly.
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Transform objects to database format in-place.
	 *
	 * This method transforms object data to the format required for database storage.
	 * It handles:
	 * - UUID generation and validation
	 * - Register and schema assignment
	 * - Owner and organization assignment
	 * - Relations scanning
	 * - Business data extraction
	 *
	 * @param array $objects Array of objects to transform (passed by reference).
	 * @param array $schemaCache Cache of schema objects for validation.
	 *
	 * @psalm-param   array<int, array<string, mixed>> &$objects
	 * @psalm-param   array<int|string, Schema> $schemaCache
	 * @phpstan-param array<int, array<string, mixed>> &$objects
	 * @phpstan-param array<int|string, Schema> $schemaCache
	 *
	 * @return array Array with 'valid' and 'invalid' keys containing transformed and invalid objects.
	 *
	 * @psalm-return   array{valid: list<array<string, mixed>>, invalid: list<array<string, mixed>>}
	 * @phpstan-return array{valid: list<array<string, mixed>>, invalid: list<array<string, mixed>>}
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function transformObjectsToDatabaseFormatInPlace(array &$objects, array $schemaCache): array {
		$transformedObjects = [];
		$invalidObjects = [];

		foreach ($objects as $index => &$object) {
			$selfData = $this->selfDataOf(object: $object);

			$invalid = $this->invalidReason(selfData: $selfData, schemaCache: $schemaCache);
			if ($invalid !== null) {
				$invalidObjects[] = [
					'object' => $object,
					'error' => $invalid['error'],
					'index' => $index,
					'type' => $invalid['type'],
				];
				continue;
			}

			$selfData = $this->withOwnerAndOrganisation(selfData: $selfData);

			$this->logger->info(
				message: '[TransformationHandler] DEBUG - Mixed schema object structure',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'available_keys' => array_keys($object),
					'has_object_property' => isset($object['object']) === true,
					'sample_data' => array_slice($object, 0, 3, true),
				]
			);

			$businessData = $this->businessDataOf(object: $object);
			$selfData['relations'] = $this->relationsOf(
				selfData: $selfData,
				scan: new RelationScan(businessData: $businessData, schema: $schemaCache[$selfData['schema']])
			);

			// Store the clean business data in the database object column.
			$selfData['object'] = $businessData;

			$transformedObjects[] = $selfData;
		}//end foreach

		// Return both transformed objects and any invalid objects found during transformation.
		return [
			'valid' => $transformedObjects,
			'invalid' => $invalidObjects,
		];
	}//end transformObjectsToDatabaseFormatInPlace()

	/**
	 * The @self metadata of one object, with its uuid, register and schema filled in.
	 *
	 * A flat row is its own @self data. A non-empty `id` (the row's, else @self's) becomes the
	 * uuid; otherwise a new uuid is generated. A register or schema missing from @self is taken
	 * from the row, an entity as its id.
	 *
	 * @param array<string, mixed> $object The incoming object.
	 *
	 * @return array<string, mixed>
	 */
	private function selfDataOf(array $object): array {
		$selfData = $object;
		if (($object['@self'] ?? null) !== null) {
			$selfData = $object['@self'];
		}

		$providedId = $object['id'] ?? $selfData['id'] ?? null;
		$selfData['uuid'] = Uuid::v4()->toRfc4122();
		if (($providedId !== null) === true && empty(trim($providedId)) === false) {
			$selfData['uuid'] = $providedId;
		}

		foreach (['register', 'schema'] as $field) {
			if (($selfData[$field] ?? null) === null && ($object[$field] ?? null) !== null) {
				$selfData[$field] = $object[$field];
				if (is_object($object[$field]) === true) {
					$selfData[$field] = $object[$field]->getId();
				}
			}
		}

		return $selfData;
	}//end selfDataOf()

	/**
	 * Why an object cannot be stored: no register, no schema, or a schema that is not loaded.
	 *
	 * @param array<string, mixed>      $selfData    The object's @self metadata.
	 * @param array<int|string, Schema> $schemaCache The loaded schemas by id.
	 *
	 * @return array{error: string, type: string}|null The reason, or null when the object is valid.
	 */
	private function invalidReason(array $selfData, array $schemaCache): ?array {
		if (($selfData['register'] ?? null) === null) {
			return [
				'error' => 'Register ID is required but not found in object data or method parameters',
				'type' => 'MissingRegisterException',
			];
		}

		if (($selfData['schema'] ?? null) === null) {
			return [
				'error' => 'Schema ID is required but not found in object data or method parameters',
				'type' => 'MissingSchemaException',
			];
		}

		if (isset($schemaCache[$selfData['schema']]) === false) {
			return [
				'error' => "Schema ID {$selfData['schema']} does not exist or could not be loaded",
				'type' => 'InvalidSchemaException',
			];
		}

		return null;
	}//end invalidReason()

	/**
	 * Default an empty owner to the current user (or null) and an empty organisation to null.
	 *
	 * @param array<string, mixed> $selfData The object's @self metadata.
	 *
	 * @return array<string, mixed>
	 */
	private function withOwnerAndOrganisation(array $selfData): array {
		if (($selfData['owner'] ?? null) === null || empty($selfData['owner']) === true) {
			$currentUser = $this->userSession->getUser();
			$selfData['owner'] = null;
			if ($currentUser !== null) {
				$selfData['owner'] = $currentUser->getUID();
			}
		}

		if (($selfData['organisation'] ?? null) === null || empty($selfData['organisation']) === true) {
			$selfData['organisation'] = null;
		}

		return $selfData;
	}//end withOwnerAndOrganisation()

	/**
	 * The business data of one object: its `object` array, or the row minus the metadata fields.
	 *
	 * @param array<string, mixed> $object The incoming object.
	 *
	 * @return array<string, mixed>
	 */
	private function businessDataOf(array $object): array {
		if (($object['object'] ?? null) !== null && is_array($object['object']) === true) {
			$this->logger->info(
				message: '[TransformationHandler] Using object property for business data (mixed)',
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
			return $object['object'];
		}

		$businessData = array_diff_key($object, array_flip(self::METADATA_FIELDS));

		$this->logger->info(
			message: '[TransformationHandler] Metadata removal applied (mixed)',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'removed_fields' => array_intersect(self::METADATA_FIELDS, array_keys($object)),
				'remaining_keys' => array_keys($businessData),
				'business_data_sample' => array_slice($businessData, 0, 3, true),
			]
		);

		return $businessData;
	}//end businessDataOf()

	/**
	 * The relations to store: those set during preparation, else a scan of the business data.
	 *
	 * @param array<string, mixed> $selfData The object's @self metadata.
	 * @param RelationScan         $scan     The business data and its schema.
	 *
	 * @return array<mixed>
	 */
	private function relationsOf(array $selfData, RelationScan $scan): array {
		if (($selfData['relations'] ?? null) !== null && empty($selfData['relations']) === false) {
			$this->logger->info(
				message: '[TransformationHandler] Relations already set from preparation',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'uuid' => $selfData['uuid'] ?? 'unknown',
					'relationCount' => count($selfData['relations']),
				]
			);
			return $selfData['relations'];
		}

		$relations = $this->relCascadeHandler->scanForRelations(
			data: $scan->businessData,
			prefix: '',
			schema: $scan->schema
		);

		$this->logger->info(
			message: '[TransformationHandler] Relations scanned in transformation',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'uuid' => $selfData['uuid'] ?? 'unknown',
				'relationCount' => count($relations),
				'relations' => array_slice($relations, 0, 3, true),
			]
		);

		return $relations;
	}//end relationsOf()
}//end class
