<?php

/**
 * OptOutAuthorityFactory: builds the default opt-out authority for callers outside Notifications.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Notifications\Service\Notification
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

use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Builds the OptOutAuthority a caller gets when it injects no opt-out seam of its own.
 *
 * FlowMessagingService builds its default authority on its own dispatcher. It does
 * that through here, typed as OptOutAuthorityInterface, so the concrete class stays
 * inside the Notifications component. The construction is exactly what the caller did
 * before: the same three collaborators and no IL10N.
 *
 * @spec openspec/specs/external-recipient-opt-out/spec.md#requirement-the-send-email-flow-step-asks-integriq-before-it-mails-an-external-address-req-ero-001
 */
final class OptOutAuthorityFactory {

	/**
	 * Build the default opt-out authority.
	 *
	 * @param IEventDispatcher $eventDispatcher Carries the question to integriq.
	 * @param IAppConfig       $appConfig       Holds the rollback switch.
	 * @param LoggerInterface  $logger          Records every refusal for want of an answer.
	 *
	 * @return OptOutAuthorityInterface A new OptOutAuthority.
	 *
	 * @spec openspec/specs/external-recipient-opt-out/spec.md#requirement-the-send-email-flow-step-asks-integriq-before-it-mails-an-external-address-req-ero-001
	 */
	public static function create(IEventDispatcher $eventDispatcher, IAppConfig $appConfig, LoggerInterface $logger): OptOutAuthorityInterface {
		return new OptOutAuthority(eventDispatcher: $eventDispatcher, appConfig: $appConfig, logger: $logger);
	}//end create()
}//end class
