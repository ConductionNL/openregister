<?php

/**
 * OpenRegister SchemaAttribution
 *
 * The pure decision layer of the shared-schema repair: which schemas are shared,
 * which register's configuration matches the entity that is shared, and what the
 * operator's `--keep` overrides mean.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\SharedSchema
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\SharedSchema;

use RuntimeException;

/**
 * Decide who owns a shared schema.
 *
 * Deliberately dependency-free. Every rule this repair turns on — detection,
 * matching, the refusal to guess — lives here and can therefore be tested
 * exhaustively without a database, a Nextcloud server or an app on disk. The
 * classes that surround it only fetch the inputs and carry out the verdict.
 *
 * @spec openspec/changes/dedupe-shared-schemas/proposal.md
 */
class SchemaAttribution {

	/**
	 * Exactly one referencing register's configuration matches the entity.
	 *
	 * @var string
	 */
	public const STATUS_ONE_MATCH = 'one-match';

	/**
	 * No referencing register's configuration matches the entity.
	 *
	 * @var string
	 */
	public const STATUS_NO_MATCH = 'no-match';

	/**
	 * Several referencing registers' configurations match the entity.
	 *
	 * @var string
	 */
	public const STATUS_MULTI_MATCH = 'multi-match';

	/**
	 * Invert a register->schemas map into the schemas shared by several registers.
	 *
	 * Ids are normalised because the stored list may hold them as ints or as
	 * strings depending on which import era wrote it, and a schema referenced as
	 * `"74"` by one register and `74` by another is still shared.
	 *
	 * @param array<int, mixed> $registerSchemas registerId => stored schema id list.
	 *
	 * @return array<int, int[]> schemaId => the register ids referencing it, ascending.
	 *         Only schemas with more than one referencing register are returned.
	 *
	 * @spec openspec/changes/dedupe-shared-schemas/proposal.md
	 */
	public function indexShared(array $registerSchemas): array {
		$index = [];
		foreach ($registerSchemas as $registerId => $schemaIds) {
			foreach ($this->normaliseIds(candidates: $schemaIds) as $schemaId) {
				$index[$schemaId][(int)$registerId] = true;
			}
		}

		$shared = [];
		foreach ($index as $schemaId => $registerIds) {
			if (count($registerIds) < 2) {
				continue;
			}

			$ids = array_keys($registerIds);
			sort($ids);
			$shared[$schemaId] = $ids;
		}

		ksort($shared);

		return $shared;
	}//end indexShared()

	/**
	 * Reduce a schema definition to the shape attribution compares on.
	 *
	 * Only the property NAMES and the required list are used. Comparing whole
	 * property bodies would be worse than useless: the import path stamps
	 * defaults, folds `$ref`s and normalises casing, so a definition that came
	 * from the very configuration under test still would not be byte-equal to the
	 * stored entity, and every schema would land in `no-match`. The name set is
	 * what the pre-fix overwrite actually destroyed — the observed case lost
	 * `billingCategory`, `hours` and `client` from pipelinq's `timeEntry` — so it
	 * is the evidence that discriminates.
	 *
	 * @param array<string, mixed> $definition A schema definition or a serialised entity.
	 *
	 * @return array{properties: string[], required: string[]} The normalised signature.
	 *
	 * @spec openspec/changes/dedupe-shared-schemas/proposal.md
	 */
	public function signature(array $definition): array {
		$properties = ($definition['properties'] ?? []);
		$names      = [];
		if (is_array($properties) === true) {
			foreach (array_keys($properties) as $name) {
				$names[] = strtolower((string)$name);
			}
		}

		$names = array_values(array_unique($names));
		sort($names);

		$required = ($definition['required'] ?? []);
		$fields   = [];
		if (is_array($required) === true) {
			foreach ($required as $field) {
				if (is_scalar($field) === true) {
					$fields[] = strtolower((string)$field);
				}
			}
		}

		$fields = array_values(array_unique($fields));
		sort($fields);

		return ['properties' => $names, 'required' => $fields];
	}//end signature()

	/**
	 * Decide which referencing register owns the current entity content.
	 *
	 * @param array<int, mixed> $candidates registerId => that register's configured
	 *                                      definition for this slug, or null when it has none.
	 * @param array<string, mixed> $entity  The current schema entity content.
	 *
	 * @return array{status: string, owner: int|null, matches: int[]} The verdict. `owner` is
	 *         set only for {@see self::STATUS_ONE_MATCH}.
	 *
	 * @spec openspec/changes/dedupe-shared-schemas/proposal.md
	 */
	public function classify(array $candidates, array $entity): array {
		$target  = $this->signature(definition: $entity);
		$matches = [];

		foreach ($candidates as $registerId => $definition) {
			if (is_array($definition) === false) {
				continue;
			}

			if ($this->signature(definition: $definition) === $target) {
				$matches[] = (int)$registerId;
			}
		}

		sort($matches);

		if (count($matches) === 1) {
			return ['status' => self::STATUS_ONE_MATCH, 'owner' => $matches[0], 'matches' => $matches];
		}

		if ($matches === []) {
			return ['status' => self::STATUS_NO_MATCH, 'owner' => null, 'matches' => []];
		}

		return ['status' => self::STATUS_MULTI_MATCH, 'owner' => null, 'matches' => $matches];
	}//end classify()

	/**
	 * Parse the repeatable `--keep` option.
	 *
	 * Two forms are accepted: `--keep <schemaId>:<registerId>` pins one schema,
	 * and a bare `--keep <registerId>` applies to every schema attribution could
	 * not settle. The per-schema form always wins, so a broad override cannot
	 * silently outrank a specific decision.
	 *
	 * @param array<int, mixed> $raw The raw option values.
	 *
	 * @return array{perSchema: array<int,int>, global: int|null} The parsed overrides.
	 *
	 * @throws RuntimeException When a value is not a positive id or id pair.
	 *
	 * @spec openspec/changes/dedupe-shared-schemas/proposal.md
	 */
	public function parseKeep(array $raw): array {
		$perSchema = [];
		$global    = null;

		foreach ($raw as $value) {
			$value = trim((string)$value);
			if ($value === '') {
				continue;
			}

			if (str_contains($value, ':') === false) {
				$global = $this->positiveId(value: $value, option: $value);
				continue;
			}

			[$schemaPart, $registerPart] = explode(':', $value, 2);
			$perSchema[$this->positiveId(value: $schemaPart, option: $value)] = $this->positiveId(
				value: $registerPart,
				option: $value
			);
		}

		return ['perSchema' => $perSchema, 'global' => $global];
	}//end parseKeep()

	/**
	 * Apply the `--keep` overrides on top of an attribution verdict.
	 *
	 * An override is honoured only when it names a register that actually
	 * references the schema. Pointing the repair at an unrelated register would
	 * relink every referencing register onto a fresh entity for no reason, which
	 * is a bigger change than the one the operator asked for.
	 *
	 * @param array<string, mixed> $verdict  The attribution verdict.
	 * @param int               $schemaId    The shared schema id.
	 * @param int[]             $registerIds The referencing registers.
	 * @param array<string, mixed> $keep     The parsed overrides.
	 *
	 * @return array{owner: int|null, source: string} The resolved owner and where it came from.
	 *
	 * @spec openspec/changes/dedupe-shared-schemas/proposal.md
	 */
	public function resolveOwner(array $verdict, int $schemaId, array $registerIds, array $keep): array {
		$pinned = ($keep['perSchema'][$schemaId] ?? null);
		if ($pinned !== null && in_array($pinned, $registerIds, true) === true) {
			return ['owner' => $pinned, 'source' => 'keep'];
		}

		if (($verdict['status'] ?? '') === self::STATUS_ONE_MATCH) {
			return ['owner' => $verdict['owner'], 'source' => 'configuration'];
		}

		$fallback = ($keep['global'] ?? null);
		if ($fallback !== null && in_array($fallback, $registerIds, true) === true) {
			return ['owner' => $fallback, 'source' => 'keep-global'];
		}

		return ['owner' => null, 'source' => 'unattributed'];
	}//end resolveOwner()

	/**
	 * Replace one schema id in a stored list, preserving order and the other entries.
	 *
	 * The remaining entries are copied VERBATIM for the reason
	 * {@see \OCA\OpenRegister\Db\Register::addSchemaId()} gives: a normalising
	 * rewrite would silently drop a non-numeric legacy entry, and that is data
	 * loss rather than cleanup.
	 *
	 * @param array<int, mixed> $schemas The stored schemas list.
	 * @param int               $oldId   The id to replace.
	 * @param int               $newId   The id to put in its place.
	 *
	 * @return array<int, mixed> The rewritten list.
	 *
	 * @spec openspec/changes/dedupe-shared-schemas/proposal.md
	 */
	public function replaceSchemaId(array $schemas, int $oldId, int $newId): array {
		$result = [];
		foreach ($schemas as $entry) {
			if (is_numeric($entry) === true && (int)$entry === $oldId) {
				$result[] = $newId;
				continue;
			}

			$result[] = $entry;
		}

		return array_values($result);
	}//end replaceSchemaId()

	/**
	 * Parse a positive integer id out of an option value.
	 *
	 * @param string $value  The raw value.
	 * @param string $option The whole option, for the error message.
	 *
	 * @return int The id.
	 *
	 * @throws RuntimeException When the value is not a positive integer.
	 */
	private function positiveId(string $value, string $option): int {
		$value = trim($value);
		if (ctype_digit($value) === false || (int)$value < 1) {
			throw new RuntimeException(
				sprintf('--keep "%s" is not a positive id or <schemaId>:<registerId> pair.', $option)
			);
		}

		return (int)$value;
	}//end positiveId()

	/**
	 * Normalise a stored schemas value into a list of positive ints.
	 *
	 * @param mixed $candidates The stored value.
	 *
	 * @return int[] The normalised ids.
	 */
	private function normaliseIds(mixed $candidates): array {
		$ids = [];
		foreach ((array)$candidates as $candidate) {
			if (is_numeric($candidate) === true && (int)$candidate > 0) {
				$ids[] = (int)$candidate;
			}
		}

		return array_values(array_unique($ids));
	}//end normaliseIds()
}//end class
