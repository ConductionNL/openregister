<?php

/**
 * OpenRegister ObjectWriteRequestEvent
 *
 * Dispatched synchronously when an object write request arrives, before its body is filtered and saved.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Event
 * @package  OCA\OpenRegister\Event
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

namespace OCA\OpenRegister\Event;

use OCP\EventDispatcher\Event;
use OCP\IRequest;

/**
 * An object write request, before its body is filtered and saved.
 *
 * The body starts as the request parameters; a listener may replace it. An exception a listener
 * throws reaches the dispatcher's caller.
 *
 * @spec openspec/specs/webhook-payload-mapping/spec.md#request-interception-must-support-pre-event-webhooks
 */
class ObjectWriteRequestEvent extends Event {

	/**
	 * The body the write continues with.
	 *
	 * @var array<string, mixed>
	 */
	private array $body;

	/**
	 * Constructor
	 *
	 * @param IRequest $request   The incoming request.
	 * @param string   $eventType The write, for example 'object.creating'.
	 */
	public function __construct(
		private readonly IRequest $request,
		private readonly string $eventType,
	) {
		parent::__construct();
		$this->body = $request->getParams();
	}//end __construct()

	/**
	 * The incoming request.
	 *
	 * @return IRequest
	 *
	 * @spec openspec/specs/webhook-payload-mapping/spec.md#request-interception-must-support-pre-event-webhooks
	 */
	public function getRequest(): IRequest {
		return $this->request;
	}//end getRequest()

	/**
	 * The write, for example 'object.creating'.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/webhook-payload-mapping/spec.md#request-interception-must-support-pre-event-webhooks
	 */
	public function getEventType(): string {
		return $this->eventType;
	}//end getEventType()

	/**
	 * The body the write continues with.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/webhook-payload-mapping/spec.md#request-interception-must-support-pre-event-webhooks
	 */
	public function getBody(): array {
		return $this->body;
	}//end getBody()

	/**
	 * Replace the body the write continues with.
	 *
	 * @param array<string, mixed> $body The body.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/webhook-payload-mapping/spec.md#request-interception-must-support-pre-event-webhooks
	 */
	public function setBody(array $body): void {
		$this->body = $body;
	}//end setBody()
}//end class
