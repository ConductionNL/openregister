<?php

/**
 * SourcedObjectBinding: the register and schema a sourced object is projected into.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ObjectSource
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/object-source-providers/tasks.md#task-5.1
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ObjectSource;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;

/**
 * The register + schema pair a provider projects its rows into.
 *
 * @spec openspec/changes/object-source-providers/tasks.md#task-5.1
 */
final class SourcedObjectBinding {

	/**
	 * Constructor.
	 *
	 * @param Register $register The register.
	 * @param Schema   $schema   The sourced schema.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly Register $register,
		public readonly Schema $schema,
	) {
	}//end __construct()

	/**
	 * A non-persisted virtual ObjectEntity in this register and schema.
	 *
	 * @param string               $uuid The object uuid.
	 * @param array<string, mixed> $data The object data.
	 *
	 * @return ObjectEntity The virtual object (never saved).
	 *
	 * @spec openspec/changes/object-source-providers/tasks.md#task-5.1
	 */
	public function toEntity(string $uuid, array $data): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setRegister((string)$this->register->getId());
		$entity->setSchema((string)$this->schema->getId());
		$entity->setObject($data);

		return $entity;
	}//end toEntity()
}//end class
