<?php

/**
 * OpenRegister StreamRun
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
 * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
 */

namespace OCA\OpenRegister\Service\Object\SaveObject;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Object\BatchOperationStatus;

/**
 * One SaveObject::saveObjectsStreaming() batch, and the row it is on.
 *
 * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
 */
final class StreamRun {

	/**
	 * The register context of the batch.
	 *
	 * @var Register|integer|string|null
	 */
	public Register|int|string|null $register = null;

	/**
	 * The schema context of the batch.
	 *
	 * @var Schema|integer|string|null
	 */
	public Schema|int|string|null $schema = null;

	/**
	 * The status the batch reports into.
	 *
	 * @var BatchOperationStatus|null
	 */
	public ?BatchOperationStatus $status = null;

	/**
	 * The position of the current row in the submitted batch.
	 *
	 * @var integer
	 */
	public int $rowIndex = -1;

	/**
	 * The current input row.
	 *
	 * @var mixed
	 */
	public mixed $row = null;
}//end class
