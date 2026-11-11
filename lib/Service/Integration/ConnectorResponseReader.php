<?php

/**
 * ConnectorResponseReader — reads what the connector CallService answers.
 *
 * The connector answers a plain array, a JSON or other string, a scalar, a
 * jsonSerialize object, or a call log (an object exposing getResponse() or
 * an ObjectEntity whose object carries `response` / `statusCode`). This
 * class turns any of those into the decoded upstream body, the upstream
 * HTTP status and the transport metadata (status, duration, correlation id,
 * flattened headers). It reads only; it never calls anything.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Integration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/integration-registry/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Integration;

/**
 * Decodes connector responses and reads their transport metadata.
 *
 * @spec openspec/specs/integration-registry/spec.md
 */
final class ConnectorResponseReader {

	/**
	 * Decode the upstream body out of a connector response.
	 *
	 * @param mixed $response The connector response.
	 *
	 * @return array<string,mixed> The decoded body; a non-array body is wrapped as `{body}`.
	 *
	 * @spec openspec/specs/integration-registry/spec.md
	 */
	public function decode(mixed $response): array {
		if (is_array($response) === true) {
			return $response;
		}

		// A call log: pull the upstream body out of its response payload.
		$payload = $this->payload(response: $response);
		if ($payload !== null) {
			return $this->decodePayload(payload: $payload);
		}

		return $this->decodeOther(response: $response);
	}//end decode()

	/**
	 * The transport metadata of a connector response.
	 *
	 * @param mixed $response The connector response.
	 *
	 * @return array{status: int, durationMs: int, correlationId: ?string, headers: array<string,string>}
	 *
	 * @spec openspec/specs/integration-registry/spec.md
	 */
	public function meta(mixed $response): array {
		$meta = ['status' => $this->status(response: $response), 'durationMs' => 0, 'correlationId' => null, 'headers' => []];

		$payload = $this->payload(response: $response);
		if ($payload === null && is_array($response) === true) {
			$payload = $response;
		}

		if (is_array($payload) === false) {
			return $meta;
		}

		return $this->withPayloadMeta(meta: $meta, payload: $payload);
	}//end meta()

	/**
	 * The upstream HTTP status of a connector response, or 0 when it carries none.
	 *
	 * @param mixed $response The connector response.
	 *
	 * @return int The status.
	 *
	 * @spec openspec/specs/integration-registry/spec.md
	 */
	public function status(mixed $response): int {
		if (is_object($response) === false) {
			return 0;
		}

		if (method_exists($response, 'getStatusCode') === true) {
			return (int)$response->getStatusCode();
		}

		$status = $this->logStatus(response: $response);
		if (is_numeric($status) === false) {
			return 0;
		}

		return (int)$status;
	}//end status()

	/**
	 * Flatten response headers to `name => first value`.
	 *
	 * @param array<mixed> $headers The headers; a value may be a list.
	 *
	 * @return array<string,string>
	 *
	 * @spec openspec/specs/integration-registry/spec.md
	 */
	public function flattenHeaders(array $headers): array {
		$out = [];
		foreach ($headers as $name => $value) {
			if (is_array($value) === true) {
				$value = ($value[0] ?? '');
			}

			$out[(string)$name] = (string)$value;
		}

		return $out;
	}//end flattenHeaders()

	/**
	 * The response payload of a call log, or null when the response is not one.
	 *
	 * @param mixed $response The connector response.
	 *
	 * @return array<string,mixed>|null
	 */
	private function payload(mixed $response): ?array {
		if (is_object($response) === false) {
			return null;
		}

		$payload = $this->rawPayload(response: $response);
		if (is_array($payload) === false) {
			return null;
		}

		return $payload;
	}//end payload()

	/**
	 * The raw response payload of a call-log object.
	 *
	 * @param object $response The call log.
	 *
	 * @return mixed
	 */
	private function rawPayload(object $response): mixed {
		if (method_exists($response, 'getResponse') === true) {
			return $response->getResponse();
		}

		if (method_exists($response, 'getObject') === true) {
			return ($response->getObject()['response'] ?? null);
		}

		return null;
	}//end rawPayload()

	/**
	 * The status a call-log object carries in its payload or, failing that, at its top level.
	 *
	 * @param object $response The call log.
	 *
	 * @return mixed
	 */
	private function logStatus(object $response): mixed {
		$status = ($this->payload(response: $response)['statusCode'] ?? null);
		if ($status === null && method_exists($response, 'getObject') === true) {
			$status = ($response->getObject()['statusCode'] ?? null);
		}

		return $status;
	}//end logStatus()

	/**
	 * Decode the body of a call-log payload; a payload without a body is the answer itself.
	 *
	 * @param array<string,mixed> $payload The response payload.
	 *
	 * @return array<string,mixed>
	 */
	private function decodePayload(array $payload): array {
		if (array_key_exists('body', $payload) === false) {
			return $payload;
		}

		$body = $payload['body'];
		if (($payload['encoding'] ?? null) === 'base64' && is_string($body) === true) {
			$body = (string)base64_decode($body, true);
		}

		return $this->decode(response: $body);
	}//end decodePayload()

	/**
	 * Decode a jsonSerialize object, a string or a scalar.
	 *
	 * @param mixed $response The connector response.
	 *
	 * @return array<string,mixed>
	 */
	private function decodeOther(mixed $response): array {
		if (is_object($response) === true && method_exists($response, 'jsonSerialize') === true) {
			$data = $response->jsonSerialize();
			return $this->arrayOrBody(candidate: $data, body: $data);
		}

		if (is_string($response) === true) {
			return $this->arrayOrBody(candidate: json_decode($response, true), body: $response);
		}

		return ['body' => $response];
	}//end decodeOther()

	/**
	 * The candidate when it is an array, else the body wrapped as `{body}`.
	 *
	 * @param mixed $candidate The decoded value.
	 * @param mixed $body      What to wrap when the candidate is not an array.
	 *
	 * @return array<string,mixed>
	 */
	private function arrayOrBody(mixed $candidate, mixed $body): array {
		if (is_array($candidate) === true) {
			return $candidate;
		}

		return ['body' => $body];
	}//end arrayOrBody()

	/**
	 * Fill status (when still 0), duration and headers from a payload.
	 *
	 * @param array{status: int, durationMs: int, correlationId: ?string, headers: array<string,string>} $meta    The meta so far.
	 * @param array<string,mixed>                                                                         $payload The payload.
	 *
	 * @return array{status: int, durationMs: int, correlationId: ?string, headers: array<string,string>}
	 */
	private function withPayloadMeta(array $meta, array $payload): array {
		if ($meta['status'] === 0 && isset($payload['statusCode']) === true) {
			$meta['status'] = (int)$payload['statusCode'];
		}

		if (isset($payload['responseTime']) === true && is_numeric($payload['responseTime']) === true) {
			$meta['durationMs'] = (int)round((float)$payload['responseTime']);
		}

		return $this->withHeaderMeta(meta: $meta, headers: ($payload['headers'] ?? []));
	}//end withPayloadMeta()

	/**
	 * Fill the flattened headers and the correlation id, when the headers are an array.
	 *
	 * @param array{status: int, durationMs: int, correlationId: ?string, headers: array<string,string>} $meta    The meta so far.
	 * @param mixed                                                                                       $headers The payload headers.
	 *
	 * @return array{status: int, durationMs: int, correlationId: ?string, headers: array<string,string>}
	 */
	private function withHeaderMeta(array $meta, mixed $headers): array {
		if (is_array($headers) === true) {
			$meta['headers']       = $this->flattenHeaders(headers: $headers);
			$meta['correlationId'] = $this->firstHeaderValue(headers: $headers, name: 'X-Correlation-ID');
		}

		return $meta;
	}//end withHeaderMeta()

	/**
	 * The first value of a header, matched case-insensitively; null when absent or empty.
	 *
	 * @param array<mixed> $headers The headers.
	 * @param string       $name    The header name.
	 *
	 * @return string|null
	 */
	private function firstHeaderValue(array $headers, string $name): ?string {
		$needle = strtolower($name);
		foreach ($headers as $headerName => $value) {
			if (strtolower((string)$headerName) === $needle) {
				return $this->headerText(value: $value);
			}
		}

		return null;
	}//end firstHeaderValue()

	/**
	 * The text of a header value: the first element of a list; null when empty.
	 *
	 * @param mixed $value The header value.
	 *
	 * @return string|null
	 */
	private function headerText(mixed $value): ?string {
		if (is_array($value) === true) {
			$value = ($value[0] ?? null);
		}

		if ($value === null || $value === '') {
			return null;
		}

		return (string)$value;
	}//end headerText()
}//end class
