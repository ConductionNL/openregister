<?php

/**
 * OpenRegister RevertController services
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
use OCA\OpenRegister\Service\Object\RevertHandlerInterface;
use Psr\Log\LoggerInterface;

/**
 * The collaborators of RevertController, wired by DI autowiring.
 */
final class RevertControllerServices {
	/**
	 * Bundle the services.
	 *
	 * @param RevertHandlerInterface     $revertService The revert service
	 * @param ?ObjectOperationsInterface $objectService Renders the reverted object as every object read does
	 * @param ?LoggerInterface           $logger        Holds the text of a failed revert, under the request id the answer names
	 *
	 * @spec openspec/changes/history-revert-through-the-save-path/specs/content-versioning/spec.md
	 */
	public function __construct(
		public readonly RevertHandlerInterface $revertService,
		public readonly ?ObjectOperationsInterface $objectService = null,
		public readonly ?LoggerInterface $logger = null,
	) {
	}//end __construct()
}//end class
