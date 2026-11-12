<?php

/**
 * OpenRegister duplicate controller services
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

use OCA\OpenRegister\Controller\Support\ControllerSession;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Search\Service\Quality\DismissedPairStore;
use OCA\OpenRegister\Search\Service\Quality\DuplicateDetectionServiceInterface;

/**
 * The services of the duplicate-detection API.
 *
 * @spec openspec/changes/mdm-surface-api/tasks.md#task-2
 */
final class DuplicateControllerServices {
	/**
	 * Bundle the services.
	 *
	 * @param DuplicateDetectionServiceInterface $duplicates    The duplicate detection service
	 * @param DismissedPairStore                 $dismissals    The dismissed-pair store
	 * @param ObjectOperationsInterface          $objectService The object operations
	 * @param ControllerSession                  $session       The caller's session
	 *
	 * @spec openspec/changes/mdm-surface-api/tasks.md#task-2
	 */
	public function __construct(
		public readonly DuplicateDetectionServiceInterface $duplicates,
		public readonly DismissedPairStore $dismissals,
		public readonly ObjectOperationsInterface $objectService,
		public readonly ControllerSession $session,
	) {
	}//end __construct()
}//end class
