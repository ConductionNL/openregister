<?php
/**
 * WriteElevationInterface: whether a write the person's rights refused may go ahead under an open elevation.
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

use OCA\OpenRegister\Service\Object\PermissionAsk;

/**
 * The elevation of the run in progress, as the permission check (Core) asks it.
 *
 * Asked only after the person's own rights refused a write, and never cached: an
 * elevation belongs to one run, not to the person. The area that opens elevations
 * binds its answer in its own wiring.
 *
 * @spec openspec/changes/object-actions-declared-and-run/specs/declared-actions/spec.md#requirement-elevation-is-declared-scoped-and-attributed
 */
interface WriteElevationInterface {

	/**
	 * Whether the open elevation lists this refused write; the listing entry is marked used.
	 *
	 * @param PermissionAsk $ask The question, with the schema, register and verb written to.
	 *
	 * @return bool True when the write may go ahead as the system.
	 *
	 * @spec openspec/changes/object-actions-declared-and-run/specs/declared-actions/spec.md#requirement-elevation-is-declared-scoped-and-attributed
	 */
	public function permits(PermissionAsk $ask): bool;
}//end interface
