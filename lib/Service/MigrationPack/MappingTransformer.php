<?php

/**
 * Applies the transforms of a migration-pack field mapping.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\MigrationPack
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/migration-mapping-packs/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\MigrationPack;

use DateTime;
use OCP\IL10N;

/**
 * Resolves source values (flat keys or `/a/b` pointers) and applies one
 * transform (`trim`, `date`, `bool-map`, `lookup`, `concat`, `const`) to a
 * value. Errors are returned, translated, never thrown.
 *
 * @spec openspec/specs/migration-mapping-packs/spec.md
 */
class MappingTransformer {
	/**
	 * Constructor.
	 *
	 * @param IL10N $l10n The app's translations.
	 */
	public function __construct(
		private readonly IL10N $l10n,
	) {
	}//end __construct()

	/**
	 * Apply one transform to a resolved source value.
	 *
	 * @param TransformRequest $request The resolved source value, the transform block (null for
	 *                                  identity passthrough) and the full source row.
	 *
	 * @return array{value: mixed, error: ?string}
	 *
	 * @spec openspec/specs/migration-mapping-packs/spec.md#the-mapping-engine-must-apply-pack-transforms-per-row-and-never-leak-unresolved-references
	 */
	public function apply(TransformRequest $request): array {
		$transform = $request->transform;
		if ($transform === null) {
			return ['value' => $request->value, 'error' => null];
		}

		$kind = $this->transformKind(transform: $transform);
		if ($kind === 'const' || $kind === 'trim') {
			return $this->applyValueTransform(request: $request, kind: $kind);
		}

		return $this->applyLookupTransform(request: $request, transform: $transform);
	}//end apply()

	/**
	 * Apply a `const` transform (its `value`, whatever the source) or a
	 * `trim` transform.
	 *
	 * @param TransformRequest $request The value and its (non-null) transform block.
	 * @param string           $kind    `const` or `trim`.
	 *
	 * @return array{value: mixed, error: ?string}
	 */
	private function applyValueTransform(TransformRequest $request, string $kind): array {
		if ($kind === 'const') {
			return ['value' => ($request->transform['value'] ?? null), 'error' => null];
		}

		return ['value' => trim((string)$request->value), 'error' => null];
	}//end applyValueTransform()

	/**
	 * The transform's type as the original switch matched it: it compared
	 * loosely, so a boolean `true` type equals the first case, `trim`. Kept
	 * as-is.
	 *
	 * @param array<string, mixed> $transform The transform block.
	 *
	 * @return mixed The type.
	 */
	private function transformKind(array $transform): mixed {
		$type = ($transform['type'] ?? null);
		if ($type === true) {
			return 'trim';
		}

		return $type;
	}//end transformKind()

	/**
	 * Apply a `bool-map` or `lookup` transform; otherwise see applyOtherTransform().
	 *
	 * @param TransformRequest     $request   The value and the source row.
	 * @param array<string, mixed> $transform The request's transform block.
	 *
	 * @return array{value: mixed, error: ?string}
	 */
	private function applyLookupTransform(TransformRequest $request, array $transform): array {
		$type = ($transform['type'] ?? null);
		if ($type === 'bool-map' || $type === 'lookup') {
			return $this->applyMapTransform(request: $request, coerceBool: $type === 'bool-map');
		}

		return $this->applyOtherTransform(request: $request, transform: $transform);
	}//end applyLookupTransform()

	/**
	 * Apply a `date` or `concat` transform; any other type is unknown.
	 *
	 * @param TransformRequest     $request   The value and the source row.
	 * @param array<string, mixed> $transform The request's transform block.
	 *
	 * @return array{value: mixed, error: ?string}
	 */
	private function applyOtherTransform(TransformRequest $request, array $transform): array {
		$type = ($transform['type'] ?? null);
		if ($type === 'date') {
			return $this->applyDateTransform(value: $request->value, transform: $transform);
		}

		if ($type === 'concat') {
			return $this->applyConcatTransform(request: $request, transform: $transform);
		}

		return ['value' => null, 'error' => $this->l10n->t('Unknown transform type "%s"', [(string)($type ?? '')])];
	}//end applyOtherTransform()

	/**
	 * `date` transform: parse the source value with `sourceFormat` (or a
	 * best-effort `DateTime` parse when omitted) and re-emit it as `targetFormat`
	 * (default `Y-m-d`).
	 *
	 * @param mixed                $value     The resolved source value.
	 * @param array<string, mixed> $transform The transform block.
	 *
	 * @return array{value: mixed, error: ?string}
	 */
	private function applyDateTransform(mixed $value, array $transform): array {
		$stringValue = (string)$value;
		try {
			$date = $this->parseDate(value: $stringValue, sourceFormat: ($transform['sourceFormat'] ?? null));
		} catch (\Throwable $e) {
			return ['value' => null, 'error' => $this->l10n->t('Could not parse date "%1$s": %2$s', [$stringValue, $e->getMessage()])];
		}

		if ($date === false) {
			$message = $this->l10n->t('Could not parse date "%1$s" with format "%2$s"', [$stringValue, $transform['sourceFormat']]);
			return ['value' => null, 'error' => $message];
		}

		return ['value' => $date->format($transform['targetFormat'] ?? 'Y-m-d'), 'error' => null];
	}//end applyDateTransform()

	/**
	 * Parse a date strictly with a non-empty string format, else best-effort.
	 *
	 * @param string $value        The source value.
	 * @param mixed  $sourceFormat The declared source format, if any.
	 *
	 * @return DateTime|false The date, or false when it does not match the format.
	 *
	 * @throws \Exception When the best-effort parse fails.
	 */
	private function parseDate(string $value, mixed $sourceFormat): DateTime|false {
		if (is_string($sourceFormat) === true && $sourceFormat !== '') {
			return date_create_from_format($sourceFormat, $value);
		}

		return new DateTime($value);
	}//end parseDate()

	/**
	 * Shared implementation for `bool-map` and `lookup` — both resolve the
	 * source value through a `map`, with an optional `default` and, absent a
	 * default, an error on an unresolved key (the literal-leak guard): an
	 * unresolved map key is a data-quality problem the migration operator
	 * must see and fix, never a value that silently passes through unmapped
	 * into the target schema property.
	 *
	 * @param TransformRequest $request    The value and its (non-null) transform block.
	 * @param bool             $coerceBool Whether to cast the mapped value to bool (bool-map) or return it as-is (lookup).
	 *
	 * @return array{value: mixed, error: ?string}
	 */
	private function applyMapTransform(TransformRequest $request, bool $coerceBool): array {
		$transform = (array)$request->transform;
		$map = ($transform['map'] ?? []);
		$key = (string)$request->value;
		if (array_key_exists($key, $map) === true) {
			return ['value' => $this->coerced(value: $map[$key], toBool: $coerceBool), 'error' => null];
		}

		if (array_key_exists('default', $transform) === true) {
			return ['value' => $this->coerced(value: $transform['default'], toBool: $coerceBool), 'error' => null];
		}

		return ['value' => null, 'error' => $this->l10n->t('Value "%s" has no mapping and no default is configured', [$key])];
	}//end applyMapTransform()

	/**
	 * A mapped value, cast to bool for `bool-map`.
	 *
	 * @param mixed $value  The mapped value.
	 * @param bool  $toBool Whether to cast it.
	 *
	 * @return mixed The value.
	 */
	private function coerced(mixed $value, bool $toBool): mixed {
		if ($toBool === true) {
			return (bool)$value;
		}

		return $value;
	}//end coerced()

	/**
	 * `concat` transform: join the primary source value with 0+ additional
	 * source fields, using `separator` (default a single space). Additional
	 * fields that resolve to nothing are treated as empty strings — a
	 * missing *optional* extra field is not itself a literal-leak case, since
	 * there is no map lookup involved.
	 *
	 * @param TransformRequest     $request   The primary source value and the full source row.
	 * @param array<string, mixed> $transform The request's transform block.
	 *
	 * @return array{value: mixed, error: ?string}
	 */
	private function applyConcatTransform(TransformRequest $request, array $transform): array {
		$parts = [(string)$request->value];
		foreach (($transform['fields'] ?? []) as $extraSource) {
			$extraValue = $this->resolveSource(row: $request->sourceRow, pointer: (string)$extraSource);
			$parts[] = (string)($extraValue ?? '');
		}

		return ['value' => implode(($transform['separator'] ?? ' '), $parts), 'error' => null];
	}//end applyConcatTransform()

	/**
	 * Resolve a source value from a row, given either a flat key or a
	 * JSON-Pointer-style `/a/b/c` path.
	 *
	 * @param array<string, mixed> $row     The source row.
	 * @param string               $pointer A flat key or a leading-`/` pointer path.
	 *
	 * @return mixed The resolved value, or null when not found.
	 *
	 * @spec openspec/specs/migration-mapping-packs/spec.md
	 */
	public function resolveSource(array $row, string $pointer): mixed {
		if ($pointer === '') {
			return null;
		}

		if ($pointer[0] !== '/') {
			return ($row[$pointer] ?? null);
		}

		return $this->resolvePointer(row: $row, pointer: $pointer);
	}//end resolveSource()

	/**
	 * Walk a `/a/b/c` pointer (`~1` = `/`, `~0` = `~`) into a row.
	 *
	 * @param array<string, mixed> $row     The source row.
	 * @param string               $pointer A leading-`/` pointer path.
	 *
	 * @return mixed The resolved value, or null when any segment is missing.
	 */
	private function resolvePointer(array $row, string $pointer): mixed {
		$cursor = $row;
		foreach (explode('/', ltrim($pointer, '/')) as $segment) {
			$segment = str_replace(['~1', '~0'], ['/', '~'], $segment);
			if (is_array($cursor) === false || array_key_exists($segment, $cursor) === false) {
				return null;
			}

			$cursor = $cursor[$segment];
		}

		return $cursor;
	}//end resolvePointer()

}//end class
