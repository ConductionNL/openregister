<?php

/**
 * ReindexSelection: the one selection an administrator asks to reindex.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Operations
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/search-quality-operators-and-facets/specs/zoeken-filteren/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Operations;

use OCA\OpenRegister\Service\BulkJob\BulkJobSelection;

/**
 * A register and schema, a register and schema with object list filters, or
 * a list of object ids: exactly one of the three.
 *
 * Two selections in one request are refused rather than combined, because
 * whether the ids narrow the schema or add to it is a guess either way, and a
 * reindex of the wrong set is only noticed when a search keeps missing.
 *
 * @spec openspec/changes/search-quality-operators-and-facets/specs/zoeken-filteren/spec.md#requirement-an-administrator-reindexes-a-selection-and-watches-it-run-req-sqf-007
 */
final readonly class ReindexSelection {

	/**
	 * Every object of one register and schema.
	 *
	 * @var string
	 */
	public const KIND_SCHEMA = 'schema';

	/**
	 * The objects of one register and schema that match object list filters.
	 *
	 * @var string
	 */
	public const KIND_FILTER = 'filter';

	/**
	 * The objects a list of ids names.
	 *
	 * @var string
	 */
	public const KIND_IDS = 'ids';

	/**
	 * Describe the selection.
	 *
	 * @param string               $kind       One of the KIND_ constants.
	 * @param integer|null         $registerId The register, for a schema or filter selection.
	 * @param integer|null         $schemaId   The schema, for a schema or filter selection.
	 * @param array<string, mixed> $filters    The object list filters, for a filter selection.
	 * @param array<int, string>   $ids        The object ids, for an id selection.
	 */
	public function __construct(
		public string $kind,
		public ?int $registerId = null,
		public ?int $schemaId = null,
		public array $filters = [],
		public array $ids = [],
	) {
	}//end __construct()

	/**
	 * Read the selection from request parameters, refusing anything but exactly one.
	 *
	 * @param array<string, mixed> $params The request parameters: `register`, `schema`, `filters`, `ids`.
	 *
	 * @return self The selection.
	 *
	 * @throws ReindexRefusedException When the parameters hold no selection or more than one.
	 *
	 * @spec openspec/changes/search-quality-operators-and-facets/specs/zoeken-filteren/spec.md#requirement-an-administrator-reindexes-a-selection-and-watches-it-run-req-sqf-007
	 */
	public static function fromRequest(array $params): self {
		$hasIds = (($params['ids'] ?? null) !== null);
		$hasScope = (($params['register'] ?? null) !== null || ($params['schema'] ?? null) !== null);
		$hasFilters = (($params['filters'] ?? null) !== null && $params['filters'] !== []);

		if ($hasIds === true && ($hasScope === true || $hasFilters === true)) {
			throw new ReindexRefusedException(message: 'Send exactly one selection: a register and schema (with or without filters), or a list of object ids, not both.');
		}

		if ($hasIds === true) {
			return new self(kind: self::KIND_IDS, ids: self::idsFrom(value: $params['ids']));
		}

		if ($hasScope === false) {
			throw new ReindexRefusedException(message: 'Send exactly one selection: a register and schema (with or without filters), or a list of object ids.');
		}

		$registerId = self::positiveIntFrom(value: ($params['register'] ?? null));
		$schemaId = self::positiveIntFrom(value: ($params['schema'] ?? null));
		if ($registerId === null || $schemaId === null) {
			throw new ReindexRefusedException(message: 'A register selection names both the register and the schema by their numeric id.');
		}

		if ($hasFilters === false) {
			return new self(kind: self::KIND_SCHEMA, registerId: $registerId, schemaId: $schemaId);
		}

		if (is_array($params['filters']) === false) {
			throw new ReindexRefusedException(message: 'The filters are an object of object list filters.');
		}

		return new self(kind: self::KIND_FILTER, registerId: $registerId, schemaId: $schemaId, filters: $params['filters']);

	}//end fromRequest()

	/**
	 * The selection as it is stored for the run.
	 *
	 * @return array{kind: string, register: int|null, schema: int|null, filters: array<string, mixed>, ids: array<int, string>} The stored form.
	 */
	public function toArray(): array {
		return [
			'kind' => $this->kind,
			'register' => $this->registerId,
			'schema' => $this->schemaId,
			'filters' => $this->filters,
			'ids' => $this->ids,
		];

	}//end toArray()

	/**
	 * The selection read back from its stored form.
	 *
	 * @param array<string, mixed> $stored The stored form, as toArray() wrote it.
	 *
	 * @return self The selection.
	 *
	 * @throws ReindexRefusedException When the stored form is not a selection.
	 */
	public static function fromArray(array $stored): self {
		$kind = ($stored['kind'] ?? null);
		if (in_array($kind, [self::KIND_SCHEMA, self::KIND_FILTER, self::KIND_IDS], true) === false) {
			throw new ReindexRefusedException(message: 'The stored reindex selection names no known kind.');
		}

		$filters = ($stored['filters'] ?? []);
		$ids = ($stored['ids'] ?? []);

		return new self(
			kind: $kind,
			registerId: self::positiveIntFrom(value: ($stored['register'] ?? null)),
			schemaId: self::positiveIntFrom(value: ($stored['schema'] ?? null)),
			filters: is_array($filters) === true ? $filters : [],
			ids: is_array($ids) === true ? array_values(array_map(static fn ($id): string => (string)$id, $ids)) : []
		);

	}//end fromArray()

	/**
	 * The selection in the shape the bulk selection resolver reads.
	 *
	 * @return BulkJobSelection The id list or the query, scoped to the register and schema.
	 */
	public function bulkSelection(): BulkJobSelection {
		if ($this->kind === self::KIND_IDS) {
			return new BulkJobSelection(criteria: ['ids' => $this->ids], registerId: null, schemaId: null);
		}

		return new BulkJobSelection(criteria: ['query' => $this->filters], registerId: $this->registerId, schemaId: $this->schemaId);

	}//end bulkSelection()

	/**
	 * The ids of an id selection: a non-empty list of non-empty ids, deduplicated.
	 *
	 * @param mixed $value The `ids` parameter.
	 *
	 * @return array<int, string> The ids.
	 *
	 * @throws ReindexRefusedException When the value is not such a list.
	 */
	private static function idsFrom(mixed $value): array {
		if (is_array($value) === false || $value === []) {
			throw new ReindexRefusedException(message: 'The ids are a non-empty list of object ids.');
		}

		$ids = [];
		foreach ($value as $id) {
			if ((is_string($id) === false && is_int($id) === false) || trim((string)$id) === '') {
				throw new ReindexRefusedException(message: 'Every id in the list is a non-empty object id.');
			}

			$ids[] = trim((string)$id);
		}

		return array_values(array_unique($ids));

	}//end idsFrom()

	/**
	 * A positive integer id, or null when the value is none.
	 *
	 * @param mixed $value The parameter.
	 *
	 * @return integer|null The id.
	 */
	private static function positiveIntFrom(mixed $value): ?int {
		if (is_int($value) === true && $value > 0) {
			return $value;
		}

		if (is_string($value) === true && ctype_digit($value) === true && (int)$value > 0) {
			return (int)$value;
		}

		return null;

	}//end positiveIntFrom()
}//end class
