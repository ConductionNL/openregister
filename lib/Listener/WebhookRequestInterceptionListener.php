<?php

/**
 * OpenRegister Webhook Request Interception Listener
 *
 * Sends an incoming object write request to the interception webhooks and continues with their answer.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Listener
 * @package  OCA\OpenRegister\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Listener;

use OCA\OpenRegister\Event\ObjectWriteRequestEvent;
use OCA\OpenRegister\Service\WebhookService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * Runs the request-interception webhooks for an object write request.
 *
 * @spec openspec/specs/webhook-payload-mapping/spec.md#request-interception-must-support-pre-event-webhooks
 *
 * @template-implements IEventListener<ObjectWriteRequestEvent>
 */
final class WebhookRequestInterceptionListener implements IEventListener {

	/**
	 * Constructor
	 *
	 * @param WebhookService $webhookService The webhooks.
	 */
	public function __construct(
		private readonly WebhookService $webhookService,
	) {
	}//end __construct()

	/**
	 * Replace the request body with what the interception webhooks answer.
	 *
	 * @param Event $event The event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/webhook-payload-mapping/spec.md#request-interception-must-support-pre-event-webhooks
	 */
	public function handle(Event $event): void {
		if (($event instanceof ObjectWriteRequestEvent) === false) {
			return;
		}

		$event->setBody($this->webhookService->interceptRequest(request: $event->getRequest(), eventType: $event->getEventType()));
	}//end handle()
}//end class
