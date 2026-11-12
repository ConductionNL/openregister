<?php

/**
 * PayloadTokenReading: how a calculation reference reads a criterion token
 * from the saving object's payload.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Calculation
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Calculation;

use DateTimeImmutable;
use Throwable;

/**
 * Criterion tokens (`@self.<field>`, `@ref.<path>`, `{ "year": <token> }`) read from a payload.
 */
trait PayloadTokenReading {
	use PayloadPathWalking;

	/**
	 * Resolve a criterion token to a concrete value.
	 *
	 * Supported tokens (kept deliberately small to avoid coupling to the pure
	 * CalculationEvaluator):
	 * - A literal scalar (passed through unchanged).
	 * - A `@self.<field>` / `@ref`-prefixed dotted-path string, resolved against the payload.
	 * - A single-key AST node `{ "year": <token> }` — only `year` is supported
	 *   here, mirroring the lookup criteria used by rate tables.
	 *
	 * @param array<string, mixed> $payload Object data with `@self` injected.
	 * @param mixed $token The criterion token.
	 *
	 * @return mixed The resolved value.
	 *
	 * @spec openspec/changes/calc-engine-reference-lookup/tasks.md#task-2
	 */
	private function resolveToken(array $payload, mixed $token): mixed {
		if (is_string($token) === true) {
			return $this->stringToken(payload: $payload, token: $token);
		}

		if (is_array($token) === true && count($token) === 1 && array_key_first($token) === 'year') {
			return $this->yearToken(payload: $payload, arg: $token['year']);
		}

		return $token;
	}//end resolveToken()

	/**
	 * Resolve every criterion of a filter map against the payload.
	 *
	 * @param array<string, mixed> $payload Object data with `@self` injected.
	 * @param array<int|string, mixed> $criteria Target field to criterion token.
	 *
	 * @return array<string, mixed> Target field to resolved value.
	 */
	private function resolveTokens(array $payload, array $criteria): array {
		$filters = [];
		foreach ($criteria as $target => $criterion) {
			$filters[(string)$target] = $this->resolveToken(payload: $payload, token: $criterion);
		}

		return $filters;
	}//end resolveTokens()

	/**
	 * The declarations of a named declaration map that can be resolved: a
	 * non-empty name and an array spec, in declaration order.
	 *
	 * @param array<int|string, mixed> $declarations Name to declaration.
	 *
	 * @return array<int|string, array<string, mixed>> The usable declarations, keys kept.
	 */
	private function usableDeclarations(array $declarations): array {
		return array_filter(
			$declarations,
			static fn (mixed $spec, int|string $name): bool => (string)$name !== '' && is_array($spec) === true,
			ARRAY_FILTER_USE_BOTH
		);
	}//end usableDeclarations()

	/**
	 * A string token: the payload value for a `@self.` or `@ref.` path, else the literal.
	 *
	 * @param array<string, mixed> $payload Object data with `@self` injected.
	 * @param string               $token   The criterion token.
	 *
	 * @return mixed The resolved value.
	 */
	private function stringToken(array $payload, string $token): mixed {
		if (str_starts_with($token, '@self.') === true || str_starts_with($token, '@ref.') === true) {
			return $this->readPath(payload: $payload, path: $token);
		}

		return $token;
	}//end stringToken()

	/**
	 * Resolve a `{ "year": <token> }` criterion to a four-digit year integer.
	 *
	 * @param array<string, mixed> $payload Object data with `@self` injected.
	 * @param mixed $arg The inner token (typically a `@self.<date>` reference).
	 *
	 * @return int|null The year, or null when the inner token is not a parseable date.
	 *
	 * @spec openspec/changes/calc-engine-reference-lookup/tasks.md#task-2
	 */
	private function yearToken(array $payload, mixed $arg): ?int {
		$raw = $this->resolveToken(payload: $payload, token: $arg);
		if (is_string($raw) === false || $raw === '') {
			return null;
		}

		try {
			return (int)(new DateTimeImmutable($raw))->format('Y');
		} catch (Throwable) {
			return null;
		}
	}//end yearToken()

	/**
	 * Read a dotted path (e.g. `@self.journeyDate`) from the payload.
	 *
	 * @param array<string, mixed> $payload Object data.
	 * @param string $path Dotted path.
	 *
	 * @return mixed The resolved value, or null when the path is missing.
	 *
	 * @spec openspec/changes/calc-engine-reference-lookup/tasks.md#task-2
	 */
	private function readPath(array $payload, string $path): mixed {
		$parts = explode('.', $path);

		// `@self.<field>` may address either the injected `@self` system
		// metadata (id/created/…) OR — the common case for lookup criteria —
		// one of the object's OWN data fields (e.g. `@self.journeyDate`). The
		// listener injects user data at the top level of the payload, not under
		// `@self`, so when `@self.<field>` is not a system field fall back to
		// the top-level field. This makes the lookup parameterisable by the
		// saving object exactly as the schema author expects.
		if ($parts[0] === '@self' && count($parts) === 2) {
			return $this->selfField(payload: $payload, field: $parts[1]);
		}

		return $this->walkPath(payload: $payload, parts: $parts);
	}//end readPath()

	/**
	 * A `@self.<field>` value: the system field when `@self` has it, else the top-level data field.
	 *
	 * @param array<string, mixed> $payload Object data.
	 * @param string               $field   The field name.
	 *
	 * @return mixed The value, or null when neither has it.
	 */
	private function selfField(array $payload, string $field): mixed {
		$self = ($payload['@self'] ?? []);
		if (is_array($self) === true && array_key_exists($field, $self) === true) {
			return $self[$field];
		}

		return ($payload[$field] ?? null);
	}//end selfField()
}//end trait
