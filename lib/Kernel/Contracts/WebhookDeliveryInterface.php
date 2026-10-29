<?php

/**
 * WebhookDeliveryInterface: deliver and preview webhooks.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Contract
 * @package  OCA\OpenRegister\Kernel\Contracts
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts;

use OCA\OpenRegister\Db\Webhook;
use OCP\EventDispatcher\Event;
use OCP\IRequest;

/**
 * Delivers events to webhooks, previews a payload, and intercepts a request for webhooks that ask to. Implemented by WebhookService.
 */
interface WebhookDeliveryInterface {

	/**
	 * Dispatch event to all matching webhooks
	 *
	 * @param Event $_event The event to dispatch (unused but provided by event system)
	 * @param string $eventName Event class name
	 * @param array $payload Event payload data
	 *
	 * @return void
	 */
	public function dispatchEvent(Event $_event, string $eventName, array $payload): void;

	/**
	 * Deliver webhook to target URL
	 *
	 * @param Webhook $webhook Webhook configuration
	 * @param string $eventName Event name
	 * @param array $payload Payload data
	 * @param int $attempt Current attempt number (for retries)
	 * @param int|null $timeoutCapSeconds Optional hard cap on connect + total timeout;
	 *                                    used by the synchronous interception path
	 *                                    (INTERCEPTION_TIMEOUT_SECONDS). Null keeps the
	 *                                    per-webhook timeout (async deliveries).
	 *
	 * @return bool Success status
	 *
	 * Fallback for connection errors without response
	 */
	public function deliverWebhook(
		Webhook $webhook,
		string $eventName,
		array $payload,
		int $attempt = 1,
		?int $timeoutCapSeconds = null,
	): bool;

	/**
	 * The payload a delivery of this webhook would send for one event, without sending it
	 *
	 * The body comes from buildPayload(), the method a delivery uses, so the
	 * preview cannot drift from what is sent. `mapped` says whether the mapping
	 * shaped it: a delivery whose mapping is missing or fails falls back to the
	 * standard payload, and the preview must not show that fallback as mapped.
	 *
	 * @param Webhook $webhook   The webhook, saved or as the dialog holds it.
	 * @param string  $eventName The event class the payload is for.
	 * @param array   $payload   The event payload.
	 *
	 * @return array{payload: array, mapped: bool}
	 */
	public function previewPayload(Webhook $webhook, string $eventName, array $payload): array;

	/**
	 * Intercept request and send to webhooks
	 *
	 * Finds webhooks configured for "before" events matching this request,
	 * sends CloudEvent-formatted payloads, and optionally processes responses
	 * to modify the request.
	 *
	 * This enables pre-request webhook notifications and request modification
	 * based on webhook responses.
	 *
	 * @param IRequest $request The HTTP request
	 * @param string $eventType The event type (e.g., 'object.creating')
	 *
	 * @return array Modified request data or original request data
	 *
	 * Fallback when formatter is unavailable
	 */
	public function interceptRequest(IRequest $request, string $eventType): array;
}//end interface
