<?php

/**
 * OpenRegister Example Set History Row Kinds
 *
 * The writers of the history an example set gives its objects: exports, the archival setting, audit entries and timeline entries.
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

use OCA\OpenRegister\Notifications\Service\ExampleSet\ExampleSetTimelineRowsInterface;

/**
 * The writers of the history of a set's objects, bundled for the engine kinds.
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-declare-saved-views-for-the-sets-own-accounts
 */
final readonly class ExampleSetHistoryRowKinds {
	/**
	 * Bundle the writers; a writer left out refuses its rows.
	 *
	 * @param ExampleSetExportRuns|null            $exportRuns      Writes the exports a fragment gives its accounts; null refuses them.
	 * @param ExampleSetArchival|null              $archival        Writes the destruction-list keys of the archival setting; null refuses them.
	 * @param ExampleSetAuditEntries|null          $auditEntries    Writes the history a fragment gives its objects; null refuses it.
	 * @param ExampleSetTimelineRowsInterface|null $timelineEntries Writes the timeline a fragment gives its objects; null refuses it.
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-declare-saved-views-for-the-sets-own-accounts
	 */
	public function __construct(
		public ?ExampleSetExportRuns $exportRuns=null,
		public ?ExampleSetArchival $archival=null,
		public ?ExampleSetAuditEntries $auditEntries=null,
		public ?ExampleSetTimelineRowsInterface $timelineEntries=null,
	) {
	}//end __construct()

	/**
	 * The writers by fragment part; null where this OpenRegister has none.
	 *
	 * @return array<string, ExampleSetEngineRows|null>
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-declare-saved-views-for-the-sets-own-accounts
	 */
	public function writers(): array {
		return [
			'exportRuns'      => $this->exportRuns,
			'archival'        => $this->archival,
			'auditEntries'    => $this->auditEntries,
			'timelineEntries' => $this->timelineEntries?->rows(),
		];
	}//end writers()
}//end class
