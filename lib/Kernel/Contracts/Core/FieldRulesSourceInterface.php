<?php
/**
 * FieldRulesSourceInterface: where property-level access reads the field rules of an object.
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
use OCA\OpenRegister\Service\Lifecycle\StateFieldRules;

/**
 * The hidden, read-only and required fields of one object, already decided for the caller.
 *
 * Property-level access (Core) asks; the lifecycle (Processes) answers from the
 * schema's state declaration and binds its resolver in its own registrar.
 */
interface FieldRulesSourceInterface {

	/**
	 * The field rules of an object in a state.
	 *
	 * @param Schema               $schema The schema the object belongs to.
	 * @param array<string, mixed> $data   The object's data.
	 * @param string|null          $state  The state to resolve for; defaults to the object's own.
	 *
	 * @return StateFieldRules The three lists, already decided.
	 *
	 * @spec openspec/specs/row-field-level-security/spec.md
	 */
	public function resolve(Schema $schema, array $data, ?string $state = null): StateFieldRules;
}//end interface
