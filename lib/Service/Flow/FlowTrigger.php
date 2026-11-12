<?php

/**
 * One fired trigger event: the event id, the object it is about, who fired it and its extra context.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

/**
 * One fired trigger event, as {@see FlowTriggerServiceInterface::trigger()} takes it.
 *
 * @spec openspec/changes/or-flow-triggers/specs/flow-triggers/spec.md
 */
final class FlowTrigger {
	/**
	 * Constructor.
	 *
	 * @param string               $event   The event id (e.g. `object.created`).
	 * @param array<string, mixed> $subject `{uuid, register, schema}` of the object, when there is one.
	 * @param string|null          $user    The user whose action fired the event.
	 * @param array<string, mixed> $context Extra run-level metadata (the event payload, say).
	 */
	public function __construct(
		public readonly string $event,
		public readonly array $subject = [],
		public readonly ?string $user = null,
		public readonly array $context = [],
	) {
	}//end __construct()
}//end class
