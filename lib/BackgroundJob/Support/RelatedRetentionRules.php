<?php

/**
 * OpenRegister related retention rules
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category BackgroundJob
 * @package  OCA\OpenRegister\BackgroundJob\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\BackgroundJob\Support;

use OCA\OpenRegister\Service\Archival\ArchiveActionDateCalculator;
use OCA\OpenRegister\Service\Archival\DependantRetentionIndex;
use OCA\OpenRegister\Service\RetentionServiceInterface;

/**
 * What knows which schemas depend on a source record and recalculates their retention.
 *
 * @spec openspec/changes/archival-frozen-refuses-delete-and-dates-follow/specs/retention-management/spec.md#requirement-the-destruction-date-follows-its-source-date-under-every-method
 */
final class RelatedRetentionRules {
	/**
	 * Bundle the services.
	 *
	 * @param DependantRetentionIndex     $index      Knows the dependant schemas.
	 * @param ArchiveActionDateCalculator $calculator Reads the reference a dependant holds.
	 * @param RetentionServiceInterface   $retention  Recalculates one record.
	 *
	 * @spec openspec/changes/archival-frozen-refuses-delete-and-dates-follow/specs/retention-management/spec.md#requirement-the-destruction-date-follows-its-source-date-under-every-method
	 */
	public function __construct(
		public readonly DependantRetentionIndex $index,
		public readonly ArchiveActionDateCalculator $calculator,
		public readonly RetentionServiceInterface $retention,
	) {
	}//end __construct()
}//end class
