<?php

/**
 * What a single-object save records beside the object: trail, archival metadata and activity.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object\SaveObject
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object\SaveObject;

use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Service\Object\UserActivityStamper;
use OCA\OpenRegister\Service\SettingsServiceInterface;
use OCA\OpenRegister\Service\TmloService;

/**
 * What a single-object save records beside the object: trail, archival metadata and activity.
 *
 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
 */
final class SaveObjectRecords {
	/**
	 * Bundle the collaborators.
	 *
	 * @param AuditTrailMapperInterface $auditTrailMapper Audit trail mapper for logging changes.
	 * @param SettingsServiceInterface  $settingsService  Settings service for trail settings.
	 * @param TmloService               $tmloService      TMLO archival metadata service.
	 * @param ?UserActivityStamper      $userActivity     Stamps `@self.lastUserActivity` when a person writes.
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	public function __construct(
		public readonly AuditTrailMapperInterface $auditTrailMapper,
		public readonly SettingsServiceInterface $settingsService,
		public readonly TmloService $tmloService,
		public readonly ?UserActivityStamper $userActivity = null,
	) {
	}//end __construct()
}//end class
