<?php

/**
 * OpenRegister survivorship controller services
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

use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Service\Survivorship\SourceRecordResolverInterface;
use OCP\IUserSession;

/**
 * The services of SurvivorshipController, bundled so its constructor stays small.
 *
 * @spec openspec/changes/mdm-survivorship-override/tasks.md#1.4
 */
final class SurvivorshipControllerServices {
	/**
	 * Bundle the services.
	 *
	 * @param ObjectOperationsInterface     $objectService        Object read/write path (RBAC + tenant scoped).
	 * @param SchemaMapperInterface         $schemaMapper         Schema lookup for the survivorship annotation.
	 * @param SourceRecordResolverInterface $sourceRecordResolver Mode-aware source-record resolver (embedded | reverseFk).
	 * @param IUserSession $userSession The user session.
	 */
	public function __construct(
		public readonly ObjectOperationsInterface $objectService,
		public readonly SchemaMapperInterface $schemaMapper,
		public readonly SourceRecordResolverInterface $sourceRecordResolver,
		public readonly IUserSession $userSession,
	) {
	}//end __construct()
}//end class
