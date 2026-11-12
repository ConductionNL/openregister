<?php

/**
 * The audit digest and result summary of one MCP tool invocation.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Mcp
 * @package  OCA\OpenRegister\Mcp\BuiltIn
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction BV
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/ai-mcp/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Mcp\BuiltIn;

use Throwable;

/**
 * What the attribute and schema-derived providers write into an invocation
 * audit record: a digest of the arguments (never the raw arguments) and a
 * small outcome summary (never the raw result).
 */
final class ToolInvocationDigest {

	/**
	 * SHA-256 hex digest of the arguments, canonicalised by a recursive key
	 * sort so the digest does not depend on argument key order.
	 *
	 * @param array<string, mixed> $arguments Tool arguments.
	 *
	 * @return string The digest.
	 */
	public static function paramsDigest(array $arguments): string {
		$encoded = json_encode(
			self::deepKsort(value: $arguments),
			JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
		);

		if ($encoded === false) {
			$encoded = '';
		}

		return hash('sha256', $encoded);
	}//end paramsDigest()

	/**
	 * The summary of a successful invocation that returned one record.
	 *
	 * @param array<string, mixed> $result The invocation result.
	 *
	 * @return array{isError: false, id: string|null} The summary.
	 */
	public static function idSummary(array $result): array {
		return [
			'isError' => false,
			'id' => self::resultId(result: $result),
		];
	}//end idSummary()

	/**
	 * The summary of a failed invocation.
	 *
	 * @param Throwable $error What the invocation threw.
	 *
	 * @return array{isError: true, errorClass: class-string, message: string} The summary.
	 */
	public static function errorSummary(Throwable $error): array {
		return [
			'isError' => true,
			'errorClass' => get_class($error),
			'message' => substr($error->getMessage(), 0, 300),
		];
	}//end errorSummary()

	/**
	 * The string id of a result, when it has one.
	 *
	 * @param array<string, mixed>|null $result The invocation result, or null on failure.
	 *
	 * @return string|null The id.
	 */
	public static function resultId(?array $result): ?string {
		if ($result !== null && is_string($result['id'] ?? null) === true) {
			return $result['id'];
		}

		return null;
	}//end resultId()

	/**
	 * Recursively sort an array by key for deterministic serialisation.
	 *
	 * @param array<array-key, mixed> $value The array to sort.
	 *
	 * @return array<array-key, mixed> The sorted array.
	 */
	private static function deepKsort(array $value): array {
		ksort($value);
		foreach ($value as $key => $item) {
			if (is_array($item) === true) {
				$value[$key] = self::deepKsort(value: $item);
			}
		}

		return $value;
	}//end deepKsort()
}//end class
