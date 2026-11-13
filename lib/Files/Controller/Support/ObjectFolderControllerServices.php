<?php

/**
 * OpenRegister object folder controller services
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

use OCA\OpenRegister\Files\Service\File\ObjectFileAccess;
use OCA\OpenRegister\Files\Service\File\ObjectFolderBrowser;
use OCA\OpenRegister\Files\Service\File\ObjectFolderResponder;
use OCA\OpenRegister\Files\Service\File\ObjectFolderUploads;

/**
 * The services of the object folder API.
 *
 * @spec openspec/specs/file-actions/spec.md
 */
final class ObjectFolderControllerServices {
	/**
	 * Bundle the services.
	 *
	 * @param ObjectFileAccess      $objectFileAccess The object rule for file actions.
	 * @param ObjectFolderBrowser   $browser          The folder tree of one object.
	 * @param ObjectFolderUploads   $uploads          Stores a batch of uploads.
	 * @param ObjectFolderResponder $responder        Turns outcomes and failures into responses.
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	public function __construct(
		public readonly ObjectFileAccess $objectFileAccess,
		public readonly ObjectFolderBrowser $browser,
		public readonly ObjectFolderUploads $uploads,
		public readonly ObjectFolderResponder $responder,
	) {
	}//end __construct()
}//end class
