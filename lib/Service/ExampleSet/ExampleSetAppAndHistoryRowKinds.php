<?php

/**
 * OpenRegister Example Set App And History Row Kinds
 *
 * The writers of the rows an example set puts in other apps and in the history, bundled for the engine kinds.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ExampleSet
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-declare-saved-views-for-the-sets-own-accounts
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ExampleSet;

/**
 * The writers of the rows in other apps (Deck, Calendar, Collectives, Talk) and in the history (exports, archival,
 * audit and timeline entries).
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-declare-saved-views-for-the-sets-own-accounts
 */
final readonly class ExampleSetAppAndHistoryRowKinds {
	/**
	 * Bundle the writers; a writer left out refuses its rows.
	 *
	 * @param ExampleSetAppRowKinds     $apps    Writes the Deck cards, appointments, Collectives pages and Talk conversations.
	 * @param ExampleSetHistoryRowKinds $history Writes the exports, the archival setting, audit and timeline entries.
	 *
	 * @return void
	 */
	public function __construct(
		public ExampleSetAppRowKinds $apps=new ExampleSetAppRowKinds(),
		public ExampleSetHistoryRowKinds $history=new ExampleSetHistoryRowKinds(),
	) {
	}//end __construct()

	/**
	 * The writers by part, null where a writer is left out.
	 *
	 * @return array<string, ExampleSetEngineRows|null>
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-declare-saved-views-for-the-sets-own-accounts
	 */
	public function writers(): array {
		return ($this->apps->writers() + $this->history->writers());
	}//end writers()
}//end class
