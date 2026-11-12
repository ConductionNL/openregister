<?php

/**
 * AppRegisterImportRunnerInterface: what callers outside the Service component read of AppRegisterImportRunner.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
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

namespace OCA\OpenRegister\Service;

/**
 * The import-path marker that callers outside the Service component compare a run's `path` against.
 */
interface AppRegisterImportRunnerInterface {
	/**
	 * The import ran through OpenRegister's reader of the app's files.
	 *
	 * @var string
	 */
	public const PATH_OPENREGISTER = 'openregister';
}//end interface
