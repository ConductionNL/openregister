<?php

/**
 * OpenRegister ConversionWorkFolder
 *
 * The folder office conversions run in.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\File\Office
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/export-pdf-format/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\File\Office;

use OCA\OpenRegister\Service\File\FileOwnershipHandler;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use RuntimeException;

/**
 * A hidden folder in the home of openregister's own account.
 *
 * Conversion providers need a file in a user's files: Euro-Office lets its
 * document server download the source through that user. openregister's own
 * account is used, so no person's home holds the work files.
 *
 * @spec openspec/specs/export-pdf-format/spec.md
 */
class ConversionWorkFolder {

	/**
	 * Folder name in the account's home.
	 */
	public const NAME = '.office-conversion';

	/**
	 * Constructor.
	 *
	 * @param IRootFolder          $rootFolder Root folder.
	 * @param FileOwnershipHandler $ownership  Gives openregister's own account.
	 */
	public function __construct(
		private readonly IRootFolder $rootFolder,
		private readonly FileOwnershipHandler $ownership,
	) {
	}//end __construct()

	/**
	 * The work folder, created when missing.
	 *
	 * @return Folder
	 *
	 * @throws RuntimeException When the folder cannot be opened.
	 *
	 * @spec openspec/specs/export-pdf-format/spec.md
	 */
	public function get(): Folder {
		$home = $this->rootFolder->getUserFolder($this->ownership->getUser()->getUID());

		try {
			$folder = $home->get(self::NAME);
		} catch (NotFoundException) {
			$folder = $home->newFolder(self::NAME);
		}

		if (($folder instanceof Folder) === false) {
			throw new RuntimeException('The conversion work folder is not a folder');
		}

		return $folder;
	}//end get()
}//end class
