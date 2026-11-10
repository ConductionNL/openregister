<?php

/**
 * OpenRegister HandoffTarget
 *
 * The target object a handoff created, with the register and schema it was created in.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Handoff
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/semantic-object-handoff-engine/specs/semantic-object-handoff/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Handoff;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;

/**
 * The target object a handoff created, with the register and schema it was created in.
 *
 * Passed between the write helpers of {@see HandoffService}.
 *
 * @spec openspec/changes/semantic-object-handoff-engine/specs/semantic-object-handoff/spec.md
 */
final class HandoffTarget {

	/**
	 * Name the created target.
	 *
	 * @param ObjectEntity $object   The created target object.
	 * @param Register     $register The target register (magic-table context).
	 * @param Schema       $schema   The target schema, which is the resolved provider (magic-table context).
	 *
	 * @spec openspec/changes/semantic-object-handoff-engine/specs/semantic-object-handoff/spec.md
	 */
	public function __construct(
		public readonly ObjectEntity $object,
		public readonly Register $register,
		public readonly Schema $schema,
	) {
	}//end __construct()
}//end class
