<?php

/**
 * Whether a notification recipient is inside the organisation or outside it.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Notification
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/notification-kinds-an-administrator-forces/specs/notificatie-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Notification;

/**
 * Where one recipient of a notification stands relative to the organisation.
 *
 * WHY THIS IS NOT A BOOLEAN. `decide()` used to take
 * `bool $recipientIsInternal = true`, and the default was the dangerous half
 * of the pair: a caller that did not pass it got "inside the organisation",
 * so an `internalOnly` kind was cleared for a recipient nobody had vouched
 * for. The refusal this policy exists to make is the one that never fired.
 *
 * An enum with no default makes the question unanswerable by omission. Every
 * caller states which side of the organisation the recipient is on, and a
 * reader of the call site can see the answer without opening this file.
 *
 * @spec openspec/changes/notification-kinds-an-administrator-forces/specs/notificatie-engine/spec.md
 */
enum RecipientAudience: string {

	// The recipient holds an account in this organisation.
	case Internal = 'internal';

	// The recipient is reachable only from outside the organisation.
	case External = 'external';

	/**
	 * Whether this audience is inside the organisation.
	 *
	 * @return boolean True when the recipient belongs to the organisation.
	 *
	 * @spec openspec/changes/notification-kinds-an-administrator-forces/specs/notificatie-engine/spec.md
	 */
	public function isInternal(): bool {
		return $this === self::Internal;
	}//end isInternal()

}//end enum
