<?php

/**
 * ArchiveReferenceReaderInterface: what callers outside the Service component ask of ArchiveActionDateCalculator.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Archival
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

namespace OCA\OpenRegister\Service\Archival;

/**
 * Reading the reference a dependant's relation property holds, as the related-retention recalculation does. Only the method that caller calls.
 */
interface ArchiveReferenceReaderInterface {

	/**
	 * The uuid or uri a relation property holds, or null.
	 *
	 * @param mixed $value The raw relation value
	 *
	 * @return string|null The reference
	 *
	 * @spec openspec/changes/archival-frozen-refuses-delete-and-dates-follow/specs/retention-management/spec.md#requirement-the-destruction-date-follows-its-source-date-under-every-method
	 */
	public function referenceOf(mixed $value): ?string;
}//end interface
