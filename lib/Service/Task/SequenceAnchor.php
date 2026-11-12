<?php

/**
 * What an approval sequence is about, who asked for it, and where it started.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Task
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/flow-approval-consolidation/spec.md#requirement-an-approval-is-an-ordered-task-sequence-with-one-position-enabled-at-a-time
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Task;

/**
 * The anchor of an approval sequence: the object it is about, the requester, the register and its provenance.
 *
 * @spec openspec/specs/flow-approval-consolidation/spec.md#requirement-an-approval-is-an-ordered-task-sequence-with-one-position-enabled-at-a-time
 */
final readonly class SequenceAnchor {

	/**
	 * @param string                  $objectUuid  The object the approval is about.
	 * @param string|null             $requesterId Who attempted the gated transition.
	 * @param int|null                $registerId  The anchor's register.
	 * @param SequenceProvenance|null $provenance  The flow run and node that started it, when a flow did.
	 */
	public function __construct(
		public string $objectUuid,
		public ?string $requesterId = null,
		public ?int $registerId = null,
		public ?SequenceProvenance $provenance = null,
	) {
	}//end __construct()
}//end class
