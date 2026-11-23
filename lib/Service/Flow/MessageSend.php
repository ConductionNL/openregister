<?php

/**
 * One messaging step's send: its configuration, the flow items it sends per,
 * the run context and the step's type id.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/flow-messaging-nodes/spec.md#requirement-flows-send-through-the-notification-subsystem-never-beside-it
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

/**
 * What a send, email or Talk step hands to the messaging service.
 *
 * @spec openspec/specs/flow-messaging-nodes/spec.md#requirement-flows-send-through-the-notification-subsystem-never-beside-it
 */
final class MessageSend {
	/**
	 * Describe the send.
	 *
	 * @param array  $config   The step configuration.
	 * @param array  $items    The flow items; one send per recipient per item.
	 * @param array  $context  The run context (acting user, report handle).
	 * @param string $stepName The step's type id, used as the send's rule identity; empty for a preview.
	 *
	 * @spec openspec/specs/flow-messaging-nodes/spec.md#requirement-flows-send-through-the-notification-subsystem-never-beside-it
	 */
	public function __construct(
		public readonly array $config,
		public readonly array $items,
		public readonly array $context,
		public readonly string $stepName = '',
	) {
	}//end __construct()
}//end class
