<?php

/**
 * The Nextcloud services the document store move works through.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\File
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/register-document-store/specs/file-actions/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\File;

use OCP\Files\IRootFolder;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\Share\IManager as IShareManager;
use OCP\SystemTag\ISystemTagObjectMapper;

/**
 * Files, app config, database, shares and system tags.
 *
 * @spec openspec/changes/register-document-store/specs/file-actions/spec.md
 */
final class DocumentStoreMovePlatform {

	/**
	 * Constructor.
	 *
	 * @param IRootFolder            $rootFolder Reaches the openregister account's home.
	 * @param IAppConfig             $appConfig  Holds the root and the renamed old root.
	 * @param IDBConnection          $db         Rewrites the reference columns.
	 * @param IShareManager          $shares     Reads and recreates shares.
	 * @param ISystemTagObjectMapper $tags       Reads and copies system tags.
	 *
	 * @spec openspec/changes/register-document-store/specs/file-actions/spec.md
	 */
	public function __construct(
		public readonly IRootFolder $rootFolder,
		public readonly IAppConfig $appConfig,
		public readonly IDBConnection $db,
		public readonly IShareManager $shares,
		public readonly ISystemTagObjectMapper $tags,
	) {
	}//end __construct()
}//end class
