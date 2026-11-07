<?php

/**
 * OpenRegister ObjectFileProperties
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object\SaveObject
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
 */

namespace OCA\OpenRegister\Service\Object\SaveObject;

use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use Psr\Log\LoggerInterface;

/**
 * Stores the file properties of a saved object and writes their file ids back onto it.
 *
 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
 */
final class ObjectFileProperties {

	/**
	 * @param FilePropertyHandler  $files   Recognises and stores file properties.
	 * @param MagicMapperInterface $objects Updates the object, or deletes it on a rollback.
	 * @param LoggerInterface      $logger  Records what happened.
	 */
	public function __construct(
		private readonly FilePropertyHandler $files,
		private readonly MagicMapperInterface $objects,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Store the file properties of a new object. A failure deletes the object and is thrown on.
	 *
	 * @param ObjectFileWrite $write The file write; its data gets the file ids.
	 *
	 * @return ObjectEntity The object, updated when a file property was processed.
	 *
	 * @throws \Exception When a file cannot be stored.
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	public function storeForCreate(ObjectFileWrite $write): ObjectEntity {
		$context = ['app' => 'openregister', 'uuid' => $write->entity->getUuid(), 'dataKeys' => array_keys($write->data)];
		$this->logger->debug(message: '[SaveObject] processFilePropertiesWithRollback called', context: $context);

		try {
			if ($this->storeProperties(write: $write) !== []) {
				$this->writeFileIds(write: $write);
			}

			return $write->entity;
		} catch (\Exception $e) {
			$this->rollBack(write: $write, error: $e);
			throw $e;
		}
	}//end storeForCreate()

	/**
	 * Delete an object whose files could not be stored.
	 *
	 * @param ObjectFileWrite $write The file write.
	 * @param \Exception      $error What failed.
	 *
	 * @return void
	 */
	private function rollBack(ObjectFileWrite $write, \Exception $error): void {
		$this->logger->warning(
			message: '[SaveObject] File processing failed, rolling back object creation',
			context: ['uuid' => $write->entity->getUuid(), 'error' => $error->getMessage()]
		);
		$this->objects->delete($write->entity);
	}//end rollBack()

	/**
	 * Store the file properties of an update and write their file ids onto the persisted object.
	 *
	 * BUG-OBJ-8: only the properties the file handler rewrote are overlaid onto the
	 * MERGED object that was just persisted (computed fields, defaults, cascaded
	 * sub-objects, stored fields absent from this request). Overlaying the raw partial
	 * data would drop every field absent from the request.
	 *
	 * @param ObjectUpdateRun $run     The update call; its data gets the file ids.
	 * @param ObjectEntity    $updated The updated entity.
	 *
	 * @return ObjectEntity The entity, saved again when a file property was processed.
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	public function storeForUpdate(ObjectUpdateRun $run, ObjectEntity $updated): ObjectEntity {
		$write = new ObjectFileWrite();
		$write->entity = $updated;
		$write->data = $run->data;
		$write->schema = $run->schema;
		$properties = $this->storeProperties(write: $write);
		$run->data = $write->data;
		if ($properties === []) {
			return $updated;
		}

		$updated->setObject(array_replace($updated->getObject() ?? [], array_intersect_key($run->data, array_flip($properties))));
		$this->clearImage(object: $updated, schema: $run->schema);

		return $this->objects->update(entity: $updated, register: $run->register, schema: $run->schema);
	}//end storeForUpdate()

	/**
	 * Store every file property of the data, replacing its content with file ids.
	 *
	 * @param ObjectFileWrite $write The file write; its data is rewritten.
	 *
	 * @return array The names of the properties stored.
	 */
	private function storeProperties(ObjectFileWrite $write): array {
		$stored = [];
		$snapshot = $write->data;
		foreach ($snapshot as $name => $value) {
			if ($this->files->isFileProperty(value: $value, schema: $write->schema, propertyName: $name) === true) {
				$this->files->handleFileProperty(objectEntity: $write->entity, object: $write->data, propertyName: $name, schema: $write->schema);
				$stored[] = $name;
			}
		}

		return $stored;
	}//end storeProperties()

	/**
	 * Write the data with file ids back onto the saved object and update it.
	 *
	 * @param ObjectFileWrite $write The file write.
	 *
	 * @return void
	 */
	private function writeFileIds(ObjectFileWrite $write): void {
		$this->logger->debug(
			message: '[SaveObject] File properties processed, updating object with file IDs',
			context: ['app' => 'openregister', 'uuid' => $write->entity->getUuid()]
		);
		$write->entity->setObject($write->data);
		$this->clearImage(object: $write->entity, schema: $write->schema);
		$write->entity = $this->objects->update(entity: $write->entity, register: $write->register, schema: $write->schema);
	}//end writeFileIds()

	/**
	 * Clear the image metadata when objectImageField points to a file property.
	 *
	 * The image is then extracted from the file object during rendering.
	 *
	 * @param ObjectEntity $object The saved object.
	 * @param Schema       $schema The schema.
	 *
	 * @return void
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	public function clearImage(ObjectEntity $object, Schema $schema): void {
		$field = ($schema->getConfiguration()['objectImageField'] ?? null);
		if ($field === null) {
			return;
		}

		if ((($schema->getProperties() ?? [])[$field]['type'] ?? '') === 'file') {
			$object->setImage(null);
		}
	}//end clearImage()
}//end class
