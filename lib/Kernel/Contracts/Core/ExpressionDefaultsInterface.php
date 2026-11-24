<?php
/**
 * ExpressionDefaultsInterface: the derived defaults an object gets on create.
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

use OCA\OpenRegister\Db\Schema;

/**
 * Writes the expression defaults a schema declares onto an object being created.
 *
 * The object write path (Core) asks before validation, so a property that is both
 * required and derived can be created; the rules engine (Processes) evaluates the
 * expressions and binds its implementation in its own registrar.
 */
interface ExpressionDefaultsInterface {

	/**
	 * The object with every declared expression default the caller left empty derived onto it.
	 *
	 * @param Schema               $schema The schema being written against.
	 * @param array<string, mixed> $data   The object being created.
	 *
	 * @return array<string, mixed> The object with the derived defaults written onto it.
	 *
	 * @throws \RuntimeException When an expression cannot be evaluated; the create is refused (HTTP 422).
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function apply(Schema $schema, array $data): array;
}//end interface
