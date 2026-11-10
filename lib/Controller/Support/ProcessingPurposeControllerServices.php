<?php

/**
 * OpenRegister processing purpose controller services
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

use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Db\ProcessingPurposeMapperInterface;
use OCA\OpenRegister\Service\Audit\PurposeRegistryInterface;

/**
 * The services of the processing purpose API.
 *
 * @spec openspec/specs/verwerkingsregister-api/spec.md
 */
final class ProcessingPurposeControllerServices {
	/**
	 * Bundle the services.
	 *
	 * @param ProcessingPurposeMapperInterface $purposes   The administered list.
	 * @param PurposeRegistryInterface         $registry   Resolves a purpose to its activity.
	 * @param AuditTrailMapperInterface        $auditTrail Counts entries per purpose.
	 * @param ControllerSession                $session    Session, group manager and logger.
	 *
	 * @spec openspec/specs/verwerkingsregister-api/spec.md
	 */
	public function __construct(
		public readonly ProcessingPurposeMapperInterface $purposes,
		public readonly PurposeRegistryInterface $registry,
		public readonly AuditTrailMapperInterface $auditTrail,
		public readonly ControllerSession $session,
	) {
	}//end __construct()
}//end class
