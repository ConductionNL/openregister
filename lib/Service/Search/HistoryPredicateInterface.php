<?php

/**
 * HistoryPredicateInterface: the history filters a search query carries, as a request handler reads them.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Search
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/search-over-history-and-an-administered-dictionary/specs/zoeken-filteren/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Search;

/**
 * The history filters a search query carries, as a request handler reads them.
 *
 * Callers outside lib/Service type against this; {@see SearchGrammar::historyPredicate()} reads it.
 */
interface HistoryPredicateInterface {
	/**
	 * Whether the query carries any history filter that read.
	 *
	 * @return bool True when at least one filter read.
	 *
	 * @spec openspec/changes/search-over-history-and-an-administered-dictionary/specs/zoeken-filteren/spec.md
	 */
	public function narrows(): bool;

	/**
	 * Every property this predicate names, in query order.
	 *
	 * @return string[] The property names.
	 *
	 * @psalm-return list<string>
	 *
	 * @spec openspec/changes/search-over-history-and-an-administered-dictionary/specs/zoeken-filteren/spec.md
	 */
	public function properties(): array;

	/**
	 * The refusal this predicate earns against the projected properties.
	 *
	 * @param string[] $projectedProperties Properties the schemas declare as lifecycle fields.
	 *
	 * @return string|null The refusal, or null.
	 *
	 * @spec openspec/changes/search-over-history-and-an-administered-dictionary/specs/zoeken-filteren/spec.md
	 */
	public function refusalFor(array $projectedProperties): ?string;
}//end interface
