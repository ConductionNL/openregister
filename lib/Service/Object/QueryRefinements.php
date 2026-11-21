<?php

/**
 * OpenRegister QueryRefinements
 *
 * The optional refinements of an object query: history narrowing, the search dictionary and reference resolution.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/zoeken-filteren/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

use OCA\OpenRegister\Service\Search\HistoryNarrowing;
use OCA\OpenRegister\Service\Search\SearchDictionaryProvider;

/**
 * The optional refinements of an object query: history narrowing, the search dictionary and reference resolution.
 *
 * @spec openspec/specs/zoeken-filteren/spec.md
 */
final readonly class QueryRefinements {
	/**
	 * Bundle the collaborators.
	 *
	 * @param HistoryNarrowing|null         $historyNarrowing  Resolves a history predicate to the ids the query keeps.
	 * @param SearchDictionaryProvider|null $dictionary        The administered synonym and stopword dictionary.
	 * @param SearchReferenceResolver|null  $referenceResolver Resolves a register/schema slug or uuid on a ready-made query.
	 */
	public function __construct(
		public ?HistoryNarrowing $historyNarrowing = null,
		public ?SearchDictionaryProvider $dictionary = null,
		public ?SearchReferenceResolver $referenceResolver = null,
	) {
	}//end __construct()
}//end class
