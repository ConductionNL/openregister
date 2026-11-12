<?php

/**
 * OpenRegister DSAR DPIA detection services
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

use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Service\Gdpr\DpiaPatternDetectorInterface;
use OCA\OpenRegister\Service\Gdpr\Policy\DsarPolicyPackResolverInterface;

/**
 * The object path, policy pack resolver, pattern detector and audit trail of the DPIA pattern detection.
 *
 * @spec openspec/changes/dsar-escalation-and-dpia/specs/dsar-dpia-detection/spec.md
 */
final readonly class DsarDpiaDetectionServices {
	/**
	 * Bundle the services.
	 *
	 * @param ObjectOperationsInterface       $objectService    Object read/write path.
	 * @param DsarPolicyPackResolverInterface $packResolver     Active-pack resolution per jurisdiction.
	 * @param DpiaPatternDetectorInterface    $detectionService Pure grouping/threshold engine.
	 * @param AuditTrailMapperInterface       $auditTrailMapper Immutable detection audit rows.
	 *
	 * @spec openspec/changes/dsar-escalation-and-dpia/specs/dsar-dpia-detection/spec.md
	 */
	public function __construct(
		public ObjectOperationsInterface $objectService,
		public DsarPolicyPackResolverInterface $packResolver,
		public DpiaPatternDetectorInterface $detectionService,
		public AuditTrailMapperInterface $auditTrailMapper,
	) {
	}//end __construct()
}//end class
