<?php

/**
 * ArchivalNominationServiceInterface: what callers outside the component use of ArchivalNominationService.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Archival
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Archival;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;

/**
 * The archival nomination the archival endpoints run, and the statuses it answers with.
 *
 * Declares only what callers outside the component use; the rest of the
 * class stays its own.
 */
interface ArchivalNominationServiceInterface {

	/**
	 * The nomination was derived and written.
	 */
	public const STATUS_NOMINATED = 'nominated';

	/**
	 * Nothing could decide this record's archival future. Reported, never silent.
	 */
	public const STATUS_UNNOMINATABLE = 'unnominatable';

	/**
	 * The schema does not ask for archiving at all.
	 */
	public const STATUS_NOT_APPLICABLE = 'not_applicable';

	/**
	 * Derive this object's archival future and write it down.
	 *
	 * The object is mutated and returned; persisting it is the caller's job, so
	 * a nomination taken during a write joins that write rather than racing it.
	 *
	 * The trigger written on the block is `closure`: the record reached the end of its business use.
	 *
	 * @param ObjectEntity $object The record whose business use has ended.
	 * @param Schema       $schema Its schema, holding the archive block.
	 *
	 * @return array<string, mixed> The nomination block that was written.
	 *
	 * @spec openspec/changes/archiving-as-a-process-with-sign-off/specs/retention-management/spec.md
	 */
	public function nominate(ObjectEntity $object, Schema $schema): array;

	/**
	 * Derive this object's archival future again, on request, and write it down.
	 *
	 * The trigger written on the block is `recompute`, with who asked and why.
	 *
	 * @param ObjectEntity $object The record.
	 * @param Schema       $schema Its schema, holding the archive block.
	 * @param string|null  $actor  Who asked.
	 * @param string|null  $reason Why they asked.
	 *
	 * @return array<string, mixed> The nomination block that was written.
	 *
	 * @spec openspec/changes/archiving-as-a-process-with-sign-off/specs/retention-management/spec.md
	 */
	public function recompute(ObjectEntity $object, Schema $schema, ?string $actor, ?string $reason): array;
	/**
	 * Whether the lifecycle value the object just reached is one its schema calls an end.
	 *
	 * @param Schema $schema The object's schema.
	 * @param string $state  The lifecycle value the object just reached.
	 *
	 * @return bool True when the schema calls this state an end.
	 *
	 * @spec openspec/changes/archiving-as-a-process-with-sign-off/specs/retention-management/spec.md
	 * @spec openspec/changes/archiving-as-a-process-with-sign-off/specs/object-lifecycle/spec.md
	 */
	public function isTerminalState(Schema $schema, string $state): bool;
}//end interface
