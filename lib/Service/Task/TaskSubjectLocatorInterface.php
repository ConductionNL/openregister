<?php

/**
 * TaskSubjectLocatorInterface: what callers outside the Service component ask of TaskSubjectLocator.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Task
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Task;

/**
 * Locating task subjects by uuid, as the subject-location backfill uses it.
 * Only the method that caller calls.
 */
interface TaskSubjectLocatorInterface {

	/**
	 * Find the register and schema of each subject uuid.
	 *
	 * @param array<int, string> $uuids The subject uuids.
	 *
	 * @return array<string, array{registerId: int, schemaId: int}> Locations by uuid; uuids not found are absent.
	 */
	public function locateMany(array $uuids): array;
}//end interface
