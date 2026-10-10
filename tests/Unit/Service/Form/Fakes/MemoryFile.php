<?php

/**
 * An in-memory simple file.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Form\Fakes;

use OCP\Files\SimpleFS\ISimpleFile;

/**
 * Content in a string; delete removes it from its folder.
 */
class MemoryFile implements ISimpleFile {

	public function __construct(
		private readonly string $name,
		private readonly MemoryFolder $folder,
		private string $content,
	) {
	}//end __construct()

	public function getName(): string {
		return $this->name;
	}//end getName()

	public function getSize(): int|float {
		return strlen($this->content);
	}//end getSize()

	public function getETag(): string {
		return md5($this->content);
	}//end getETag()

	public function getMTime(): int {
		return 0;
	}//end getMTime()

	public function getContent(): string {
		return $this->content;
	}//end getContent()

	public function putContent($data): void {
		$this->content = is_resource($data) === true ? (string)stream_get_contents($data) : (string)$data;
	}//end putContent()

	public function delete(): void {
		$this->folder->forget(name: $this->name);
	}//end delete()

	public function getMimeType(): string {
		return 'application/octet-stream';
	}//end getMimeType()

	public function getExtension(): string {
		return '';
	}//end getExtension()

	public function read() {
		$stream = fopen('php://memory', 'r+');
		fwrite($stream, $this->content);
		rewind($stream);

		return $stream;
	}//end read()

	public function write() {
		return fopen('php://memory', 'w');
	}//end write()
}//end class
