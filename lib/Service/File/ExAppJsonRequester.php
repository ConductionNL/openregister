<?php

/**
 * One signed JSON request to an ExApp through AppAPI.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\File
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/anonymisation-image-seam/specs/image-anonymisation/spec.md#requirement-region-detectors-plug-into-the-seam-and-an-absent-one-is-never-read-as-run-req-ais-002
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\File;

use OCP\Http\Client\IResponse;
use OCP\Server;
use RuntimeException;
use Throwable;

/**
 * Sends a request to an ExApp the way AnonymisationBackendService reaches
 * OpenAnonymiser: `OCA\AppAPI\PublicFunctions::exAppRequest()`, resolved
 * lazily so OpenRegister has no hard dependency on AppAPI.
 *
 * Unlike that service it never answers null: every failure is an exception
 * with the reason, so a caller can record a detector as `failed` and say why.
 *
 * @spec openspec/changes/anonymisation-image-seam/specs/image-anonymisation/spec.md#requirement-region-detectors-plug-into-the-seam-and-an-absent-one-is-never-read-as-run-req-ais-002
 */
class ExAppJsonRequester {

	/**
	 * The AppAPI class that signs and routes ExApp requests.
	 */
	public const PUBLIC_FUNCTIONS = 'OCA\\AppAPI\\PublicFunctions';

	/**
	 * Send one request and decode the JSON answer.
	 *
	 * @param string               $appId  The ExApp.
	 * @param string               $route  The ExApp route.
	 * @param array<string, mixed> $params The request body.
	 * @param string               $method The HTTP method.
	 *
	 * @return array<string, mixed> The decoded answer.
	 *
	 * @throws RuntimeException When AppAPI is absent, the request fails or the answer is not a JSON object.
	 *
	 * @spec openspec/changes/anonymisation-image-seam/specs/image-anonymisation/spec.md#requirement-region-detectors-plug-into-the-seam-and-an-absent-one-is-never-read-as-run-req-ais-002
	 */
	public function request(string $appId, string $route, array $params, string $method = 'POST'): array {
		$publicFunctionsClass = self::PUBLIC_FUNCTIONS;
		if (class_exists($publicFunctionsClass) === false) {
			throw new RuntimeException('AppAPI is not available, so ' . $appId . ' cannot be reached.');
		}

		try {
			// phpcs:ignore CustomSniffs.Nextcloud.NoServiceLocator.GlobalContainerLookup -- Optional AppAPI PublicFunctions: guarded by class_exists, absent unless the ExApp stack is installed.
			$publicFunctions = Server::get($publicFunctionsClass);
			$response = $publicFunctions->exAppRequest($appId, $route, null, $method, $params);
		} catch (Throwable $e) {
			throw new RuntimeException($appId . ' could not be reached: ' . $e->getMessage(), 0, $e);
		}

		if (is_array($response) === true) {
			// ExAppRequest returns an array only to signal an error.
			throw new RuntimeException($appId . ' request failed: ' . (string)($response['error'] ?? 'unknown error'));
		}

		if (($response instanceof IResponse) === false) {
			throw new RuntimeException($appId . ' gave no response.');
		}

		return $this->decode(response: $response, appId: $appId);
	}//end request()

	/**
	 * Decode an ExApp answer, refusing a non-2xx status or a body that is not a JSON object.
	 *
	 * @param IResponse $response The answer.
	 * @param string    $appId    The ExApp, for the message.
	 *
	 * @return array<string, mixed> The decoded body.
	 *
	 * @throws RuntimeException When it is refused.
	 *
	 * @spec openspec/changes/anonymisation-image-seam/specs/image-anonymisation/spec.md#requirement-region-detectors-plug-into-the-seam-and-an-absent-one-is-never-read-as-run-req-ais-002
	 */
	public function decode(IResponse $response, string $appId): array {
		$status = $response->getStatusCode();
		if ($status < 200 || $status >= 300) {
			throw new RuntimeException(sprintf('%s answered HTTP %d.', $appId, $status));
		}

		$decoded = json_decode((string)$response->getBody(), true);
		if (is_array($decoded) === false) {
			throw new RuntimeException($appId . ' answered something that is not a JSON object.');
		}

		return $decoded;
	}//end decode()
}//end class
