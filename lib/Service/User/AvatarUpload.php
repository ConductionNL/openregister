<?php

/**
 * AvatarUpload: an image the user uploads as avatar.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\User
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/profile-actions/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\User;

/**
 * The raw image, its MIME type and its size, as the upload carried them.
 *
 * ADR-011 Rule 2 operation object for UserService::setAvatar().
 *
 * @spec openspec/specs/profile-actions/spec.md
 */
final class AvatarUpload {

	/**
	 * Describe it.
	 *
	 * @param string $data     The raw image data.
	 * @param string $mimeType The MIME type of the uploaded file.
	 * @param int    $size     The file size in bytes.
	 */
	public function __construct(
		public readonly string $data,
		public readonly string $mimeType,
		public readonly int $size,
	) {
	}//end __construct()
}//end class
