<?php

/**
 * OpenRegister Flow Messaging Platform
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

use OCA\OpenRegister\Notifications\Service\Notification\NotificationTemplatingInterface;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * What a flow send renders and reports with: the placeholder evaluator, the app config, the logger and the event dispatcher.
 *
 * @spec openspec/specs/flow-messaging-nodes/spec.md#requirement-flows-send-through-the-notification-subsystem-never-beside-it
 */
final readonly class FlowMessagingPlatform {

	/**
	 * Bundle the collaborators.
	 *
	 * @param NotificationTemplatingInterface $templating The dialect's placeholder evaluator.
	 * @param IAppConfig $appConfig App config for the recipient bound.
	 * @param LoggerInterface $logger Logger for send diagnostics.
	 * @param IEventDispatcher $eventDispatcher Announces each sent email (FlowEmailSentEvent).
	 */
	public function __construct(
		public NotificationTemplatingInterface $templating,
		public IAppConfig $appConfig,
		public LoggerInterface $logger,
		public IEventDispatcher $eventDispatcher,
	) {
	}//end __construct()
}//end class
