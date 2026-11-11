<?php

/**
 * AnyAppInstalledCheck: a provider enabled when any one of several apps is installed.
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
 *
 * @spec openspec/changes/object-source-providers/tasks.md#task-5.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ObjectSource;

use Throwable;

/**
 * isEnabled() for a provider that needs any one of `ENABLING_APPS`.
 *
 * The using class carries an `IAppManager $appManager` and the
 * `ENABLING_APPS` constant, checked in order.
 */
trait AnyAppInstalledCheck {

	/**
	 * {@inheritDoc}
	 *
	 * @return bool True when any enabling app is installed; false when none is
	 *              or the app manager throws.
	 *
	 * @spec openspec/changes/object-source-providers/tasks.md#task-5.2
	 */
	public function isEnabled(): bool {
		try {
			foreach (self::ENABLING_APPS as $app) {
				if ($this->appManager->isInstalled($app) === true) {
					return true;
				}
			}
		} catch (Throwable $e) {
			return false;
		}

		return false;
	}//end isEnabled()
}//end trait
