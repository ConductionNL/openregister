<?php

/**
 * Turns a history predicate into a candidate set the ordinary query narrows by.
 *
 * 🔴 NARROWING IS THE ONLY THING THIS DOES. The projection carries no access
 * control, so its uuids are candidates and nothing more: they are intersected
 * into the id set of the ordinary, access-filtered object query, which can only
 * ever REMOVE objects the caller could already see. Answering the page from the
 * projection instead would walk around RBAC, tenant isolation and property
 * redaction in one step, and the result would look exactly like a correct one.
 *
 * 🔑 SEVERAL FILTERS ARE AN AND. "Was ever in bezwaar AND changed status in
 * March" is the intersection, because two filters in one query bar mean both.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Search
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/search-over-history-and-an-administered-dictionary/specs/zoeken-filteren/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Search;

use OCA\OpenRegister\Db\StateHistoryMapper;
use Psr\Log\LoggerInterface;

/**
 * Resolves a history predicate to the ids a list query keeps.
 *
 * @spec openspec/changes/search-over-history-and-an-administered-dictionary/specs/zoeken-filteren/spec.md
 */
class HistoryNarrowing {

	/**
	 * Constructor.
	 *
	 * @param StateHistoryMapper $mapper The projection.
	 * @param LoggerInterface    $logger Diagnostics.
	 */
	public function __construct(
		private readonly StateHistoryMapper $mapper,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The ids a query carrying this predicate may still answer with.
	 *
	 * @param HistoryPredicate $predicate The parsed predicate.
	 * @param string[]|null    $ids       The id set the query already carries, or null.
	 *
	 * @return string[] The narrowed id set, empty when nothing survives.
	 *
	 * @psalm-return list<string>
	 *
	 * @spec openspec/changes/search-over-history-and-an-administered-dictionary/specs/zoeken-filteren/spec.md
	 */
	public function narrow(HistoryPredicate $predicate, ?array $ids = null): array {
		$candidates = null;

		foreach ($predicate->wasEver() as $property => $value) {
			$candidates = $this->intersect(
				current: $candidates,
				next: $this->mapper->findObjectUuidsEverAt(property: $property, value: $value)
			);
		}

		foreach ($predicate->changedBetween() as $property => $period) {
			$candidates = $this->intersect(
				current: $candidates,
				next: $this->mapper->findObjectUuidsChangedBetween(
					property: $property,
					after: $period['after'],
					before: $period['before']
				)
			);
		}

		if ($candidates === null) {
			// Nothing read, so nothing to narrow by. The caller checks
			// narrows() first; this is the belt on that brace.
			return ($ids ?? []);
		}

		if ($ids !== null && $ids !== []) {
			$candidates = array_values(array_intersect($ids, $candidates));
		}

		$this->logger->debug(
			'[HistoryNarrowing] History predicate narrowed the query to {count} candidates',
			['count' => count($candidates)]
		);

		return $candidates;
	}//end narrow()

	/**
	 * Intersect two candidate sets, treating "not yet set" as "everything".
	 *
	 * @param string[]|null $current The set so far, or null before the first filter.
	 * @param string[]      $next    The next filter's set.
	 *
	 * @return string[] The intersection.
	 *
	 * @psalm-return list<string>
	 */
	private function intersect(?array $current, array $next): array {
		if ($current === null) {
			return array_values(array_unique($next));
		}

		return array_values(array_intersect($current, $next));
	}//end intersect()
}//end class
