<?php

/**
 * OpenRegister file editing services
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

namespace OCA\OpenRegister\Files\Controller\Support;

use OCA\OpenRegister\Files\Service\File\AttachNodeHandler;
use OCA\OpenRegister\Files\Service\File\OfficeSessionService;

/**
 * Office sessions and attaching existing nodes; each is null when absent.
 *
 * @spec openspec/specs/file-actions/spec.md
 */
final class FileEditing {
	/**
	 * Bundle the services.
	 *
	 * @param ?OfficeSessionService $officeSessionService The office session service.
	 * @param ?AttachNodeHandler    $attachNodeHandler    The attach-node handler.
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	public function __construct(
		public readonly ?OfficeSessionService $officeSessionService = null,
		public readonly ?AttachNodeHandler $attachNodeHandler = null,
	) {
	}//end __construct()
}//end class
