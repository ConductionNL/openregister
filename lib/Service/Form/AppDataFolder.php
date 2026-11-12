<?php

/**
 * AppDataFolder: the app-data folder a form store keeps its files in.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Form
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

namespace OCA\OpenRegister\Service\Form;

use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFolder;

/**
 * The store's folder, found or created on demand.
 *
 * The using store declares `FOLDER` and provides `$this->appData` (IAppData).
 */
trait AppDataFolder {

	/**
	 * The store's folder.
	 *
	 * @param bool $create Create it when absent.
	 *
	 * @return ISimpleFolder|null The folder; null only when absent and not created.
	 *
	 * @psalm-return ($create is true ? ISimpleFolder : ISimpleFolder|null)
	 */
	private function folder(bool $create): ?ISimpleFolder {
		try {
			return $this->appData->getFolder(self::FOLDER);
		} catch (NotFoundException) {
			if ($create === false) {
				return null;
			}

			return $this->appData->newFolder(self::FOLDER);
		}
	}//end folder()
}//end trait
