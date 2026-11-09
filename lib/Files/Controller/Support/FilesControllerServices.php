<?php

/**
 * OpenRegister files controller services
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

/**
 * Everything the files controller injects besides the request.
 *
 * @spec openspec/specs/file-actions/spec.md
 */
final class FilesControllerServices {
	/**
	 * Bundle the services.
	 *
	 * @param FileStore    $store    File and object services, root folder and mapper.
	 * @param FilePlatform $platform Users, events, session and translations.
	 * @param FileGuards   $guards   Access, write and restriction checks and audit.
	 * @param FileEditing  $editing  Office sessions and node attaching.
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	public function __construct(
		public readonly FileStore $store,
		public readonly FilePlatform $platform,
		public readonly FileGuards $guards,
		public readonly FileEditing $editing,
	) {
	}//end __construct()
}//end class
