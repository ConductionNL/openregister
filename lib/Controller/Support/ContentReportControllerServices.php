<?php

/**
 * OpenRegister ContentReportController services
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

use OCA\OpenRegister\Db\ContentReportMapper;
use OCA\OpenRegister\Db\MagicMapper\MagicObjectFinderInterface;
use OCA\OpenRegister\Service\Audit\ContentReportService;
use OCP\IUserSession;

/**
 * The collaborators of ContentReportController, wired by DI autowiring.
 *
 * @spec openspec/specs/enhanced-audit-trail/spec.md
 */
final class ContentReportControllerServices {
	/**
	 * Bundle the services.
	 *
	 * @param ContentReportMapper $reports The reports and their copies.
	 * @param ContentReportService $service Files a report and resolves reviewer access.
	 * @param MagicObjectFinderInterface $objects Resolves the content being reported.
	 * @param IUserSession $userSession Current user session.
	 *
	 * @spec openspec/specs/enhanced-audit-trail/spec.md
	 */
	public function __construct(
		public readonly ContentReportMapper $reports,
		public readonly ContentReportService $service,
		public readonly MagicObjectFinderInterface $objects,
		public readonly IUserSession $userSession,
	) {
	}//end __construct()
}//end class
