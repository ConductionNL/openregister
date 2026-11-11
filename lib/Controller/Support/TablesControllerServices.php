<?php

/**
 * OpenRegister tables controller services
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

use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\SchemaMapperInterface;

/**
 * The services of the tables API.
 *
 * @spec openspec/changes/retrofit-2026-05-24-b-ctrl-misc/tasks.md#task-8
 */
final class TablesControllerServices {
	/**
	 * Bundle the services.
	 *
	 * @param MagicMapperInterface    $magicMapper    Magic mapper for table operations
	 * @param RegisterMapperInterface $registerMapper Register mapper
	 * @param SchemaMapperInterface   $schemaMapper   Schema mapper
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-ctrl-misc/tasks.md#task-8
	 */
	public function __construct(
		public readonly MagicMapperInterface $magicMapper,
		public readonly RegisterMapperInterface $registerMapper,
		public readonly SchemaMapperInterface $schemaMapper,
	) {
	}//end __construct()
}//end class
