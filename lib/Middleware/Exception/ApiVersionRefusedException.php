<?php

/**
 * A request named a contract version this instance will not answer on.
 *
 * Two refusals, deliberately different status codes, because they are
 * different facts about the caller:
 *
 *  - 410 Gone, for a version that was withdrawn. The version existed, we
 *    ended it, and the answer names the successor. A 404 here would read as
 *    a mistake in the caller's own URL and send an integrator hunting through
 *    their routing rather than reading our changelog (design D-3).
 *  - 400 Bad Request, for a version nothing ever declared. The caller is
 *    asking for a contract that does not exist, usually a typo, and the answer
 *    lists what this instance does serve so the fix is in the response.
 *
 * @category Middleware
 * @package  OCA\OpenRegister\Middleware\Exception
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/openapi-generation/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Middleware\Exception;

use Exception;
use OCA\OpenRegister\Service\ApiVersion\ApiVersion;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;

/**
 * Carries the refusal a caller receives when its named version cannot answer.
 *
 * @category Middleware
 * @package  OCA\OpenRegister\Middleware\Exception
 */
class ApiVersionRefusedException extends Exception {

	/**
	 * The HTTP status the refusal answers with.
	 *
	 * @var integer
	 */
	private readonly int $statusCode;

	/**
	 * The identifier the caller named.
	 *
	 * @var string
	 */
	private readonly string $requestedId;

	/**
	 * The identifier the caller should move to, or null.
	 *
	 * @var string|null
	 */
	private readonly ?string $successor;

	/**
	 * The identifiers this instance does serve.
	 *
	 * @var array<int, string>
	 */
	private readonly array $acceptable;

	/**
	 * Constructor.
	 *
	 * The sentence a human reads follows from the status: 410 names the
	 * successor, anything else lists the versions this instance serves.
	 *
	 * @param int $statusCode The HTTP status to answer with.
	 * @param string $requestedId The identifier the caller named.
	 * @param string|null $successor The identifier to move to, or null.
	 * @param array<int, string> $acceptable The identifiers this instance serves.
	 *
	 * @return void
	 */
	public function __construct(
		int $statusCode,
		string $requestedId,
		?string $successor = null,
		array $acceptable = [],
	) {
		$this->statusCode = $statusCode;
		$this->requestedId = $requestedId;
		$this->successor = $successor;
		$this->acceptable = $acceptable;
		parent::__construct(message: $this->sentence(), code: $statusCode);

	}//end __construct()

	/**
	 * The refusal for a version that was withdrawn.
	 *
	 * @param ApiVersion $version The withdrawn version.
	 * @param array<int, string> $acceptable The identifiers this instance serves.
	 *
	 * @return self The refusal.
	 *
	 * @spec openspec/specs/openapi-generation/spec.md
	 */
	public static function withdrawn(ApiVersion $version, array $acceptable): self {
		return new self(
			statusCode: Http::STATUS_GONE,
			requestedId: $version->id,
			successor: $version->successor,
			acceptable: $acceptable,
		);

	}//end withdrawn()

	/**
	 * The refusal for a version nothing declares.
	 *
	 * @param string $requestedId The identifier the caller named.
	 * @param array<int, string> $acceptable The identifiers this instance serves.
	 *
	 * @return self The refusal.
	 *
	 * @spec openspec/specs/openapi-generation/spec.md
	 */
	public static function unknown(string $requestedId, array $acceptable): self {
		return new self(
			statusCode: Http::STATUS_BAD_REQUEST,
			requestedId: $requestedId,
			successor: null,
			acceptable: $acceptable,
		);

	}//end unknown()

	/**
	 * The response a caller receives.
	 *
	 * @return JSONResponse The refusal, with the successor named in a Link header when there is one.
	 *
	 * @spec openspec/specs/openapi-generation/spec.md
	 */
	public function toResponse(): JSONResponse {
		$response = new JSONResponse(data: $this->body(), statusCode: $this->statusCode);
		if ($this->successor !== null) {
			$response->addHeader(
				'Link',
				'</apps/openregister/api/versions/' . $this->successor . '/oas>; rel="successor-version"'
			);
		}

		return $response;

	}//end toResponse()

	/**
	 * The sentence a human reads: the successor for a withdrawn version, the served versions otherwise.
	 *
	 * @return string The message.
	 */
	private function sentence(): string {
		if ($this->statusCode === Http::STATUS_GONE) {
			return 'API version ' . $this->requestedId . ' has been withdrawn. Use version ' . (string)$this->successor . '.';
		}

		return 'API version "' . $this->requestedId . '" is not served by this instance. Served versions: ' . implode(', ', $this->acceptable) . '.';

	}//end sentence()

	/**
	 * The refusal's body: the message, the version asked for, the versions served and any successor.
	 *
	 * @return array<string, mixed> The body.
	 */
	private function body(): array {
		$body = [
			'error' => $this->getMessage(),
			'requestedVersion' => $this->requestedId,
			'servedVersions' => $this->acceptable,
		];

		if ($this->successor !== null) {
			$body['successorVersion'] = $this->successor;
		}

		return $body;

	}//end body()
}//end class
