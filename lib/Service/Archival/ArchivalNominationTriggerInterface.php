<?php

/**
 * ArchivalNominationTriggerInterface: the nomination a lifecycle transition triggers.
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
 * Whether a reached state ends business use, and the nomination taken when it does.
 *
 * Bound to ArchivalNominationService in ContractBindings.
 */
interface ArchivalNominationTriggerInterface {
	/**
	 * Derive this object's archival future and write it down.
	 *
	 * @param ObjectEntity $object  The record whose business use has ended.
	 * @param Schema       $schema  Its schema, holding the archive block.
	 * @param string       $trigger What caused this: `closure` or `recompute`.
	 * @param string|null  $actor   Who asked, for a recomputation.
	 * @param string|null  $reason  Why they asked, for a recomputation.
	 *
	 * @return array<string, mixed> The nomination block that was written.
	 *
	 * @spec openspec/changes/archiving-as-a-process-with-sign-off/specs/retention-management/spec.md
	 */
	public function nominate(ObjectEntity $object, Schema $schema, string $trigger, ?string $actor = null, ?string $reason = null): array;

	/**
	 * Whether the schema calls this lifecycle state an end of business use.
	 *
	 * @param Schema $schema The object's schema.
	 * @param string $state  The lifecycle value the object just reached.
	 *
	 * @return bool True when the schema calls this state an end.
	 *
	 * @spec openspec/changes/archiving-as-a-process-with-sign-off/specs/retention-management/spec.md
	 */
	public function isTerminalState(Schema $schema, string $state): bool;
}//end interface
