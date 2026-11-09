<?php

/**
 * Applies a validated migration-pack definition to one parsed source row.
 *
 * Format-agnostic by design: `resolveSource()` accepts either a flat key
 * (CSV/Excel column name, or a top-level JSON key) or a JSON-Pointer-style
 * `/a/b/c` path (nested JSON), so the same engine serves CSV, Excel, and
 * JSON imports without a format-specific code path. The caller (ImportService)
 * merges the mapped `data` back into the row shape the existing
 * schema-driven import pipeline already expects (target property name =>
 * value, with `id` recognised by the existing update-by-id convention), so
 * the single write path (`ObjectService::saveObjects()`/`saveObject()`) is
 * unchanged.
 *
 * Literal-leak guard (fleet lesson — a transform/template reference that
 * doesn't resolve must ERROR the row, never pass the literal through): the
 * `lookup` transform errors the row when the source value is present but
 * has no entry in the map and no `default` is configured, rather than
 * silently passing the raw, unmapped source value through to the target
 * schema property.
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

use OCP\IL10N;

/**
 * Maps one source row (CSV row / Excel row / decoded JSON object) onto a set
 * of target schema-property values, per a migration-pack definition.
 *
 * @spec openspec/specs/migration-mapping-packs/spec.md
 */
class MappingEngine {
	/**
	 * Applies transforms and resolves source pointers.
	 *
	 * @var MappingTransformer
	 */
	private readonly MappingTransformer $transformer;

	/**
	 * Constructor.
	 *
	 * The row errors this engine returns are shown to the person running the
	 * import or the editor's preview, so each message goes through the app's
	 * translations; the values inside them stay as found.
	 *
	 * @param IL10N $l10n The app's translations.
	 */
	public function __construct(
		private readonly IL10N $l10n,
	) {
		$this->transformer = new MappingTransformer(l10n: $l10n);
	}//end __construct()

	/**
	 * Apply a pack definition to one source row.
	 *
	 * @param array<string, mixed> $pack The validated pack definition (decoded JSON).
	 * @param array<string, mixed> $sourceRow The parsed source row (flat for CSV/Excel, possibly nested for JSON).
	 * @param int $rowNumber 1-based row number, used only to label errors.
	 *
	 * @return array{data: array<string, mixed>, errors: list<array{row: int, source: string, target: ?string, transform: ?string, message: string}>}
	 *
	 * @spec openspec/specs/migration-mapping-packs/spec.md#the-mapping-engine-must-apply-pack-transforms-per-row-and-never-leak-unresolved-references
	 */
	public function mapRow(array $pack, array $sourceRow, int $rowNumber): array {
		$mapped = ['data' => ($pack['defaults'] ?? []), 'errors' => []];
		foreach (($pack['fieldMappings'] ?? []) as $mapping) {
			$mapped = $this->mergeField(mapped: $mapped, mapping: $mapping, sourceRow: $sourceRow, rowNumber: $rowNumber);
		}

		$mapped['data'] = $this->applyIdStrategy(pack: $pack, sourceRow: $sourceRow, data: $mapped['data']);
		return $mapped;
	}//end mapRow()

	/**
	 * Whether a given (1-based) row number is listed in the pack's `skipRows`.
	 *
	 * @param array<string, mixed> $pack      The pack definition.
	 * @param int                  $rowNumber 1-based row number.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/migration-mapping-packs/spec.md
	 */
	public function isRowSkipped(array $pack, int $rowNumber): bool {
		$skipRows = $pack['skipRows'] ?? [];
		return in_array($rowNumber, $skipRows, true);
	}//end isRowSkipped()

	/**
	 * Map one field mapping and merge the outcome into the row built so far:
	 * a value under its target, or an error labelled with the row number.
	 *
	 * @param array{data: array<string, mixed>, errors: list<array<string, mixed>>} $mapped    The row so far.
	 * @param mixed                                                                 $mapping   The field mapping.
	 * @param array<string, mixed>                                                  $sourceRow The source row.
	 * @param int                                                                   $rowNumber 1-based row number.
	 *
	 * @return array{data: array<string, mixed>, errors: list<array<string, mixed>>} The row with the field merged.
	 */
	private function mergeField(array $mapped, mixed $mapping, array $sourceRow, int $rowNumber): array {
		$field = $this->mapField(mapping: $mapping, sourceRow: $sourceRow);
		if (isset($field['message']) === true) {
			$mapped['errors'][] = (['row' => $rowNumber] + $field);
			return $mapped;
		}

		if (array_key_exists('target', $field) === true) {
			$mapped['data'][$field['target']] = $field['value'];
		}

		return $mapped;
	}//end mergeField()

	/**
	 * Map one field mapping.
	 *
	 * @param mixed                $mapping   The field mapping.
	 * @param array<string, mixed> $sourceRow The source row.
	 *
	 * @return array<string, mixed> `[target, value]`, an error (`source, target, transform, message`), or `[]` when skipped.
	 */
	private function mapField(mixed $mapping, array $sourceRow): array {
		$value = $this->transformer->resolveSource(row: $sourceRow, pointer: (string)($mapping['source'] ?? ''));
		if ($this->isEmptyValue(value: $value) === true) {
			return $this->mapEmptyField(mapping: $mapping, value: $value, sourceRow: $sourceRow);
		}

		return $this->transformField(mapping: $mapping, value: $value, sourceRow: $sourceRow);
	}//end mapField()

	/**
	 * Map a field whose source is missing or empty.
	 *
	 * A required field is an error. A `const` transform always applies,
	 * regardless of the source value. Every other transform is skipped
	 * (leaving any seeded default in place): there is nothing to map, and
	 * nothing to error.
	 *
	 * @param mixed                $mapping   The field mapping.
	 * @param mixed                $value     The empty source value (null or '').
	 * @param array<string, mixed> $sourceRow The source row.
	 *
	 * @return array<string, mixed> As mapField().
	 */
	private function mapEmptyField(mixed $mapping, mixed $value, array $sourceRow): array {
		if (($mapping['required'] ?? false) === true) {
			$source = (string)($mapping['source'] ?? '');
			return $this->fieldError(mapping: $mapping, message: $this->l10n->t('Required source field "%s" is missing or empty', [$source]));
		}

		if ($this->transformType(mapping: $mapping) !== 'const') {
			return [];
		}

		return $this->transformField(mapping: $mapping, value: $value, sourceRow: $sourceRow);
	}//end mapEmptyField()

	/**
	 * Run a field's transform and return its value or its error.
	 *
	 * @param mixed                $mapping   The field mapping.
	 * @param mixed                $value     The resolved source value.
	 * @param array<string, mixed> $sourceRow The source row.
	 *
	 * @return array<string, mixed> As mapField().
	 */
	private function transformField(mixed $mapping, mixed $value, array $sourceRow): array {
		$result = $this->transformer->apply(value: $value, transform: ($mapping['transform'] ?? null), sourceRow: $sourceRow);
		if ($result['error'] !== null) {
			return $this->fieldError(mapping: $mapping, message: $result['error']);
		}

		return ['target' => (string)($mapping['target'] ?? ''), 'value' => $result['value']];
	}//end transformField()

	/**
	 * A field error, without its row number.
	 *
	 * @param mixed  $mapping The field mapping.
	 * @param string $message The translated message.
	 *
	 * @return array{source: string, target: string, transform: mixed, message: string}
	 */
	private function fieldError(mixed $mapping, string $message): array {
		return [
			'source' => (string)($mapping['source'] ?? ''),
			'target' => (string)($mapping['target'] ?? ''),
			'transform' => $this->transformType(mapping: $mapping),
			'message' => $message,
		];
	}//end fieldError()

	/**
	 * The `type` of a mapping's transform block, or null without one.
	 *
	 * @param mixed $mapping The field mapping.
	 *
	 * @return mixed The transform type.
	 */
	private function transformType(mixed $mapping): mixed {
		$transform = ($mapping['transform'] ?? null);
		if (is_array($transform) === false) {
			return null;
		}

		return ($transform['type'] ?? null);
	}//end transformType()

	/**
	 * Resolve the id/uuid target from `idStrategy`, setting `data['id']`
	 * when the strategy is `sourceField` and a value is present. The
	 * `generate` strategy is a no-op here — leaving `data['id']` unset lets
	 * the existing import pipeline treat the row as a create, exactly as it
	 * already does for CSV/JSON rows with no id column.
	 *
	 * @param array<string, mixed> $pack      The pack definition.
	 * @param array<string, mixed> $sourceRow The source row.
	 * @param array<string, mixed> $data      The mapped target data so far.
	 *
	 * @return array<string, mixed> The (possibly id-augmented) target data.
	 */
	private function applyIdStrategy(array $pack, array $sourceRow, array $data): array {
		$pointer = $this->idPointer(pack: $pack);
		if ($pointer === null) {
			return $data;
		}

		$idValue = $this->transformer->resolveSource(row: $sourceRow, pointer: $pointer);
		if ($this->isEmptyValue(value: $idValue) === false) {
			$data['id'] = (string)$idValue;
		}

		return $data;
	}//end applyIdStrategy()

	/**
	 * Where a `sourceField` id strategy reads the id; null for any other strategy.
	 *
	 * @param array<string, mixed> $pack The pack definition.
	 *
	 * @return string|null The source pointer, or null.
	 */
	private function idPointer(array $pack): ?string {
		$idStrategy = ($pack['idStrategy'] ?? []);
		if (($idStrategy['type'] ?? null) !== 'sourceField') {
			return null;
		}

		return (string)($idStrategy['field'] ?? '');
	}//end idPointer()

	/**
	 * Whether a source value counts as empty (missing, null or '').
	 *
	 * @param mixed $value The value.
	 *
	 * @return bool True when empty.
	 */
	private function isEmptyValue(mixed $value): bool {
		return $value === null || $value === '';
	}//end isEmptyValue()
}//end class
