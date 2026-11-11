<?php

/**
 * OpenRegister HandoffExecutedSource
 *
 * The source side of an executed handoff: the owning app, the object and its label.
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
 * The source side of an executed handoff.
 *
 * @spec openspec/changes/semantic-object-handoff-engine/specs/semantic-object-handoff/spec.md
 *   (Requirement: ADR-041 event emission on handoff execution)
 */
final class HandoffExecutedSource {
	/**
	 * Constructor.
	 *
	 * @param string      $app    The app owning the emitting schema ('' when undeclared).
	 * @param ObjectRef   $object Register id, schema id and UUID of the source object.
	 * @param string|null $label  Human-readable label of the source object.
	 */
	public function __construct(
		public readonly string $app,
		public readonly ObjectRef $object,
		public readonly ?string $label,
	) {
	}//end __construct()
}//end class
