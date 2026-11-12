<?php

/**
 * StateHistoryProjectorInterface: the part of StateHistoryProjector that the objects controller uses through its governance bundle.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\History
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

namespace OCA\OpenRegister\Service\History;

/**
 * The methods of StateHistoryProjector that callers outside its component use.
 * Callers depend on this interface, not on the class.
 */
interface StateHistoryProjectorInterface {

	/**
	 * The properties a history predicate can be answered about.
	 *
	 * Read from the schemas' own declarations, never from the rows present in
	 * the projection: an empty projection must still be able to say that
	 * `status` is a property with history, and a key that somehow got written
	 * must not become filterable because it is there.
	 *
	 * @return string[] The declared lifecycle fields, distinct.
	 *
	 * @psalm-return list<string>
	 *
	 * @spec openspec/changes/search-over-history-and-an-administered-dictionary/specs/zoeken-filteren/spec.md
	 */
	public function projectedProperties(): array;
}//end interface
