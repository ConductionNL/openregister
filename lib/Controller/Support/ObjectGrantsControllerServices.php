<?php

/**
 * OpenRegister object-grants controller services
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
use OCA\OpenRegister\Service\Rbac\ObjectGrantsReportInterface;

/**
 * The services of ObjectGrantsController, bundled so its constructor stays small.
 *
 * @spec openspec/changes/an-object-lists-its-grants-with-their-origin/specs/rbac-scopes/spec.md
 */
final class ObjectGrantsControllerServices {
	/**
	 * Bundle the services.
	 *
	 * @param ObjectOperationsInterface   $objectService Names the register and schema the request resolved.
	 * @param AccessReviewGateInterface   $gate          Resolves the object and decides who may ask.
	 * @param ObjectGrantsReportInterface $report        Assembles the rows.
	 */
	public function __construct(
		public readonly ObjectOperationsInterface $objectService,
		public readonly AccessReviewGateInterface $gate,
		public readonly ObjectGrantsReportInterface $report,
	) {
	}//end __construct()
}//end class
