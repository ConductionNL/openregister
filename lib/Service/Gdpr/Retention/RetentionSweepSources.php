<?php

/**
 * OpenRegister retention sweep sources
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Gdpr\Retention
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

namespace OCA\OpenRegister\Service\Gdpr\Retention;

use OCA\OpenRegister\Service\Gdpr\Case\CaseObjectAccessor;
use OCA\OpenRegister\Service\Gdpr\DataSubjectRequestService;
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * What a retention sweep reads and scrubs: the cases, the evidence PII and the clock.
 *
 * @spec openspec/specs/dsar-retention-sweep/spec.md
 */
final class RetentionSweepSources {
	/**
	 * Bundle the collaborators.
	 *
	 * @param CaseObjectAccessor        $accessor   RBAC-scoped case query + audited delete.
	 * @param DataSubjectRequestService $dsrService Reused evidence-PII scrub (erase pseudonymise).
	 * @param ITimeFactory              $time       Time source for the `retainUntil` cut-off.
	 */
	public function __construct(
		public readonly CaseObjectAccessor $accessor,
		public readonly DataSubjectRequestService $dsrService,
		public readonly ITimeFactory $time,
	) {
	}//end __construct()
}//end class
