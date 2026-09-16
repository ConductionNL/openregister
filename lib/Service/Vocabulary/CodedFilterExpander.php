<?php

/**
 * OpenRegister CodedFilterExpander
 *
 * Turns `?categorie[branch]=<uri>` into the value set the query can actually
 * match: the branch root plus every narrower concept under it, bounded by
 * depth, resolved at query time (design.md D-3).
 *
 * Resolving it here rather than storing a denormalised path is the whole
 * design. A gemeente moves `kapvergunning` under a new broader term and the
 * next filter on `vergunning` follows it, with no reindex and no schema save.
 * A stored path would have gone stale at the moment of the move, and a stale
 * path is a filter that silently returns the wrong set, which is the failure
 * nobody notices because it still returns rows.
 *
 * An unresolvable branch is left as it arrived rather than expanded to
 * nothing: a filter that quietly becomes the empty set is indistinguishable
 * from a filter that matched nothing, and one of those is a bug.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Vocabulary
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/code-list-lifecycle-and-hierarchy/specs/skos-concept-registers/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Vocabulary;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use Throwable;

/**
 * Expands a branch filter into the concept values it stands for.
 */
class CodedFilterExpander {

	/**
	 * The filter key that asks for a branch rather than an exact value.
	 *
	 * @var string
	 */
	public const BRANCH_KEY = 'branch';

	/**
	 * The filter key that bounds the branch walk.
	 *
	 * @var string
	 */
	public const DEPTH_KEY = 'depth';

	/**
	 * Constructor.
	 *
	 * @param ConceptRepository $concepts Reads the scheme's concepts.
	 * @param ConceptHierarchy $hierarchy Walks broader and narrower.
	 * @param SchemaMapper $schemas Resolves the schema being queried.
	 */
	public function __construct(
		private readonly ConceptRepository $concepts,
		private readonly ConceptHierarchy $hierarchy,
		private readonly SchemaMapper $schemas,
		private readonly CodedPropertyDeclarationFactory $declarationFactory,
	) {

	}//end __construct()

	/**
	 * Expand every branch filter in `$filters` against `$schemaRef`.
	 *
	 * @param array<string,mixed> $filters The object-field filters as parsed from the request.
	 * @param int|string|array|null $schemaRef The schema being queried.
	 *
	 * @return array<string,mixed> The filters with branch filters expanded.
	 *
	 * @spec openspec/changes/code-list-lifecycle-and-hierarchy/specs/skos-concept-registers/spec.md
	 */
	public function expand(array $filters, int|string|array|null $schemaRef): array {
		if ($this->carriesBranchFilter(filters: $filters) === false) {
			return $filters;
		}

		$schema = $this->resolveSchema(schemaRef: $schemaRef);
		if ($schema === null) {
			return $filters;
		}

		$declarations = $this->declarationFactory->fromProperties(properties: ($schema->getProperties() ?? []));
		if ($declarations === []) {
			return $filters;
		}

		foreach ($filters as $property => $value) {
			$branch = $this->branchOf(value: $value);
			if ($branch === null) {
				continue;
			}

			$declaration = ($declarations[(string)$property] ?? null);
			if ($declaration === null) {
				continue;
			}

			$expanded = $this->valuesForBranch(
				branch: $branch,
				declaration: $declaration,
				depth: $this->depthOf(value: $value)
			);
			if ($expanded === []) {
				continue;
			}

			$filters[$property] = ['in' => $expanded];
		}//end foreach

		return $filters;
	}//end expand()

	/**
	 * The stored values a branch stands for, in the declaration's storage form.
	 *
	 * @param string $branch The branch root's uri.
	 * @param CodedPropertyDeclaration $declaration The property's declaration.
	 * @param integer|null $depth The requested depth bound.
	 *
	 * @return array<int,string> The stored values, empty when the branch is unresolvable.
	 *
	 * @spec openspec/changes/code-list-lifecycle-and-hierarchy/specs/skos-concept-registers/spec.md
	 */
	public function valuesForBranch(
		string $branch,
		CodedPropertyDeclaration $declaration,
		?int $depth = null,
	): array {
		$conceptsByUri = $this->concepts->conceptsOf(schemeUri: $declaration->scheme);
		if (isset($conceptsByUri[$branch]) === false) {
			return [];
		}

		$bound = $depth;
		if ($bound === null) {
			$bound = $declaration->maxDepth;
		}

		$uris = $this->hierarchy->branchUris(
			rootUri: $branch,
			conceptsByUri: $conceptsByUri,
			maxDepth: $bound
		);

		if ($declaration->store === 'uri') {
			return $uris;
		}

		$notations = [];
		foreach ($uris as $uri) {
			$notation = trim((string)(($conceptsByUri[$uri]['notation'] ?? '')));
			if ($notation !== '') {
				$notations[] = $notation;
			}
		}

		return $notations;
	}//end valuesForBranch()

	/**
	 * Whether any filter asks for a branch.
	 *
	 * Checked first so a query with no branch filter costs one loop over the
	 * filters and never touches the schema or the vocabulary register.
	 *
	 * @param array<string,mixed> $filters The object-field filters.
	 *
	 * @return boolean True when at least one filter is a branch filter.
	 */
	private function carriesBranchFilter(array $filters): bool {
		foreach ($filters as $value) {
			if ($this->branchOf(value: $value) !== null) {
				return true;
			}
		}

		return false;
	}//end carriesBranchFilter()

	/**
	 * The branch a filter value asks for, or null when it asks for none.
	 *
	 * @param mixed $value The filter value.
	 *
	 * @return string|null The branch root's uri.
	 */
	private function branchOf(mixed $value): ?string {
		if (is_array($value) === false) {
			return null;
		}

		$branch = ($value[self::BRANCH_KEY] ?? null);
		if (is_string($branch) === false) {
			return null;
		}

		$branch = trim($branch);

		if ($branch === '') {
			return null;
		}

		return $branch;
	}//end branchOf()

	/**
	 * The depth bound a filter value asks for, or null.
	 *
	 * @param mixed $value The filter value.
	 *
	 * @return integer|null The requested depth.
	 */
	private function depthOf(mixed $value): ?int {
		if (is_array($value) === false) {
			return null;
		}

		$depth = ($value[self::DEPTH_KEY] ?? null);
		if (is_numeric($depth) === false) {
			return null;
		}

		return (int)$depth;
	}//end depthOf()

	/**
	 * Resolve the single schema a query names, or null.
	 *
	 * A cross-schema query names several, and a branch filter over several
	 * schemas is not something this expander guesses at: it leaves the filter
	 * alone rather than picking one schema's declaration and applying it to
	 * all of them.
	 *
	 * @param int|string|array|null $schemaRef The schema reference from the query.
	 *
	 * @return Schema|null The resolved schema.
	 */
	private function resolveSchema(int|string|array|null $schemaRef): ?Schema {
		if (is_array($schemaRef) === true) {
			if (count($schemaRef) !== 1) {
				return null;
			}

			$schemaRef = reset($schemaRef);
		}

		if ($schemaRef === null || $schemaRef === '') {
			return null;
		}

		try {
			return $this->schemas->find(id: $schemaRef, _rbac: false, _multitenancy: false);
		} catch (Throwable $missing) {
			return null;
		}
	}//end resolveSchema()
}//end class
