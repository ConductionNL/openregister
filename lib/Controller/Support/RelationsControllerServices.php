<?php

/**
 * OpenRegister relations controller services
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

/**
 * The service bundles of the relations API.
 *
 * @spec openspec/changes/retrofit-2026-05-24-data-integrity-relations/tasks.md#task-1
 */
final class RelationsControllerServices {
	/**
	 * Bundle the services.
	 *
	 * @param RelationsRecords       $records       The records bundle.
	 * @param RelationsCollaboration $collaboration The collaboration bundle.
	 * @param RelationsPlatform      $platform      The platform bundle.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-data-integrity-relations/tasks.md#task-1
	 */
	public function __construct(
		public readonly RelationsRecords $records,
		public readonly RelationsCollaboration $collaboration,
		public readonly RelationsPlatform $platform,
	) {
	}//end __construct()
}//end class
