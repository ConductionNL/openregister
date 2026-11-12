<?php

/**
 * The task, register, schema and object services the tasks integration provider works through.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Integration\BuiltinProviders
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/specs/integration-registry/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Integration\BuiltinProviders;

use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectCalendarTasksInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;

/**
 * A dependency bundle of {@see TasksProvider}.
 *
 * @spec openspec/specs/integration-registry/spec.md
 */
final class TasksProviderServices {
	/**
	 * Bundle the services.
	 *
	 * @param ObjectCalendarTasksInterface $taskService    Tasks service.
	 * @param RegisterMapperInterface      $registerMapper Register lookup (slug/uuid/id → entity).
	 * @param SchemaMapperInterface        $schemaMapper   Schema lookup (slug/uuid/id → entity).
	 * @param ObjectOperationsInterface    $objectService  Object lookup (for the LINK label title).
	 *
	 * @spec openspec/specs/integration-registry/spec.md
	 */
	public function __construct(
		public readonly ObjectCalendarTasksInterface $taskService,
		public readonly RegisterMapperInterface $registerMapper,
		public readonly SchemaMapperInterface $schemaMapper,
		public readonly ObjectOperationsInterface $objectService,
	) {
	}//end __construct()
}//end class
