<?php

/**
 * OpenRegister HandoffSourceRef
 *
 * The source object of a handoff, as the caller names it.
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

/**
 * The source object of a handoff, as the caller names it: register and schema
 * (slug or id) and the object's id or uuid.
 *
 * @spec openspec/changes/semantic-object-handoff-engine/specs/semantic-object-handoff/spec.md
 */
final class HandoffSourceRef {

	/**
	 * Name the source object.
	 *
	 * @param string $register The source register slug or id.
	 * @param string $schema   The source schema slug or id.
	 * @param string $id       The source object id/uuid.
	 *
	 * @spec openspec/changes/semantic-object-handoff-engine/specs/semantic-object-handoff/spec.md
	 */
	public function __construct(
		public readonly string $register,
		public readonly string $schema,
		public readonly string $id,
	) {
	}//end __construct()
}//end class
