<?php

/**
 * OpenRegister CrossSchemaTarget
 *
 * Which schema a named aggregation counts: its own, or a related one.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Search\Service\Aggregation
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/aggregation-dialect-repair/specs/aggregation-api/spec.md#requirement-the-named-annotation-aggregation-surface-must-validate-filtergroupby-fields-against-the-schema-that-owns-them
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Search\Service\Aggregation;

/**
 * Reads a `schema` key that names another schema as the spec's `from`.
 *
 * Apps declare a count over a related schema in two spellings: `from`, which
 * the validator and the runner have always read, and `schema`, which
 * decidesk's Meeting uses (`{"metric": "count", "schema": "Participant",
 * "filter": {"governanceBody": "@self.governanceBody"}}`). The second was
 * read by neither: the validator checked `governanceBody` against Meeting's
 * own properties and discarded the annotation, and the runner would have
 * counted Meetings.
 *
 * A `schema` key that names the declaring schema itself is NOT a cross-schema
 * reference. Shipped annotations do that (`dataSubjectRequest` declares
 * `"schema": "dataSubjectRequest"`), and they keep counting their own schema
 * under its own read gate, exactly as before. Names compare case-insensitively
 * against the declaring schema's slug and title, because annotations name a
 * schema by either.
 *
 * Validation and execution both go through here, so the two cannot disagree
 * about which schema a spec counts.
 *
 * @spec openspec/changes/aggregation-dialect-repair/specs/aggregation-api/spec.md#requirement-the-named-annotation-aggregation-surface-must-validate-filtergroupby-fields-against-the-schema-that-owns-them
 */
final class CrossSchemaTarget {

	/**
	 * The spec with `from` set from a `schema` key that names another schema.
	 *
	 * A spec that already declares `from`, or has no `schema` key, comes back
	 * unchanged. So does one whose `schema` names the declaring schema, and
	 * one checked without knowing the declaring schema's names: without them
	 * a self-reference cannot be told apart, and the intra-schema reading is
	 * the one that was always applied.
	 *
	 * @param array<string, mixed> $spec     The declared aggregation spec.
	 * @param array<int, mixed>    $ownNames The declaring schema's slug and title.
	 *
	 * @return array<string, mixed> The spec, with `from` when it counts another schema.
	 *
	 * @spec openspec/changes/aggregation-dialect-repair/specs/aggregation-api/spec.md#requirement-the-named-annotation-aggregation-surface-must-validate-filtergroupby-fields-against-the-schema-that-owns-them
	 */
	public function resolve(array $spec, array $ownNames): array {
		if (array_key_exists('from', $spec) === true) {
			return $spec;
		}

		$target = ($spec['schema'] ?? null);
		if (is_string($target) === false || trim($target) === '') {
			return $spec;
		}

		$own = array_values(
			array_filter(
				array_map(static fn (mixed $name): string => strtolower(trim((string) $name)), array_filter($ownNames, 'is_scalar')),
				static fn (string $name): bool => $name !== ''
			)
		);
		if ($own === [] || in_array(strtolower(trim($target)), $own, true) === true) {
			return $spec;
		}

		$spec['from'] = trim($target);
		return $spec;
	}//end resolve()
}//end class
