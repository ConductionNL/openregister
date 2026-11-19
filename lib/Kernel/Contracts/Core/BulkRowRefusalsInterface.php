<?php
/**
 * BulkRowRefusalsInterface: the rows of a bulk save a declared rule refuses.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Contract
 * @package  OCA\OpenRegister\Kernel\Contracts\Core
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts\Core;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;

/**
 * The rows of a bulk save that the single save would have refused, by their position in the batch.
 *
 * The bulk save path (Core) asks before it stores the batch; the decision-table rules
 * (Processes) answer and bind their implementation in their own registrar.
 *
 * @spec openspec/changes/stored-decision-tables/specs/flow-decision-tables/spec.md
 */
interface BulkRowRefusalsInterface {

	/**
	 * The rows that must not be stored, by their position in the batch.
	 *
	 * @param array<int|string, array<string, mixed>> $objects  The raw rows.
	 * @param Register|string|int|null                $register The batch's default register.
	 * @param Schema|string|int|null                  $schema   The batch's default schema.
	 *
	 * @return array<int|string, string> The refusal reason per refused row.
	 *
	 * @spec openspec/changes/stored-decision-tables/specs/flow-decision-tables/spec.md
	 */
	public function refusals(array $objects, Register|string|int|null $register, Schema|string|int|null $schema): array;
}//end interface
