<?php

/**
 * OpenRegister CloudEvent Formatter
 *
 * Formatter for creating CloudEvents specification compliant webhook payloads.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Webhook
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/webhook-payload-mapping/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Notifications\Service\Webhook;

use OCP\IRequest;
use Symfony\Component\Uid\Uuid;

/**
 * CloudEventFormatter formats webhook payloads as CloudEvents
 *
 * Formats webhook payloads according to the CloudEvents 1.0 specification.
 * CloudEvents is a specification for describing event data in a common way,
 * making it easier to integrate services that produce and consume events.
 *
 * CloudEvents provides:
 * - Consistent event structure across systems
 * - Interoperability between different event systems
 * - Standardized metadata (source, type, id, time)
 * - Extensibility through attributes
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Webhook
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 */
class CloudEventFormatter implements CloudEventFormatterInterface {
	/**
	 * Format an event payload as a CloudEvent
	 *
	 * Creates a CloudEvent-compliant payload from event data.
	 * This allows external systems to receive standardized event notifications.
	 *
	 * @param string $eventType Event type (e.g., 'object.created', 'object.updated')
	 * @param array $payload Event payload data
	 * @param string|null $source Event source (defaults to '/apps/openregister')
	 * @param string|null $subject Event subject (optional)
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
	public function formatAsCloudEvent(string $eventType, array $payload, ?string $source = null, ?string $subject = null): array {
		// The source defaults to the app when none is given.
		$source = ($source ?? '/apps/openregister');
		$attributes = ['type' => $eventType, 'source' => $source, 'datacontenttype' => 'application/json', 'subject' => $subject];
		return $this->envelope(attributes: $attributes, data: $payload);
	}//end formatAsCloudEvent()

	/**
	 * Format a request as a CloudEvent
	 *
	 * Creates a CloudEvent-compliant payload from an HTTP request.
	 * This is useful for pre-request webhook interception where the request
	 * itself is the event data.
	 *
	 * @param IRequest $request The HTTP request
	 * @param string $eventType The event type (e.g., 'object.creating', 'object.updating')
	 * @param array $data Additional event data
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
	public function formatRequestAsCloudEvent(IRequest $request, string $eventType, array $data = []): array {
		return $this->envelope(
			attributes: $this->requestAttributes(request: $request, eventType: $eventType),
			data: array_merge($this->requestData(request: $request), $data)
		);
	}//end formatRequestAsCloudEvent()

	/**
	 * The CloudEvent attributes of a request: its type, source, content type and subject.
	 *
	 * @param IRequest $request   The HTTP request.
	 * @param string   $eventType The event type.
	 *
	 * @return array<string, string|null> The `type`, `source`, `datacontenttype` and `subject`.
	 */
	private function requestAttributes(IRequest $request, string $eventType): array {
		return [
			'type' => $eventType,
			'source' => $this->getSource(request: $request),
			'datacontenttype' => $this->getContentTypeHeader(request: $request),
			'subject' => $this->getSubject(request: $request),
		];
	}//end requestAttributes()

	/**
	 * The event data a request carries: method, path, query parameters, headers and body.
	 *
	 * @param IRequest $request The HTTP request.
	 *
	 * @return array<string, mixed> The request data.
	 */
	private function requestData(IRequest $request): array {
		return [
			'method' => $request->getMethod(),
			'path' => $request->getPathInfo(),
			'queryParams' => $request->getParams(),
			'headers' => $this->getRequestHeaders(request: $request),
			'body' => $this->requestBody(request: $request),
		];
	}//end requestData()

	/**
	 * The CloudEvents 1.0 envelope around event data.
	 *
	 * @param array<string, string|null> $attributes The `type`, `source`, `datacontenttype` and `subject`.
	 * @param array                      $data       The event data.
	 *
	 * @return array<string, mixed> CloudEvent-formatted payload
	 */
	private function envelope(array $attributes, array $data): array {
		// Build CloudEvent payload according to CloudEvents 1.0 specification.
		return [
			// Required CloudEvent attributes.
			'specversion' => '1.0',
			'type' => $attributes['type'],
			'source' => $attributes['source'],
			'id' => Uuid::v4()->toRfc4122(),
			'time' => date('c'),

			// Optional CloudEvent attributes.
			'datacontenttype' => $attributes['datacontenttype'],
			'subject' => $attributes['subject'],
			'dataschema' => null,

			// Event data.
			'data' => $data,

			// OpenRegister-specific extensions.
			'openregister' => [
				'app' => 'openregister',
				'version' => $this->getAppVersion(),
			],
		];
	}//end envelope()

	/**
	 * The request's JSON body merged with its parameters (parameters win).
	 *
	 * @param IRequest $request The HTTP request
	 *
	 * @return array The body data.
	 */
	private function requestBody(IRequest $request): array {
		// Get request body.
		$requestBody = $request->getParams();
		$rawBody = '';
		if (method_exists($request, 'getRawBody') === true) {
			$rawBody = $request->getRawBody();
		}

		// Parse JSON body if present.
		$parsedBody = [];
		if (empty($rawBody) === false) {
			$decoded = json_decode($rawBody, true);
			if (json_last_error() === JSON_ERROR_NONE) {
				$parsedBody = $decoded;
			}
		}

		// Merge parsed body with request params.
		return array_merge($parsedBody, $requestBody);
	}//end requestBody()

	/**
	 * Get event source from request
	 *
	 * Determines the source identifier for the CloudEvent.
	 * The source identifies the context in which the event occurred.
	 * Format: {protocol}://{host}/apps/openregister
	 *
	 * @param IRequest $request The HTTP request to extract source from
	 *
	 * @return string Event source identifier (URI format)
	 */
	private function getSource(IRequest $request): string {
		// Build source URI from request protocol and host.
		// Protocol is determined from server protocol (https or http).
		$protocol = 'http://';
		if ($request->getServerProtocol() === 'https') {
			$protocol = 'https://';
		}

		$host = $protocol . $request->getServerHost();

		// Append OpenRegister app path to source.
		return $host . '/apps/openregister';
	}//end getSource()

	/**
	 * Get event subject from request
	 *
	 * Determines the subject identifier for the CloudEvent.
	 * The subject identifies the resource that the event relates to.
	 *
	 * @param IRequest $request The HTTP request
	 *
	 * @return null|string Event subject identifier or null
	 */
	private function getSubject(IRequest $request): ?string {
		$path = $request->getPathInfo();

		// Extract resource identifiers from path.
		// Example: /api/objects/{register}/{schema}/{id}.
		if (preg_match('#/api/objects/([^/]+)/([^/]+)(?:/([^/]+))?#', $path, $matches) === 1) {
			if (($matches[3] ?? null) !== null) {
				return 'object:' . $matches[1] . '/' . $matches[2] . '/' . $matches[3];
			}

			return 'object:' . $matches[1] . '/' . $matches[2];
		}

		return null;
	}//end getSubject()

	/**
	 * Get request headers
	 *
	 * Extracts relevant headers from the request.
	 *
	 * @param IRequest $request The HTTP request
	 *
	 * @return array<string, string> Request headers.
	 */
	private function getRequestHeaders(IRequest $request): array {
		$headers = [];

		// Get common headers.
		$headerKeys = [
			'Content-Type',
			'Accept',
			'Authorization',
			'User-Agent',
			'X-Requested-With',
		];

		foreach ($headerKeys as $key) {
			$value = $request->getHeader($key);
			if ($value !== '') {
				$headers[$key] = $value;
			}
		}

		return $headers;
	}//end getRequestHeaders()

	/**
	 * Get content type header from request
	 *
	 * Returns Content-Type header if present, otherwise defaults to 'application/json'.
	 *
	 * @param IRequest $request Request object with getHeader method
	 *
	 * @return string Content type header value
	 *
	 * @spec openspec/specs/webhook-payload-mapping/spec.md
	 */
	private function getContentTypeHeader(IRequest $request): string {
		$contentType = $request->getHeader('Content-Type');

		if (empty($contentType) === false) {
			return $contentType;
		}

		return 'application/json';
	}//end getContentTypeHeader()

	/**
	 * Get application version
	 *
	 * @return string Application version
	 *
	 * @psalm-return '1.0.0'
	 */
	private function getAppVersion(): string {
		// @todo Get actual version from appinfo/info.xml or composer.json.
		return '1.0.0';
	}//end getAppVersion()
}//end class
