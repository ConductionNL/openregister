<?php

/**
 * An in-memory app-data root, so the form stores are tested against real reads and writes.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Form\Fakes;

use OCP\Files\IAppData;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFolder;

/**
 * Folders by name, each a MemoryFolder.
 */
class MemoryAppData implements IAppData {

	/**
	 * @var array<string, MemoryFolder>
	 */
	public array $folders = [];

	public function getFolder(string $name): ISimpleFolder {
		if (isset($this->folders[$name]) === false) {
			throw new NotFoundException($name);
		}

		return $this->folders[$name];
	}//end getFolder()

	public function getDirectoryListing(): array {
		return array_values($this->folders);
	}//end getDirectoryListing()

	public function newFolder(string $name): ISimpleFolder {
		$this->folders[$name] = new MemoryFolder(name: $name);

		return $this->folders[$name];
	}//end newFolder()
}//end class
