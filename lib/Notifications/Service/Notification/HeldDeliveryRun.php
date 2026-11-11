<?php

/**
 * OpenRegister HeldDeliveryRun
 *
 * One recipient delivery held by quiet hours or a digest schedule.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Notification
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Notifications\Service\Notification;

/**
 * The firing and the recipient delivery the hold steps share.
 *
 * Built and used only inside AnnotationNotificationDispatcher.
 *
 * @spec openspec/specs/notificatie-engine/spec.md
 */
final class HeldDeliveryRun {

	/**
	 * Constructor.
	 *
	 * @param RuleFiring        $firing   The rule and the event.
	 * @param RecipientDelivery $delivery The recipient, its text and the channels it keeps.
	 */
	public function __construct(
		public readonly RuleFiring $firing,
		public readonly RecipientDelivery $delivery,
	) {
	}//end __construct()
}//end class
