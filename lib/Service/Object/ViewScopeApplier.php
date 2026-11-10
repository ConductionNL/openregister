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
use OCA\OpenRegister\Search\Db\ViewMapperInterface;
use Psr\Log\LoggerInterface;

/**
 * Applies the filters a view stores onto a search query.
 */
class ViewScopeApplier {
	/**
	 * The stored-query keys that can BOUND a search, as opposed to merely
	 * narrowing one.
	 *
	 * `searchTerms` is deliberately absent. It narrows - it is merged into
	 * `_search` - but it sets no register or schema, so a searchTerms-only view
	 * reaches the mapper with no register/schema context at all. Today that is
	 * inert rather than leaking: MagicMapper takes its no-context branch, logs
	 * and returns []. "Correct outcome reached by accident" is not the contract
	 * this class advertises, and the accident is one register-resolution change
	 * away from becoming a real unbounded read.
	 *
	 * These MUST stay in step with {@see self::SELF_KEYS}.
	 *
	 * @var array<int, string>
	 */
	private const BOUNDING_FILTERS = [
		'registers',
		'schemas',
	];

	/**
	 * Where each stored filter goes under `@self`.
	 *
	 * @var array<string, string>
	 */
	private const SELF_KEYS = [
		'registers' => 'register',
		'schemas' => 'schema',
	];

	/**
	 * Constructor.
	 *
	 * @param ViewMapperInterface      $viewMapper Mapper for view operations.
	 * @param LoggerInterface $logger     Logger for debug and failure reporting.
	 */
	public function __construct(
		private readonly ViewMapperInterface $viewMapper,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Apply view filters to a query
	 *
	 * Converts view definitions into query parameters by merging view->query into the base query.
	 * Supports multiple views - their filters are combined (OR logic for same field, AND for different fields).
	 *
	 * ## When the view IS the bound
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
	 * caller uses {@see self::applyRequired()}, and then every way of failing to
	 * apply the view throws instead of continuing: an unresolvable view, and a
	 * view whose query bounds nothing at all. The caller turns that into "this
	 * link no longer resolves", which is the correct answer.
	 *
	 * The view is resolved RBAC- and organisation-exempt in that mode for the
	 * same reason the caller already reads its schema that way: there is no
	 * session to judge, and the link is the authorization. What it may NOT do is
	 * fail open.
	 *
	 * @param array<string, mixed> $query Base query parameters.
	 * @param array<int|string> $viewIds View IDs to apply (can be int or string IDs).
	 *
	 * @return array<string, mixed> Query with view filters applied
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md
	 */
	public function apply(array $query, array $viewIds): array {
		if (empty($viewIds) === true) {
			return $query;
		}

		$run = new ViewScopeRun(viewIds: $viewIds, required: false);
		$this->logApplying(query: $query, run: $run);

		return $this->applyEach(query: $query, run: $run);
	}//end apply()

	/**
	 * Apply view filters to a query whose only bound is the view.
	 *
	 * See {@see self::apply()} for when a caller is in this position.
	 *
	 * @param array<string, mixed> $query Base query parameters.
	 * @param array<int|string> $viewIds View IDs to apply (can be int or string IDs).
	 *
	 * @return array<string, mixed> Query with view filters applied
	 *
	 * @throws Exception When a view cannot be applied.
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md
	 */
	public function applyRequired(array $query, array $viewIds): array {
		if (empty($viewIds) === true) {
			// A caller whose only bound is the view must not be handed an
			// unbounded query because the view list arrived empty.
			throw new Exception('Refusing a view-scoped search without a view to scope it by.');
		}

		$run = new ViewScopeRun(viewIds: $viewIds, required: true);
		$this->logApplying(query: $query, run: $run);

		return $this->applyEach(query: $query, run: $run);
	}//end applyRequired()

	/**
	 * Merge every view of a run into the query.
	 *
	 * @param array<string, mixed> $query Base query parameters.
	 * @param ViewScopeRun $run The views and whether they are the only bound.
	 *
	 * @return array<string, mixed> Query with view filters applied.
	 *
	 * @throws Exception When the views are required and one cannot be applied.
	 */
	private function applyEach(array $query, ViewScopeRun $run): array {
		foreach ($run->viewIds as $viewId) {
			try {
				$viewQuery = $this->storedQuery(viewId: $viewId, run: $run);
				$query = $this->merged(query: $query, viewQuery: $viewQuery);
				$this->logApplied(viewId: $viewId, viewQuery: $viewQuery);
			} catch (Exception $e) {
				$this->logFailed(viewId: $viewId, failure: $e);

				if ($run->required === true) {
					// Fail closed: for this caller the view was the whole bound,
					// so swallowing the failure would answer with everything.
					throw $e;
				}
			}//end try
		}//end foreach

		return $query;
	}//end applyEach()

	/**
	 * The stored query of one view.
	 *
	 * @param int|string $viewId The view to resolve.
	 * @param ViewScopeRun $run The run it belongs to.
	 *
	 * @return array<string, mixed>|null The view's stored query.
	 *
	 * @throws Exception When the view cannot be resolved, or bounds nothing while required.
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md
	 */
	private function storedQuery(int|string $viewId, ViewScopeRun $run): ?array {
		$view = $this->viewMapper->find(
			$viewId,
			_rbac: ($run->required === false),
			_multitenancy: ($run->required === false)
		);
		$viewQuery = $view->getQuery();

		if ($run->required === true && $this->bounds(viewQuery: $viewQuery) === false) {
			// A view that sets no register and no schema is not a bound. Applying
			// it would leave the query exactly as wide as it arrived.
			throw new Exception(
				'View "' . (string)$viewId . '" carries no register or schema filter.'
			);
		}

		return $viewQuery;
	}//end storedQuery()

	/**
	 * Merge one view's stored query into the search query.
	 *
	 * @param array<string, mixed> $query Base query parameters.
	 * @param array<string, mixed>|null $viewQuery The view's stored query.
	 *
	 * @return array<string, mixed> Query with this view's filters applied.
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md
	 */
	private function merged(array $query, ?array $viewQuery): array {
		// Registers and schemas go under @self, the format MagicMapper understands.
		foreach (self::SELF_KEYS as $filter => $selfKey) {
			if (empty($viewQuery[$filter]) === false) {
				$query['@self'][$selfKey] = $this->mergeIds(
					existing: ($query['@self'][$selfKey] ?? null),
					additional: $viewQuery[$filter]
				);
			}
		}

		if (empty($viewQuery['searchTerms']) === false) {
			$query['_search'] = $this->mergedSearch(existing: ($query['_search'] ?? ''), terms: $viewQuery['searchTerms']);
		}

		return $query;
	}//end merged()

	/**
	 * Add a view's search terms to what the caller searched for.
	 *
	 * @param mixed $existing The query's `_search`.
	 * @param mixed $terms The view's terms, as its author stored them.
	 *
	 * @return string The combined search.
	 */
	private function mergedSearch(mixed $existing, mixed $terms): string {
		if (is_array($terms) === true) {
			$terms = implode(' ', $terms);
		}

		if (is_string($existing) === true && $existing !== '') {
			return $existing . ' ' . $terms;
		}

		return (string)$terms;
	}//end mergedSearch()

	/**
	 * Say which views are about to be applied.
	 *
	 * @param array<string, mixed> $query Base query parameters.
	 * @param ViewScopeRun $run The run.
	 *
	 * @return void
	 */
	private function logApplying(array $query, ViewScopeRun $run): void {
		$this->logger->debug(
			message: '[ViewScopeApplier] Applying views to query',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'viewIds' => $run->viewIds,
				'originalQuery' => array_keys($query),
			]
		);
	}//end logApplying()

	/**
	 * Say what one view held.
	 *
	 * @param int|string $viewId The view.
	 * @param array<string, mixed>|null $viewQuery Its stored query.
	 *
	 * @return void
	 */
	private function logApplied(int|string $viewId, ?array $viewQuery): void {
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
	}//end logApplied()

	/**
	 * Say that one view could not be applied.
	 *
	 * @param int|string $viewId The view.
	 * @param Exception $failure What went wrong.
	 *
	 * @return void
	 */
	private function logFailed(int|string $viewId, Exception $failure): void {
		$this->logger->warning(
			message: '[ViewScopeApplier] Failed to apply view',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'viewId' => $viewId,
				'error' => $failure->getMessage(),
			]
		);
	}//end logFailed()

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
	 * Whether a view's stored query BOUNDS a search.
	 *
	 * Asked only of a caller whose sole bound is the view, so the question is
	 * not "does this view change the result" but "does this view constrain what
	 * may be reached at all". See {@see self::BOUNDING_FILTERS} for why a
	 * search-term-only view answers false.
	 *
	 * @param array<string, mixed>|null $viewQuery The view's stored query.
	 *
	 * @return bool True when the view names a register or a schema.
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md
	 */
	private function bounds(?array $viewQuery): bool {
		if ($viewQuery === null) {
			return false;
		}

		foreach (self::BOUNDING_FILTERS as $key) {
			if (empty($viewQuery[$key]) === false) {
				return true;
			}
		}

		return false;
	}//end bounds()
}//end class
