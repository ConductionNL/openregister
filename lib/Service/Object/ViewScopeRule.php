<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * What it means for a view to actually bound a search.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

namespace OCA\OpenRegister\Service\Object;

/**
 * The rule that decides whether a view narrows a search.
 *
 * It lives in its own class because it is a rule, not a step: a view-scoped
 * access link is authorized by the link alone, so the view's filter is the
 * whole bound on what that link may read. "Does this view narrow anything" is
 * therefore a security question, and one worth being able to read, test and
 * change in one place rather than finding inline in a 1000-line query builder.
 *
 * The filters named here MUST stay in step with the ones
 * {@see SearchQueryHandler::applyViewsToQuery()} knows how to merge. A view
 * holding none of them merges nothing, and for a caller whose only bound is
 * the view, "applied successfully" and "no bound at all" are the same outcome.
 */
final class ViewScopeRule {
	/**
	 * The stored-query keys that actually constrain a search.
	 *
	 * @var array<int, string>
	 */
	private const NARROWING_FILTERS = [
		'registers',
		'schemas',
		'searchTerms',
	];

	/**
	 * Whether a view's stored query narrows a search at all.
	 *
	 * @param array<string, mixed>|null $viewQuery The view's stored query.
	 *
	 * @return bool True when applying the view narrows the search.
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
