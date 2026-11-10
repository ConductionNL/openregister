<?php

/**
 * ObjectStreamingSaveInterface: the object operations plus the streaming bulk save.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
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

namespace OCA\OpenRegister\Service;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Service\Object\BatchOperationStatus;

/**
 * What the bulk API asks of ObjectService: the operations on
 * ObjectOperationsInterface, which stays unchanged, plus the streaming save
 * that interface does not carry.
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
interface ObjectStreamingSaveInterface extends ObjectOperationsInterface {

	/**
	 * Save many objects one row at a time, keeping reference checks O(1).
	 *
	 * A row that throws is recorded on the returned status and the batch
	 * continues, rather than failing the call.
	 *
	 * @param array                    $objects  Rows in the shape saveObject() accepts.
	 * @param Register|string|int|null $register Optional register context.
	 * @param Schema|string|int|null   $schema   Optional schema context.
	 *
	 * @return BatchOperationStatus Per-row outcomes plus reference-cache counters.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function saveObjectsStreaming(
		array $objects,
		Register | string | int | null $register=null,
		Schema | string | int | null $schema=null
	): BatchOperationStatus;
}//end interface
