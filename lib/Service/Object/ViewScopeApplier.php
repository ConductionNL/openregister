<?php

/**
 * ViewScopeApplier - merging a view's stored query into a search
 *
 * Extracted from SearchQueryHandler, where it was the only user of ViewMapper.
 * It moved because for a view-scoped access link the view's filter is the WHOLE
 * bound on what an anonymous reader may see, which makes this a security rule
 * worth reading and testing in one place rather than inline in a query builder.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Handler
 * @package  OCA\OpenRegister\Service\Object
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/zoeken-filteren/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

use Exception;
use OCA\OpenRegister\Db\ViewMapper;
use Psr\Log\LoggerInterface;

/**
 * Applies the filters a view stores onto a search query.
 */
class ViewScopeApplier {
	/**
	 * The stored-query keys that actually constrain a search.
	 *
	 * These MUST stay in step with the filters {@see self::apply()} knows how to
	 * merge. A view holding none of them merges nothing, and for a caller whose
	 * only bound is the view, "applied successfully" and "no bound at all" are
	 * the same outcome.
	 *
	 * @var array<int, string>
	 */
	private const NARROWING_FILTERS = [
		'registers',
		'schemas',
		'searchTerms',
	];

	/**
	 * Constructor.
	 *
	 * @param ViewMapper      $viewMapper Mapper for view operations.
	 * @param LoggerInterface $logger     Logger for debug and failure reporting.
	 */
	public function __construct(
		private readonly ViewMapper $viewMapper,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Apply view filters to a query
	 *
	 * Converts view definitions into query parameters by merging view->query into the base query.
	 * Supports multiple views - their filters are combined (OR logic for same field, AND for different fields).
	 *
	 * ## `$_viewScopeRequired` - when the view IS the bound
	 *
	 * For an ordinary authenticated search a view is a convenience: the caller
	 * is already scoped by RBAC and by its organisation, so a view that cannot
	 * be resolved may be logged and skipped without widening anything the caller
	 * could not already see. That is the default, and it stays.
	 *
	 * A trusted caller is a different case. When a caller has switched RBAC and
	 * multitenancy OFF because it carries its own authorization - a published
	 * access link naming one view - the view filter is the ONLY bound left on
	 * the query. Skipping it there does not narrow less, it removes the bound
	 * entirely and answers with arbitrary objects from any organisation. Such a
	 * caller passes `$_viewScopeRequired: true`, and then every way of failing to
	 * apply the view throws instead of continuing: an unresolvable view, and a
	 * view whose query narrows nothing at all. The caller turns that into "this
	 * link no longer resolves", which is the correct answer.
	 *
	 * The view is resolved RBAC- and organisation-exempt in that mode for the
	 * same reason the caller already reads its schema that way: there is no
	 * session to judge, and the link is the authorization. What it may NOT do is
	 * fail open.
	 *
	 * @param array<string, mixed> $query Base query parameters.
	 * @param array<int|string> $viewIds View IDs to apply (can be int or string IDs).
	 * @param bool $_viewScopeRequired Whether the view filter is load-bearing for this
	 *                                 caller, making any failure to apply it fatal.
	 *
	 * @return array<string, mixed> Query with view filters applied
	 *
	 * @throws Exception When `$_viewScopeRequired` is true and a view cannot be applied.
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) Complex view merging with multiple filter types
	 * @SuppressWarnings(PHPMD.NPathComplexity)      Multiple view filter paths for registers, schemas, and search terms
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)  The flag is the fail-closed contract, not a mode switch
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md
	 */
	public function apply(array $query, array $viewIds, bool $_viewScopeRequired = false): array {
		if (empty($viewIds) === true) {
			if ($_viewScopeRequired === true) {
				// A caller whose only bound is the view must not be handed an
				// unbounded query because the view list arrived empty.
				throw new Exception('Refusing a view-scoped search without a view to scope it by.');
			}

			return $query;
		}

		$this->logger->debug(
			message: '[ViewScopeApplier] Applying views to query',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'viewIds' => $viewIds,
				'originalQuery' => array_keys($query),
			]
		);

		foreach ($viewIds as $viewId) {
			try {
				$query = $this->applyOne(
					query: $query,
					viewId: $viewId,
					_viewScopeRequired: $_viewScopeRequired
				);
			} catch (Exception $e) {
				$this->logger->warning(
					message: '[ViewScopeApplier] Failed to apply view',
					context: [
						'file' => __FILE__,
						'line' => __LINE__,
						'viewId' => $viewId,
						'error' => $e->getMessage(),
					]
				);

				if ($_viewScopeRequired === true) {
					// Fail closed: for this caller the view was the whole bound,
					// so swallowing the failure would answer with everything.
					throw $e;
				}
			}//end try
		}//end foreach

		return $query;
	}//end apply()

	/**
	 * Merge one view's stored query into the search query.
	 *
	 * @param array<string, mixed> $query Base query parameters.
	 * @param int|string $viewId The view to resolve and merge.
	 * @param bool $_viewScopeRequired Whether failing to apply the view is fatal.
	 *
	 * @return array<string, mixed> Query with this view's filters applied.
	 *
	 * @throws Exception When the view cannot be resolved, or narrows nothing while required.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) The flag is the fail-closed contract, not a mode switch
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md
	 */
	private function applyOne(array $query, int|string $viewId, bool $_viewScopeRequired): array {
		$view = $this->viewMapper->find(
			$viewId,
			_rbac: ($_viewScopeRequired === false),
			_multitenancy: ($_viewScopeRequired === false)
		);
		$viewQuery = $view->getQuery();

		if ($_viewScopeRequired === true && $this->narrows(viewQuery: $viewQuery) === false) {
			// A view that filters on nothing is not a bound. Applying it
			// would leave the query exactly as wide as it arrived.
			throw new Exception(
				'View "' . (string)$viewId . '" carries no register, schema or search-term filter.'
			);
		}

		// Apply registers filter using @self metadata (format MagicMapper understands).
		if (empty($viewQuery['registers']) === false) {
			$query['@self']['register'] = $this->mergeIds(
				existing: ($query['@self']['register'] ?? null),
				additional: $viewQuery['registers']
			);
		}

		// Apply schemas filter using @self metadata (format MagicMapper understands).
		if (empty($viewQuery['schemas']) === false) {
			$query['@self']['schema'] = $this->mergeIds(
				existing: ($query['@self']['schema'] ?? null),
				additional: $viewQuery['schemas']
			);
		}

		// Apply search terms.
		if (empty($viewQuery['searchTerms']) === false) {
			$searchTerms = $viewQuery['searchTerms'];
			if (is_array($viewQuery['searchTerms']) === true) {
				$searchTerms = implode(' ', $viewQuery['searchTerms']);
			}

			// Merge with existing search if present.
			//
			// This previously assigned $query['_search'] FIRST and then
			// appended $searchTerms to it, so the isset() guard could only
			// ever see the value just written. Two things went wrong: the
			// caller's own `_search` was discarded (the merge this comment
			// describes never happened), and the view's terms were appended
			// to themselves, producing "foo foo". Mirrors the `schemas`
			// merge above: read what is there, then combine.
			$existingSearch = ($query['_search'] ?? '');
			$searchPrefix = '';
			if (is_string($existingSearch) === true && $existingSearch !== '') {
				$searchPrefix = $existingSearch . ' ';
			}

			$query['_search'] = $searchPrefix . $searchTerms;
		}//end if

		$this->logger->debug(
			message: '[ViewScopeApplier] Applied view to query',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'viewId' => $viewId,
				'registers' => $viewQuery['registers'] ?? [],
				'schemas' => $viewQuery['schemas'] ?? [],
				'hasSearchTerms' => empty($viewQuery['searchTerms']) === false,
			]
		);

		return $query;
	}//end applyOne()

	/**
	 * Combine an already-present id bound with the one a view carries.
	 *
	 * @param mixed $existing What the query already holds, if anything.
	 * @param array<int, mixed> $additional The ids the view names.
	 *
	 * @return array<int, mixed> The combined, de-duplicated, list-indexed set.
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md
	 */
	private function mergeIds(mixed $existing, array $additional): array {
		$existingIds = [];
		if (is_array($existing) === true) {
			$existingIds = $existing;
		} elseif ($existing !== null && $existing !== false) {
			$existingIds = [$existing];
		}

		// Kept a LIST on purpose: array_unique() preserves the original keys, and
		// a gapped array reaches MagicMapper as something its single-versus-many
		// test cannot read as one id.
		return array_values(array_unique(array_merge($existingIds, $additional)));
	}//end mergeIds()

	/**
	 * Whether a view's stored query narrows a search at all.
	 *
	 * @param array<string, mixed>|null $viewQuery The view's stored query.
	 *
	 * @return bool True when applying the view narrows the search.
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md
	 */
	public function narrows(?array $viewQuery): bool {
		if ($viewQuery === null) {
			return false;
		}

		foreach (self::NARROWING_FILTERS as $key) {
			if (empty($viewQuery[$key]) === false) {
				return true;
			}
		}

		return false;
	}//end narrows()
}//end class
