<?php
/**
 * SaveBoundaryInterface: the write boundary an object save opens and closes, as the object save asks it.
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

/**
 * The write boundary around an object save, as the object save (Core) asks it.
 *
 * The save opens a boundary before it writes and closes it in a `finally`;
 * closing the outermost one lets the work that waits for a finished write run,
 * and the save answers with the entity that work left behind. The automatic
 * lifecycle moves (Processes) answer, through AutoTransitionPass.
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
interface SaveBoundaryInterface {

	/**
	 * Open a write boundary.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function enter(): void;

	/**
	 * Close a write boundary; the entities written once the outermost one closed, by object uuid.
	 *
	 * @return array<string, ObjectEntity> The last entity applied per object uuid, empty when nothing was.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function leave(): array;

	/**
	 * The entity a save answers with: the one applied after it, when there is one.
	 *
	 * @param ObjectEntity                $saved   The entity the write itself produced.
	 * @param array<string, ObjectEntity> $applied What leave() returned.
	 *
	 * @return ObjectEntity The object in its final state.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function preferAutomatic(ObjectEntity $saved, array $applied): ObjectEntity;
}//end interface
