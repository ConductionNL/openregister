<?php

/**
 * OpenRegister HandoffExecutedTarget
 *
 * The target side of an executed handoff: the kind handed off to and the created object.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Event
 * @package  OCA\OpenRegister\Event
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

namespace OCA\OpenRegister\Event;

use OCA\OpenRegister\Kernel\Contracts\ObjectRef;

/**
 * The target side of an executed handoff.
 *
 * @spec openspec/changes/semantic-object-handoff-engine/specs/semantic-object-handoff/spec.md
 *   (Requirement: ADR-041 event emission on handoff execution)
 */
final class HandoffExecutedTarget {
	/**
	 * Constructor.
	 *
	 * @param string    $semanticType The canonical kind URI that was handed off to.
	 * @param ObjectRef $object       Register id, schema id and UUID of the created target object.
	 */
	public function __construct(
		public readonly string $semanticType,
		public readonly ObjectRef $object,
	) {
	}//end __construct()
}//end class
