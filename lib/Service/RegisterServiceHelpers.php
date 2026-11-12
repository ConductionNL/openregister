<?php

/**
 * The register folder service and the register serializer of the register service.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
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

namespace OCA\OpenRegister\Service;

use OCA\OpenRegister\Service\Serializer\RegisterSerializer;

/**
 * The register folder service and the register serializer of the register service.
 *
 * @spec openspec/specs/register-service-extensions/spec.md
 */
final class RegisterServiceHelpers {
	/**
	 * Bundle the collaborators.
	 *
	 * @param FileFolderServiceInterface $fileService        Creates and deletes register folders.
	 * @param RegisterSerializer         $registerSerializer Applies _extend post-processing.
	 *
	 * @spec openspec/specs/register-service-extensions/spec.md
	 */
	public function __construct(
		public readonly FileFolderServiceInterface $fileService,
		public readonly RegisterSerializer $registerSerializer,
	) {
	}//end __construct()
}//end class
