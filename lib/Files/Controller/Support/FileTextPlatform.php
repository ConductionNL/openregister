<?php

/**
 * OpenRegister file text platform
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

use OCP\Files\IRootFolder;

/**
 * The platform services of the file text API.
 *
 * @spec openspec/specs/api-test-coverage/spec.md
 */
final class FileTextPlatform {
	/**
	 * Bundle the services.
	 *
	 * @param IRootFolder $rootFolder Root folder for per-user file access checks
	 *
	 * @spec openspec/specs/api-test-coverage/spec.md
	 */
	public function __construct(
		public readonly IRootFolder $rootFolder,
	) {
	}//end __construct()
}//end class
