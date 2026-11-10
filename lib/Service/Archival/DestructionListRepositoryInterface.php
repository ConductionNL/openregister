<?php

/**
 * DestructionListRepositoryInterface: what callers outside the component use of DestructionListRepository.
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

/**
 * The destruction lists the archival endpoints read and save.
 *
 * Declares only what callers outside the component use; the rest of the
 * class stays its own.
 */
interface DestructionListRepositoryInterface {

	/**
	 * The statuses of a list that still has review work on it.
	 *
	 * @var string[]
	 */
	public const OPEN_STATUSES = ['in_review', 'awaiting_second_approval'];

	/**
	 * One destruction list, by uuid.
	 *
	 * @param string $uuid The list's uuid.
	 *
	 * @return ObjectEntity|null The list, or null when there is no such object.
	 *
	 * @spec openspec/changes/archiving-as-a-process-with-sign-off/specs/retention-management/spec.md
	 */
	public function find(string $uuid): ?ObjectEntity;

	/**
	 * The destruction certificates stored for executed lists, newest first.
	 *
	 * DestructionExecutionJob stores one `verklaring_van_vernietiging` per
	 * executed list and writes its uuid to the list as `certificateUuid`. An
	 * executed list without a stored certificate (the save failed, or no
	 * archival register was set) is named under `missing`: a statutory record
	 * that is absent must not read as a list that never ran.
	 *
	 * @param string|null $listUuid Only this list's certificate, or null for all.
	 *
	 * @return array{results: array<int, array<string, mixed>>, missing: array<int, string>} Certificates and lists without one.
	 *
	 * @spec openspec/specs/archival-destruction-workflow/spec.md
	 */
	public function findCertificates(?string $listUuid = null): array;

	/**
	 * Every destruction list, optionally narrowed to a set of statuses.
	 *
	 * @param string[]|null $statuses Statuses to keep, or null for every list.
	 *
	 * @return ObjectEntity[] The destruction lists, empty when there are none or none are configured.
	 *
	 * @spec openspec/changes/archiving-as-a-process-with-sign-off/specs/retention-management/spec.md
	 */
	public function findLists(?array $statuses = null): array;

	/**
	 * Is this instance configured to keep destruction lists at all?
	 *
	 * @return bool True when a register and a schema are named in the archival settings.
	 *
	 * @spec openspec/changes/archiving-as-a-process-with-sign-off/specs/retention-management/spec.md
	 */
	public function isConfigured(): bool;

	/**
	 * Write a destruction list's own data back.
	 *
	 * @param ObjectEntity         $list     The list object.
	 * @param array<string, mixed> $listData The data to store on it.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archiving-as-a-process-with-sign-off/specs/retention-management/spec.md
	 */
	public function save(ObjectEntity $list, array $listData): void;
}//end interface
