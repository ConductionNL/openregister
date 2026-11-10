<?php

/**
 * FileWriteGuardInterface: refusing a file write on a frozen or archived object.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Exception\ObjectStateWriteException;

/**
 * What the files controllers and the frozen-node listener need of FileWriteGuard.
 * Bound to FileWriteGuard in ContractBindings, so it resolves to the same shared instance.
 */
interface FileWriteGuardInterface {

	/**
	 * Refuse when the object is archived or frozen.
	 *
	 * @param ObjectEntity $object The object that owns the files.
	 *
	 * @return void
	 *
	 * @throws ObjectStateWriteException When the object is archived or frozen.
	 */
	public function assertWritable(ObjectEntity $object): void;

	/**
	 * The refusal for this object, or null when its files may change.
	 *
	 * @param ObjectEntity $object The object that owns the files.
	 *
	 * @return ObjectStateWriteException|null The refusal naming the state, or null.
	 */
	public function refusalFor(ObjectEntity $object): ?ObjectStateWriteException;
}//end interface
