<?php

/**
 * The email channel of a view alert, in each recipient's own language.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Listener
 * @package  OCA\OpenRegister\Listener
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Listener;

use OCA\OpenRegister\Notifications\Service\Notification\EmailSenderInterface;
use OCP\IUserManager;
use OCP\L10N\IFactory;

/**
 * The email channel of a view alert, in each recipient's own language.
 *
 * @spec openspec/changes/saved-view-count-alert/specs/saved-search-views/spec.md#requirement-a-view-alert-fires-once-per-crossing-and-re-arms
 */
final readonly class ViewAlertMail {
	/**
	 * Bundle the services.
	 *
	 * @param EmailSenderInterface $email The engine's email sender.
	 * @param IFactory $l10nFactory For each recipient's own language in an email.
	 * @param IUserManager $userManager To read a recipient's language.
	 */
	public function __construct(
		public EmailSenderInterface $email,
		public IFactory $l10nFactory,
		public IUserManager $userManager,
	) {
	}//end __construct()
}//end class
