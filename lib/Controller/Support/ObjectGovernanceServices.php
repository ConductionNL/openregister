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

use OCA\OpenRegister\Service\Audit\PurposeGuardInterface;
use OCA\OpenRegister\Service\Deletion\DeletionWindowService;
use OCA\OpenRegister\Service\History\StateHistoryProjectorInterface;
use OCA\OpenRegister\Service\Quality\UniqueHintWarningsInterface;

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
	 * @param ?UniqueHintWarningsInterface    $uniqueHintWarnings The soft-uniqueness collector, null when absent.
	 * @param ?PurposeGuardInterface          $purposeGuard       The purpose guard, null when absent.
	 * @param ?StateHistoryProjectorInterface $stateHistory       The state-history projector, null when absent.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function __construct(
		public readonly ?DeletionWindowService $windowService = null,
		public readonly ?UniqueHintWarningsInterface $uniqueHintWarnings = null,
		public readonly ?PurposeGuardInterface $purposeGuard = null,
		public readonly ?StateHistoryProjectorInterface $stateHistory = null,
	) {
	}//end __construct()
}//end class
