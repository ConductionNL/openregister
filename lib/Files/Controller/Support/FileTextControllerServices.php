<?php

/**
 * OpenRegister file text controller services
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

use OCA\OpenRegister\Controller\Support\ControllerSession;

/**
 * The service bundles of the file text API.
 *
 * @spec openspec/specs/api-test-coverage/spec.md
 */
final class FileTextControllerServices {
	/**
	 * Bundle the services.
	 *
	 * @param ControllerSession $session  The session bundle.
	 * @param FileTextMappers   $mappers  The mappers bundle.
	 * @param FileTextDomain    $domain   The domain bundle.
	 * @param FileTextPlatform  $platform The platform bundle.
	 *
	 * @spec openspec/specs/api-test-coverage/spec.md
	 */
	public function __construct(
		public readonly ControllerSession $session,
		public readonly FileTextMappers $mappers,
		public readonly FileTextDomain $domain,
		public readonly FileTextPlatform $platform,
	) {
	}//end __construct()
}//end class
