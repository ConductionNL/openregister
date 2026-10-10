<?php

/**
 * A file property's size and type rules, read one way for the save path and the upload token.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-upload-tokens-must-hold-bytes-only-and-expire
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\File;

use OCA\OpenRegister\Service\File\FilePropertyRules;
use PHPUnit\Framework\TestCase;

/**
 * Bytes vs editor megabytes, the two allowed-type spellings, and the items rule.
 *
 * @covers \OCA\OpenRegister\Service\File\FilePropertyRules
 */
class FilePropertyRulesTest extends TestCase {

	/**
	 * Top-level maxSize is bytes; the editor's fileConfiguration.maxSize is megabytes.
	 */
	public function testMaxSize(): void {
		$rules = new FilePropertyRules();

		$this->assertSame(2048, $rules->maxSizeBytes(fileConfig: ['maxSize' => 2048]));
		$this->assertSame(10 * 1024 * 1024, $rules->maxSizeBytes(fileConfig: ['fileConfiguration' => ['maxSize' => 10]]));
		$this->assertSame(0, $rules->maxSizeBytes(fileConfig: ['type' => 'file']));
	}//end testMaxSize()

	/**
	 * allowedTypes first, else the editor's allowedMimeTypes; non-strings dropped.
	 */
	public function testAllowedTypes(): void {
		$rules = new FilePropertyRules();

		$this->assertSame(['image/png'], $rules->allowedTypes(fileConfig: ['allowedTypes' => ['image/png', 3]]));
		$this->assertSame(['application/pdf'], $rules->allowedTypes(fileConfig: ['fileConfiguration' => ['allowedMimeTypes' => ['application/pdf']]]));
		$this->assertSame([], $rules->allowedTypes(fileConfig: []));
	}//end testAllowedTypes()

	/**
	 * The rule that governs a file: the property for `file`, its items for an array of files, none otherwise.
	 */
	public function testFileConfigOf(): void {
		$rules = new FilePropertyRules();

		$this->assertSame(['type' => 'file', 'maxSize' => 1], $rules->fileConfigOf(property: ['type' => 'file', 'maxSize' => 1]));
		$this->assertSame(['type' => 'file'], $rules->fileConfigOf(property: ['type' => 'array', 'items' => ['type' => 'file']]));
		$this->assertNull($rules->fileConfigOf(property: ['type' => 'string']));
	}//end testFileConfigOf()
}//end class
