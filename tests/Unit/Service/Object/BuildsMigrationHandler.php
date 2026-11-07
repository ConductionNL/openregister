<?php

/**
 * Builds a MigrationHandler over a real MoveObject, so a migration runs the move as shipped.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Service\Object\DataManipulationHandler;
use OCA\OpenRegister\Service\Object\MigrationHandler;
use OCA\OpenRegister\Service\Object\MoveObject;
use OCA\OpenRegister\Service\Object\SaveObject;
use OCA\OpenRegister\Service\Object\UtilityHandler;
use OCA\OpenRegister\Service\Object\ValidateObject;
use Opis\JsonSchema\ValidationResult;
use Psr\Log\LoggerInterface;

/**
 * Doubles for the collaborators a migration test does not look at.
 */
trait BuildsMigrationHandler {

	/**
	 * A handler over the given doubles; the target accepts every object unless a validator is given.
	 *
	 * @param MagicMapper             $mapper    The object store.
	 * @param UtilityHandler          $utility   Loads registers and schemas.
	 * @param DataManipulationHandler $dataManip Maps the properties.
	 * @param LoggerInterface|null    $logger    The logger.
	 *
	 * @return MigrationHandler
	 */
	private function migrationHandler(
		MagicMapper $mapper,
		UtilityHandler $utility,
		DataManipulationHandler $dataManip,
		?LoggerInterface $logger = null,
	): MigrationHandler {
		$logger ??= $this->createMock(LoggerInterface::class);
		$validator = $this->createMock(ValidateObject::class);
		$validator->method('validateObject')->willReturn(new ValidationResult(null));
		$audit = $this->createMock(AuditTrailMapper::class);

		return new MigrationHandler(
			objectMapper: $mapper,
			utilityHandler: $utility,
			dataManipHandler: $dataManip,
			mover: new MoveObject(objects: $mapper, validator: $validator, audit: $audit, logger: $logger),
			relationScanner: $this->createMock(SaveObject::class),
			auditTrail: $audit,
			logger: $logger
		);
	}//end migrationHandler()
}//end trait
