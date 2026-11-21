<?php

/**
 * OpenRegister UserPlatform
 *
 * The session, event dispatcher, language factory and logger the user service runs on.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\User
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/profile-actions/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\User;

use OCP\EventDispatcher\IEventDispatcher;
use OCP\IUserSession;
use OCP\L10N\IFactory;
use Psr\Log\LoggerInterface;

/**
 * The session, event dispatcher, language factory and logger the user service runs on.
 *
 * @spec openspec/specs/profile-actions/spec.md
 */
final readonly class UserPlatform {
	/**
	 * Bundle the collaborators.
	 *
	 * @param IUserSession     $userSession     The user session service.
	 * @param IEventDispatcher $eventDispatcher The event dispatcher service.
	 * @param IFactory         $l10nFactory     L10N factory for language detection.
	 * @param LoggerInterface  $logger          The logger.
	 */
	public function __construct(
		public IUserSession $userSession,
		public IEventDispatcher $eventDispatcher,
		public IFactory $l10nFactory,
		public LoggerInterface $logger,
	) {
	}//end __construct()
}//end class
