<?php

/**
 * OpenRegister object sharing controller services
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

use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Service\Rbac\ObjectSharingServiceInterface;

/**
 * The services of the object sharing API.
 *
 * @spec openspec/changes/object-level-sharing-and-private-scope/specs/object-level-sharing/spec.md
 */
final class ObjectSharingControllerServices {
	/**
	 * Bundle the services.
	 *
	 * @param ObjectOperationsInterface $objectService  Resolves an object through the RBAC boundary.
	 * @param RegisterMapperInterface   $registerMapper Resolves the register entity.
	 * @param SchemaMapperInterface     $schemaMapper   Resolves the schema entity.
	 * @param ObjectSharingServiceInterface      $sharing        Performs the owner-checked writes.
	 *
	 * @spec openspec/changes/object-level-sharing-and-private-scope/specs/object-level-sharing/spec.md
	 */
	public function __construct(
		public readonly ObjectOperationsInterface $objectService,
		public readonly RegisterMapperInterface $registerMapper,
		public readonly SchemaMapperInterface $schemaMapper,
		public readonly ObjectSharingServiceInterface $sharing,
	) {
	}//end __construct()
}//end class
