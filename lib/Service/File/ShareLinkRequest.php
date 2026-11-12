<?php

/**
 * One share link to create for a path.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\File
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\File;

/**
 * A share link request: the path to share, the share type and the permissions.
 *
 * Read by {@see \OCA\OpenRegister\Service\FileService::createShareLinkFor()}.
 *
 * @spec openspec/specs/file-actions/spec.md
 */
final readonly class ShareLinkRequest {
	/**
	 * Constructor.
	 *
	 * @param string   $path        Path (from root) to the file or folder to share.
	 * @param int|null $shareType   The share type (0=user, 1=group, 3=public link, 4=email).
	 * @param int|null $permissions The permissions; null picks the default for the share type.
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	public function __construct(
		public string $path,
		public ?int $shareType = 3,
		public ?int $permissions = null,
	) {
	}//end __construct()

	/**
	 * The permissions to grant: the asked ones, else read for a public link and all for any other type.
	 *
	 * @return int The permissions bit mask.
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	public function permissions(): int {
		if ($this->permissions !== null) {
			return $this->permissions;
		}

		if ($this->shareType === 3) {
			return 1;
		}

		return 31;
	}//end permissions()
}//end class
