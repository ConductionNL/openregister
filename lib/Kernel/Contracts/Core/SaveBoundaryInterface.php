<?php
/**
 * SaveBoundaryInterface: the write boundary an object save opens and closes, as the Core object service asks it.
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
 * The boundary around one object save: opened before the write, closed after it, and the entity to answer with.
 *
 * The object service (Core) opens and closes it around every save. The
 * request-scoped automatic-transition pass (Processes) implements it, because
 * whatever lifecycle move a save triggers is applied when the outermost
 * boundary closes, and binds its shared instance to this contract in its own
 * wiring.
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
	 * Close a write boundary; the outermost one applies what the save triggered.
	 *
	 * @return array<string, ObjectEntity> The last entity applied per object uuid, empty when nothing was.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function leave(): array;

	/**
	 * The entity a save answers with: the one applied after it when there is one, else the saved one.
	 *
	 * @param ObjectEntity                $saved   The entity the write itself produced.
	 * @param array<string, ObjectEntity> $applied What closing the boundary applied, by uuid.
	 *
	 * @return ObjectEntity The object in its final state.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function preferAutomatic(ObjectEntity $saved, array $applied): ObjectEntity;
}//end interface
