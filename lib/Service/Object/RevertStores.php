<?php

/**
 * OpenRegister RevertStores
 *
 * The stores a revert reads and writes: the audit trail it reverts from, the
 * object mapper it finds the object with, the validator the save path uses and
 * the save path's update it writes through.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 */

namespace OCA\OpenRegister\Service\Object;

use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Db\MagicMapperInterface;

/**
 * The collaborators of RevertHandler that hold the object and its history, wired by DI autowiring.
 */
final class RevertStores {
	/**
	 * Bundle the services.
	 *
	 * @param AuditTrailMapperInterface  $auditTrailMapper   Audit trail mapper.
	 * @param MagicMapperInterface       $objectEntityMapper Object entity mapper.
	 * @param ObjectValidatorInterface   $validateHandler    Schema validator of the save path.
	 * @param SaveObjectUpdaterInterface $saveObject         The save path; a revert is written as its update.
	 *
	 * @spec openspec/changes/history-revert-through-the-save-path/specs/content-versioning/spec.md
	 */
	public function __construct(
		public readonly AuditTrailMapperInterface $auditTrailMapper,
		public readonly MagicMapperInterface $objectEntityMapper,
		public readonly ObjectValidatorInterface $validateHandler,
		public readonly SaveObjectUpdaterInterface $saveObject,
	) {
	}//end __construct()
}//end class
