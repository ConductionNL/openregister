<?php

/**
 * An in-memory simple folder.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Form\Fakes;

use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\Files\SimpleFS\ISimpleFolder;

/**
 * Files by name; a deleted file disappears from the listing.
 */
class MemoryFolder implements ISimpleFolder {

	/**
	 * @var array<string, MemoryFile>
	 */
	public array $files = [];

	public function __construct(private readonly string $name) {
	}//end __construct()

	public function getDirectoryListing(): array {
		return array_values($this->files);
	}//end getDirectoryListing()

	public function fileExists(string $name): bool {
		return isset($this->files[$name]);
	}//end fileExists()

	public function getFile(string $name): ISimpleFile {
		if (isset($this->files[$name]) === false) {
			throw new NotFoundException($name);
		}

		return $this->files[$name];
	}//end getFile()

	public function newFile(string $name, $content = null): ISimpleFile {
		$this->files[$name] = new MemoryFile(name: $name, folder: $this, content: (string)($content ?? ''));

		return $this->files[$name];
	}//end newFile()

	public function delete(): void {
		$this->files = [];
	}//end delete()

	public function getName(): string {
		return $this->name;
	}//end getName()

	public function getFolder(string $name): ISimpleFolder {
		throw new NotFoundException($name);
	}//end getFolder()

	public function newFolder(string $path): ISimpleFolder {
		return new self(name: $path);
	}//end newFolder()

	public function getOrCreateFolder(string $path, int $maxRetries = 5): ISimpleFolder {
		return new self(name: $path);
	}//end getOrCreateFolder()

	/**
	 * Remove one file, as MemoryFile::delete asks.
	 */
	public function forget(string $name): void {
		unset($this->files[$name]);
	}//end forget()
}//end class
