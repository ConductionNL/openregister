<?php

/**
 * The archival settings and the rule helpers retention dates and destruction are derived with.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
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

namespace OCA\OpenRegister\Service;

use OCA\OpenRegister\Service\Archival\ArchiveActionDateCalculator;
use OCA\OpenRegister\Service\Archival\RetentionRowScanner;
use OCA\OpenRegister\Service\Archival\SelectielijstResolver;
use OCA\OpenRegister\Service\Settings\ArchivalSettingsReaderInterface;

/**
 * The archival settings and the rule helpers retention dates and destruction are derived with.
 *
 * @spec openspec/specs/retention-management/spec.md
 */
final class RetentionRules {
	/**
	 * Bundle the collaborators.
	 *
	 * @param ArchivalSettingsReaderInterface $settingsHandler      Archival settings.
	 * @param RetentionRowScanner             $rowScanner           Scans rows for retention.
	 * @param ArchiveActionDateCalculator     $actionDateCalculator Calculates the archive action date.
	 * @param SelectielijstResolver           $listResolver         Resolves the selectielijst category.
	 *
	 * @spec openspec/specs/retention-management/spec.md
	 */
	public function __construct(
		public readonly ArchivalSettingsReaderInterface $settingsHandler,
		public readonly RetentionRowScanner $rowScanner,
		public readonly ArchiveActionDateCalculator $actionDateCalculator,
		public readonly SelectielijstResolver $listResolver,
	) {
	}//end __construct()
}//end class
