<?php

/**
 * Where a sent flow email came from: the flow, run and step, and the user the run acted as.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Event
 * @package  OCA\OpenRegister\Event
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/flow-send-email-external-recipients/spec.md#requirement-every-sent-email-is-announced-to-listeners
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Event;

/**
 * Where a sent flow email came from: the flow, run and step, and the user the run acted as.
 *
 * @spec openspec/specs/flow-send-email-external-recipients/spec.md#requirement-every-sent-email-is-announced-to-listeners
 */
final class FlowEmailOrigin {
	/**
	 * Constructor.
	 *
	 * @param string|null $flowId The flow the sending run belongs to.
	 * @param string|null $runId The run that sent the email.
	 * @param string $stepName The step that sent the email: the node id when known, otherwise the node type.
	 * @param string $actingUser The user the run acted as, the sender of record.
	 */
	public function __construct(
		public readonly ?string $flowId,
		public readonly ?string $runId,
		public readonly string $stepName,
		public readonly string $actingUser,
	) {
	}//end __construct()
}//end class
