<?php

/**
 * OpenRegister tmlo guards
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

use OCA\OpenRegister\Service\Export\ExportGate;
use Psr\Log\LoggerInterface;

/**
 * Services of the tmlo API.
 *
 * @spec openspec/changes/retrofit-2026-05-24-tmlo-metadata/tasks.md#task-1
 */
final class TmloGuards {
	/**
	 * Bundle the services.
	 *
	 * @param ExportGate      $exportGate The export verb, checked before any archival metadata leaves.
	 * @param LoggerInterface $logger     Logger interface
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-tmlo-metadata/tasks.md#task-1
	 */
	public function __construct(
		public readonly ExportGate $exportGate,
		public readonly LoggerInterface $logger,
	) {
	}//end __construct()
}//end class
