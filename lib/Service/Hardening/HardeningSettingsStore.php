<?php

/**
 * OpenRegister hardening settings store
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Hardening
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

namespace OCA\OpenRegister\Service\Hardening;

use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Where hardening settings are stored and their changes written down.
 *
 * @spec openspec/changes/instance-hardening-controls/specs/instance-hardening/spec.md
 */
final class HardeningSettingsStore {
	/**
	 * Bundle the collaborators.
	 *
	 * @param IAppConfig                $appConfig        Stores the administered values and the floors.
	 * @param AuditTrailMapperInterface $auditTrailMapper Records the change, and the refusal.
	 * @param LoggerInterface           $logger           Records an audit write that could not be made.
	 */
	public function __construct(
		public readonly IAppConfig $appConfig,
		public readonly AuditTrailMapperInterface $auditTrailMapper,
		public readonly LoggerInterface $logger,
	) {
	}//end __construct()
}//end class
