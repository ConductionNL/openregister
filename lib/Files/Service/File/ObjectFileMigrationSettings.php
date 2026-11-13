<?php

/**
 * The settings an object file move reads and writes.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Files\Service\File
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

namespace OCA\OpenRegister\Files\Service\File;

use OCP\Encryption\IManager as IEncryptionManager;
use OCP\IAppConfig;
use OCP\IConfig;

/**
 * The app config, server config and encryption manager the object file migration consults.
 *
 * @spec openspec/specs/file-actions/spec.md
 */
final class ObjectFileMigrationSettings {
	/**
	 * Bundle the collaborators.
	 *
	 * @param IAppConfig         $appConfig         Keeps the refusal message for the admin.
	 * @param IEncryptionManager $encryptionManager Tells whether server-side encryption is on.
	 * @param IConfig            $config            Reads whether an admin set the account's quota.
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	public function __construct(
		public readonly IAppConfig $appConfig,
		public readonly IEncryptionManager $encryptionManager,
		public readonly IConfig $config,
	) {
	}//end __construct()
}//end class
