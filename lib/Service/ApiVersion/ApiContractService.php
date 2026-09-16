<?php

/**
 * One OpenAPI description per served contract version.
 *
 * WHY A FILTER AND NOT A SECOND GENERATOR. `OasService` already reads the
 * registers and schemas and produces a correct OpenAPI 3.1.0 document; a
 * second generator per version would be a second thing to keep true, and the
 * one that drifts is always the one that is not the default. So there is one
 * generator, and a version narrows its output to the paths that version
 * declares. Version 1 declares the whole surface, which is exactly what it has
 * always answered, so its document is today's document with the contract
 * stamped on it.
 *
 * WHAT THE STAMP CARRIES. A root `x-api-version` block naming the status, the
 * end date and the successor, so a consumer that reads the description rather
 * than the headers still learns the deadline. And, for a deprecated version,
 * `deprecated: true` on every operation — the OpenAPI-native spelling, which
 * means a generated client warns its own author without anyone reading a
 * changelog.
 *
 * 🔴 A WITHDRAWN VERSION HAS NO DOCUMENT. Publishing the description of a
 * contract that answers 410 would let an integrator generate a client against
 * it and discover the withdrawal at runtime, which is the failure this whole
 * change exists to end.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ApiVersion
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
 * @spec openspec/changes/api-as-a-versioned-surface/specs/openapi-generation/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ApiVersion;

use OCA\OpenRegister\Service\OasService;

/**
 * Produces the OpenAPI description belonging to one contract version.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ApiVersion
 */
class ApiContractService {

	/**
	 * The HTTP methods an OpenAPI path item may carry an operation under.
	 *
	 * Listed rather than "every key that is an array", because a path item
	 * also holds `parameters`, `servers` and `$ref`, and marking `parameters`
	 * deprecated would be nonsense a validator accepts in silence.
	 *
	 * @var array<int, string>
	 */
	private const OPERATION_KEYS = [
		'get',
		'put',
		'post',
		'delete',
		'options',
		'head',
		'patch',
		'trace',
	];

	/**
	 * Constructor.
	 *
	 * @param OasService $oasService Generates the description from registers and schemas.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly OasService $oasService,
	) {

	}//end __construct()

	/**
	 * The OpenAPI description of one contract version.
	 *
	 * @param ApiVersion $version The version to describe.
	 * @param string|null $registerId Narrow to one register, or null for all.
	 *
	 * @return array<string, mixed> The description.
	 *
	 * @spec openspec/changes/api-as-a-versioned-surface/specs/openapi-generation/spec.md
	 */
	public function documentFor(ApiVersion $version, ?string $registerId = null): array {
		$document = $this->oasService->createOas($registerId, false);

		$document['paths'] = $this->pathsFor(version: $version, paths: ($document['paths'] ?? []));
		$document['x-api-version'] = $this->stamp(version: $version);

		if ($version->isDeprecated() === true) {
			$document['paths'] = self::markOperationsDeprecated(paths: $document['paths']);
		}

		return $document;

	}//end documentFor()

	/**
	 * The contract stamp published at the root of the description.
	 *
	 * @param ApiVersion $version The version being described.
	 *
	 * @return array<string, mixed> The stamp.
	 *
	 * @spec openspec/changes/api-as-a-versioned-surface/specs/openapi-generation/spec.md
	 */
	public function stamp(ApiVersion $version): array {
		$stamp = $version->jsonSerialize();
		$stamp['deprecated'] = $version->isDeprecated();
		$stamp['versionHeader'] = ApiVersionNegotiator::REQUEST_HEADER;

		return $stamp;

	}//end stamp()

	/**
	 * Narrow a path map to the paths a version declares.
	 *
	 * @param ApiVersion $version The version being described.
	 * @param array<string, mixed> $paths The generated path map.
	 *
	 * @return array<string, mixed> The narrowed path map.
	 */
	private function pathsFor(ApiVersion $version, array $paths): array {
		$narrowed = [];
		foreach ($paths as $path => $item) {
			if ($version->servesPath(path: (string)$path) === true) {
				$narrowed[$path] = $item;
			}
		}

		return $narrowed;

	}//end pathsFor()

	/**
	 * Mark every operation in a path map deprecated.
	 *
	 * @param array<string, mixed> $paths The path map.
	 *
	 * @return array<string, mixed> The path map, with each operation deprecated.
	 */
	private static function markOperationsDeprecated(array $paths): array {
		foreach ($paths as $path => $item) {
			if (is_array($item) === false) {
				continue;
			}

			foreach (self::OPERATION_KEYS as $method) {
				if (isset($item[$method]) === true && is_array($item[$method]) === true) {
					$item[$method]['deprecated'] = true;
				}
			}

			$paths[$path] = $item;
		}

		return $paths;

	}//end markOperationsDeprecated()
}//end class
