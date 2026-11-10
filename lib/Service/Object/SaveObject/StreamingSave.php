<?php

/**
 * One streaming batch save: where the rows go, the rows, and the status to accumulate on.
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
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
 */

namespace OCA\OpenRegister\Service\Object\SaveObject;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Object\BatchOperationStatus;

/**
 * The register and schema context of a streamed batch, its rows and an optional running status.
 *
 * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
 */
final class StreamingSave {
	/**
	 * Constructor.
	 *
	 * @param Register|int|string|null  $register Register context for the batch.
	 * @param Schema|int|string|null    $schema   Schema context for the batch.
	 * @param iterable                  $rows     Input rows, each the shape `saveObject` accepts.
	 * @param BatchOperationStatus|null $status   A pre-existing status to accumulate on across calls.
	 *
	 * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
	 */
	public function __construct(
		public readonly Register|int|string|null $register,
		public readonly Schema|int|string|null $schema,
		public readonly iterable $rows,
		public readonly ?BatchOperationStatus $status = null,
	) {
	}//end __construct()
}//end class
