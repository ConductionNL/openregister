<?php
/**
 * ExpressionDefaultRefusalInterface: a create refused because a declared default could not be derived.
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

use Throwable;

/**
 * What the object API (Core) answers with 422 when ExpressionDefaultsInterface refuses.
 *
 * The rules engine (Processes) throws it; the object API reads only the
 * message and the field error below.
 *
 * @spec openspec/specs/objects-crud/spec.md
 */
interface ExpressionDefaultRefusalInterface extends Throwable {

	/**
	 * The field error as the API publishes it: code, property and message.
	 *
	 * @return array<string, mixed> The error: `code`, `property` and `message`.
	 *
	 * @spec openspec/specs/objects-crud/spec.md
	 */
	public function toArray(): array;
}//end interface
