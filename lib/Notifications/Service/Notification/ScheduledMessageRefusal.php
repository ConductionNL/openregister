<?php

/**
 * Why a scheduled message cannot be acted on, with the HTTP status it answers.
 *
 * @category Service
 * @package  OCA\OpenRegister\Notifications\Service\Notification
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/send-at-on-the-messaging-leaf/specs/integration-message-dispatch/spec.md#requirement-a-send-may-be-scheduled-and-bound-to-an-object
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Notifications\Service\Notification;

use RuntimeException;

/**
 * A refusal of the scheduling side: 404 not on this object, 403 not allowed,
 * 409 no longer cancellable.
 *
 * @spec openspec/changes/send-at-on-the-messaging-leaf/specs/integration-message-dispatch/spec.md#requirement-a-send-may-be-scheduled-and-bound-to-an-object
 */
final class ScheduledMessageRefusal extends RuntimeException {

	/**
	 * Constructor.
	 *
	 * @param string $message Why.
	 * @param int    $status  The HTTP status the refusal answers.
	 *
	 * @spec openspec/changes/send-at-on-the-messaging-leaf/specs/integration-message-dispatch/spec.md#requirement-a-send-may-be-scheduled-and-bound-to-an-object
	 */
	public function __construct(string $message, public readonly int $status) {
		parent::__construct(message: $message);
	}//end __construct()
}//end class
