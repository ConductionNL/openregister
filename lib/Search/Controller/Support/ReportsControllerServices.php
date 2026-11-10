<?php

/**
 * OpenRegister reports controller services
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Search\Controller\Support
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

namespace OCA\OpenRegister\Search\Controller\Support;

use OCA\OpenRegister\Db\MagicMapper\MagicObjectFinderInterface;
use OCA\OpenRegister\Search\Service\Reporting\ReportRenderService;
use Psr\Log\LoggerInterface;

/**
 * The services of the reports API.
 *
 * @spec openspec/changes/retrofit-2026-05-24-b-ctrl-graphql-rt-dash/tasks.md#task-9
 */
final class ReportsControllerServices {
	/**
	 * Bundle the services.
	 *
	 * @param ReportRenderService        $renderService The report render service
	 * @param MagicObjectFinderInterface $objectMapper  Finds the objects a report reads
	 * @param LoggerInterface            $logger        Logger for error tracking
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-ctrl-graphql-rt-dash/tasks.md#task-9
	 */
	public function __construct(
		public readonly ReportRenderService $renderService,
		public readonly MagicObjectFinderInterface $objectMapper,
		public readonly LoggerInterface $logger,
	) {
	}//end __construct()
}//end class
