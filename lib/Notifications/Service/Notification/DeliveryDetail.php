<?php

/**
 * DeliveryDetail: the text a delivery carried and why it failed.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Notifications\Service\Notification
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/notificatie-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Notifications\Service\Notification;

/**
 * The subject a delivery carried, the recipient's locale, and why it did not deliver.
 *
 * @spec openspec/specs/notificatie-engine/spec.md
 */
final class DeliveryDetail {

	/**
	 * Constructor.
	 *
	 * @param string|null $subject      The interpolated subject (null when no subject was rendered).
	 * @param string|null $locale       Recipient locale (null for broadcast channels).
	 * @param string|null $errorMessage Why it did not deliver, when it did not.
	 */
	public function __construct(
		public readonly ?string $subject = null,
		public readonly ?string $locale = null,
		public readonly ?string $errorMessage = null,
	) {
	}//end __construct()
}//end class
