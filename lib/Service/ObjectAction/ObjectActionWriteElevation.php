<?php

/**
 * ObjectActionWriteElevation: the declared action's open elevation, as the permission check asks it.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ObjectAction
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/object-actions-declared-and-run/specs/declared-actions/spec.md#requirement-elevation-is-declared-scoped-and-attributed
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ObjectAction;

use OCA\OpenRegister\Kernel\Contracts\Core\WriteElevationInterface;
use OCA\OpenRegister\Service\Object\PermissionAsk;

/**
 * Answers the permission check's elevation question from the request-scoped frame the action run opened.
 *
 * @spec openspec/changes/object-actions-declared-and-run/specs/declared-actions/spec.md#requirement-elevation-is-declared-scoped-and-attributed
 */
final class ObjectActionWriteElevation implements WriteElevationInterface {

	/**
	 * Whether the open elevation lists this refused write; the listing entry is marked used.
	 *
	 * @param PermissionAsk $ask The question, with the schema, register and verb written to.
	 *
	 * @return bool True when the write may go ahead as the system.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) ObjectActionElevationScope is the request-scoped frame the run opened.
	 *
	 * @spec openspec/changes/object-actions-declared-and-run/specs/declared-actions/spec.md#requirement-elevation-is-declared-scoped-and-attributed
	 */
	public function permits(PermissionAsk $ask): bool {
		return ObjectActionElevationScope::permits(
			write: new ElevatedWrite(schemaId: $ask->schema->getId(), registerId: $ask->register, verb: $ask->action)
		);
	}//end permits()
}//end class
