<?php

/**
 * ShareUrlBuilderInterface: what callers outside the Files component use of ShareUrlBuilder.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Files
 * @package  OCA\OpenRegister\Files\Service\File
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Files\Service\File;

/**
 * Builds the public link of a share, as FileService formats it.
 *
 * @spec openspec/specs/file-actions/spec.md#requirement-a-file-link-points-at-the-host-the-visitor-used
 */
interface ShareUrlBuilderInterface {

	/**
	 * The public link of a share token.
	 *
	 * @param string $token The share token.
	 *
	 * @return string The absolute share link, without a trailing `/download`.
	 *
	 * @spec openspec/specs/file-actions/spec.md#requirement-a-file-link-points-at-the-host-the-visitor-used
	 */
	public function shareLink(string $token): string;
}//end interface
