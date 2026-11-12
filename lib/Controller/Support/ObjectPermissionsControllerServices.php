<?php

/**
 * OpenRegister object-permissions controller services
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

use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Service\Rbac\AccessReviewGateInterface;
use OCA\OpenRegister\Service\Rbac\ObjectAccessReportInterface;
use Psr\Log\LoggerInterface;

/**
 * The services of ObjectPermissionsController, bundled so its constructor stays small.
 *
 * @spec openspec/specs/rbac-scopes/spec.md
 */
final class ObjectPermissionsControllerServices {
	/**
	 * Bundle the services.
	 *
	 * @param ObjectOperationsInterface   $objectService Names the register and schema the request resolved.
	 * @param ObjectAccessReportInterface $report        Assembles the answer.
	 * @param AccessReviewGateInterface   $gate          Resolves the object and decides who may ask.
	 * @param LoggerInterface             $logger        Records unexpected failures.
	 */
	public function __construct(
		public readonly ObjectOperationsInterface $objectService,
		public readonly ObjectAccessReportInterface $report,
		public readonly AccessReviewGateInterface $gate,
		public readonly LoggerInterface $logger,
	) {
	}//end __construct()
}//end class
