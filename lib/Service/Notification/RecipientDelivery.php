<?php

/**
 * One recipient's share of a notification rule firing.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Notification
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/notificatie-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Notification;

/**
 * The recipient, the text rendered for them and the channels they keep.
 *
 * @spec openspec/specs/notificatie-engine/spec.md
 */
final class RecipientDelivery {

	/**
	 * The recipient's locale, or null when unknown.
	 *
	 * @var string|null
	 */
	public ?string $locale = null;

	/**
	 * Subject rendered for the recipient, or null before rendering.
	 *
	 * @var string|null
	 */
	public ?string $subject = null;

	/**
	 * Body rendered for the recipient.
	 *
	 * @var string
	 */
	public string $message = '';

	/**
	 * Channels left after the recipient's preference.
	 *
	 * @var array<int, string>
	 */
	public array $channels = [];

	/**
	 * Why the delivery did not go out, recorded in its history rows.
	 *
	 * @var string
	 */
	public string $status = '';

	/**
	 * Event id its history rows carry; queued deliveries carry none.
	 *
	 * @var string|null
	 */
	public ?string $eventId = null;

	/**
	 * Constructor.
	 *
	 * @param string $uid The recipient uid.
	 */
	public function __construct(
		public readonly string $uid,
	) {
	}//end __construct()
}//end class
