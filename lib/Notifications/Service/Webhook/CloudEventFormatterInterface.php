<?php

/**
 * CloudEventFormatterInterface: the public surface of CloudEventFormatter, for callers to type against.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Notifications
 * @package  OCA\OpenRegister\Notifications\Service\Webhook
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

namespace OCA\OpenRegister\Notifications\Service\Webhook;

use OCP\IRequest;

/**
 * Every public method of CloudEventFormatter. Callers depend on this interface, not on the class.
 */
interface CloudEventFormatterInterface {

	/**
	 * Format an event payload as a CloudEvent
	 *
	 * Creates a CloudEvent-compliant payload from event data.
	 * This allows external systems to receive standardized event notifications.
	 *
	 * @param CloudEventAttributes $attributes The event type, source (defaults to '/apps/openregister') and subject.
	 * @param array                $payload    Event payload data
	 *
	 * @return (array|null|string)[] CloudEvent-formatted payload
	 *
	 * @psalm-return array{specversion: '1.0', type: string, source: string,
	 *     id: string, time: string, datacontenttype: 'application/json',
	 *     subject: null|string, dataschema: null, data: array,
	 *     openregister: array{app: 'openregister', version: '1.0.0'}}
	 *
	 * @spec openspec/specs/webhook-payload-mapping/spec.md
	 */
	public function formatAsCloudEvent(CloudEventAttributes $attributes, array $payload): array;

	/**
	 * Format a request as a CloudEvent
	 *
	 * Creates a CloudEvent-compliant payload from an HTTP request.
	 * This is useful for pre-request webhook interception where the request
	 * itself is the event data.
	 *
	 * @param IRequest $request   The HTTP request
	 * @param string   $eventType The event type (e.g., 'object.creating', 'object.updating')
	 *
	 * @return ((array|false|mixed|string)[]|null|string)[] CloudEvent-formatted payload
	 *
	 * @psalm-return array{specversion: '1.0', type: string, source: string,
	 *     id: string, time: string, datacontenttype: string,
	 *     subject: null|string, dataschema: null,
	 *     data: array{method: mixed|string, path: false|mixed|string,
	 *     queryParams: array|mixed, headers: array|mixed, body: array|mixed,...},
	 *     openregister: array{app: 'openregister', version: '1.0.0'}}
	 *
	 * @spec openspec/specs/webhook-payload-mapping/spec.md
	 */
	public function formatRequestAsCloudEvent(IRequest $request, string $eventType): array;
}//end interface
