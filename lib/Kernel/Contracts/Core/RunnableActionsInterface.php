<?php
/**
 * RunnableActionsInterface: which declared actions the reader may run on an object, as a render tells it.
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

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;

/**
 * The declared-action part of the `@self.can` marker a render puts on an object.
 *
 * The render (Core) asks; the object action list, which knows the declared actions
 * and their run gate, answers and is bound to this contract in its own area's wiring.
 *
 * @spec openspec/specs/declared-actions/spec.md
 */
interface RunnableActionsInterface {

	/**
	 * Which declared actions the caller may run on this object now, by key.
	 *
	 * @param ObjectEntity $object The object.
	 * @param Schema       $schema Its schema.
	 *
	 * @return array<string, bool> Action key => whether the caller may run it.
	 *
	 * @spec openspec/specs/declared-actions/spec.md
	 */
	public function canRun(ObjectEntity $object, Schema $schema): array;
}//end interface
