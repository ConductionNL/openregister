<?php

/**
 * OpenRegister office session settings
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

use OCP\IAppConfig;
use OCP\IConfig;

/**
 * The configuration an Office session service reads: the instance id and richdocuments' callback address.
 *
 * @spec openspec/changes/object-files-follow-object-access/specs/file-actions/spec.md#requirement-nextcloud-office-opens-an-objects-document-by-the-object-rule-req-ofoa-006
 */
final class OfficeSessionSettings {
	/**
	 * Bundle the settings.
	 *
	 * @param IConfig    $config    Reads the instance id.
	 * @param IAppConfig $appConfig Reads richdocuments' callback address.
	 *
	 * @spec openspec/changes/object-files-follow-object-access/specs/file-actions/spec.md#requirement-nextcloud-office-opens-an-objects-document-by-the-object-rule-req-ofoa-006
	 */
	public function __construct(
		public readonly IConfig $config,
		public readonly IAppConfig $appConfig,
	) {
	}//end __construct()
}//end class
