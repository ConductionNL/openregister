<?php

/**
 * OpenRegister object governance services
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller\Support
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

namespace OCA\OpenRegister\Controller\Support;

use OCA\OpenRegister\Service\Audit\PurposeGuard;
use OCA\OpenRegister\Service\Deletion\DeletionWindowService;
use OCA\OpenRegister\Service\History\StateHistoryProjector;
use OCA\OpenRegister\Service\Quality\UniqueHintWarnings;

/**
 * Recovery window, soft uniqueness, purpose binding and state history for object reads and writes.
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
final class ObjectGovernanceServices {
	/**
	 * Bundle the services.
	 *
	 * @param ?DeletionWindowService $windowService      The recovery-window service, null when absent.
	 * @param ?UniqueHintWarnings    $uniqueHintWarnings The soft-uniqueness collector, null when absent.
	 * @param ?PurposeGuard          $purposeGuard       The purpose guard, null when absent.
	 * @param ?StateHistoryProjector $stateHistory       The state-history projector, null when absent.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function __construct(
		public readonly ?DeletionWindowService $windowService = null,
		public readonly ?UniqueHintWarnings $uniqueHintWarnings = null,
		public readonly ?PurposeGuard $purposeGuard = null,
		public readonly ?StateHistoryProjector $stateHistory = null,
	) {
	}//end __construct()
}//end class
