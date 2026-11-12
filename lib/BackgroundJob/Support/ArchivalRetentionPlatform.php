<?php

/**
 * OpenRegister archival retention platform
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

use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Service\RetentionServiceInterface;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * The delete path, the legal-hold answer, the notification container and the logger of the archival sweep.
 *
 * @spec openspec/changes/add-archival-annotation-support/tasks.md#task-5
 */
final readonly class ArchivalRetentionPlatform {
	/**
	 * Bundle the services.
	 *
	 * @param ObjectOperationsInterface $objectService    Performs the sweep deletes.
	 * @param RetentionServiceInterface $retentionService Answers whether a row is under an active legal hold.
	 * @param ContainerInterface        $container        App container the notification collaborators come from.
	 * @param LoggerInterface           $logger           Logger for per-schema summaries.
	 *
	 * @spec openspec/changes/add-archival-annotation-support/tasks.md#task-5
	 */
	public function __construct(
		public ObjectOperationsInterface $objectService,
		public RetentionServiceInterface $retentionService,
		public ContainerInterface $container,
		public LoggerInterface $logger,
	) {
	}//end __construct()
}//end class
