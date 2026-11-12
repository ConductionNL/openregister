<?php

/**
 * InstalledAppCheck: whether the Nextcloud app an object source reads is installed.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ObjectSource
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ObjectSource;

use Throwable;

/**
 * isEnabled() for a provider over one Nextcloud app.
 *
 * The using provider declares `REQUIRED_APP` and provides `$this->appManager` (IAppManager).
 */
trait InstalledAppCheck {

	/**
	 * Whether the backing app is installed; false when the check itself fails.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/virtual-schema-semantic-providers/tasks.md#task-5.1
	 */
	public function isEnabled(): bool {
		try {
			return $this->appManager->isInstalled(self::REQUIRED_APP);
		} catch (Throwable $e) {
			return false;
		}
	}//end isEnabled()
}//end trait
