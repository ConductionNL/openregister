<?php

/**
 * A file property's options are refused at schema save, with a sentence that names the entry.
 *
 * Every refusal names the property path and the offending entry, so an author
 * who saves a schema with a bad MIME type, an out-of-range size or a malformed
 * tag list reads which line to fix. A property without file options, or with
 * well-formed ones, passes.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Schemas
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Schemas;

use Exception;
use OCA\OpenRegister\Service\Schemas\FilePropertyOptionsValidator;
use PHPUnit\Framework\TestCase;

/**
 * The save-time guard on a file property's allowedTypes, maxSize, allowedTags and autoTags.
 *
 * @covers \OCA\OpenRegister\Service\Schemas\FilePropertyOptionsValidator
 */
final class FilePropertyOptionsValidatorTest extends TestCase {

	private FilePropertyOptionsValidator $validator;

	protected function setUp(): void {
		parent::setUp();
		$this->validator = new FilePropertyOptionsValidator();
	}//end setUp()

	/**
	 * Assert the property is refused with exactly this message.
	 *
	 * @param array<string, mixed> $property The property.
	 * @param string               $message  The expected message.
	 *
	 * @return void
	 */
	private function assertRefused(array $property, string $message): void {
		try {
			$this->validator->validate(property: $property, path: 'attachment');
		} catch (Exception $e) {
			$this->assertSame($message, $e->getMessage());
			return;
		}

		$this->fail('Expected a refusal: '.$message);
	}//end assertRefused()

	/**
	 * No file options, or well-formed ones, pass.
	 *
	 * @return void
	 */
	public function testWellFormedOptionsPass(): void {
		$this->assertTrue($this->validator->validate(property: [], path: 'attachment'));
		$this->assertTrue(
			$this->validator->validate(
				property: [
					'allowedTypes' => ['application/pdf', 'text/plain', 'application/vnd.ms-excel'],
					'maxSize' => '104857600',
					'allowedTags' => ['contract', 'signed'],
					'autoTags' => ['inbox'],
				],
				path: 'attachment'
			)
		);
		$this->assertTrue($this->validator->validate(property: ['maxSize' => 0, 'allowedTypes' => null], path: 'attachment'));
	}//end testWellFormedOptionsPass()

	/**
	 * allowedTypes must be a list of strings in type/subtype form.
	 *
	 * @return void
	 */
	public function testAllowedTypesRefusals(): void {
		$this->assertRefused(property: ['allowedTypes' => 'application/pdf'], message: "'allowedTypes' at 'attachment' must be an array");
		$this->assertRefused(property: ['allowedTypes' => ['application/pdf', 7]], message: "'allowedTypes[1]' at 'attachment' must be a string");
		$this->assertRefused(property: ['allowedTypes' => ['pdf']], message: "'allowedTypes[0]' at 'attachment' contains invalid MIME type format: 'pdf'");
		$this->assertRefused(property: ['allowedTypes' => ['application/']], message: "'allowedTypes[0]' at 'attachment' contains invalid MIME type format: 'application/'");
	}//end testAllowedTypesRefusals()

	/**
	 * maxSize must be numeric, not negative and at most 100 MB; the 100 MB edge passes.
	 *
	 * @return void
	 */
	public function testMaxSizeRefusals(): void {
		$this->assertRefused(property: ['maxSize' => 'big'], message: "'maxSize' at 'attachment' must be a numeric value");
		$this->assertRefused(property: ['maxSize' => -1], message: "'maxSize' at 'attachment' must be a positive number");
		$this->assertRefused(property: ['maxSize' => 104857601], message: "'maxSize' at 'attachment' exceeds maximum allowed size (100MB)");
	}//end testMaxSizeRefusals()

	/**
	 * allowedTags and autoTags must be lists of non-empty strings of at most 50 characters.
	 *
	 * @return void
	 */
	public function testTagListRefusals(): void {
		$this->assertRefused(property: ['allowedTags' => 'x'], message: "'allowedTags' at 'attachment' must be an array");
		$this->assertRefused(property: ['autoTags' => ['ok', 3]], message: "'autoTags[1]' at 'attachment' must be a string");
		$this->assertRefused(property: ['allowedTags' => ['  ']], message: "'allowedTags[0]' at 'attachment' cannot be empty");
		$this->assertRefused(property: ['autoTags' => [str_repeat('a', 51)]], message: "'autoTags[0]' at 'attachment' exceeds maximum length (50 characters)");
		$this->assertTrue($this->validator->validate(property: ['autoTags' => [str_repeat('a', 50)]], path: 'attachment'));
	}//end testTagListRefusals()

	/**
	 * The types are checked before the size, and the size before the tags.
	 *
	 * @return void
	 */
	public function testRefusalOrder(): void {
		$this->assertRefused(
			property: ['allowedTypes' => ['pdf'], 'maxSize' => -1, 'allowedTags' => ['']],
			message: "'allowedTypes[0]' at 'attachment' contains invalid MIME type format: 'pdf'"
		);
		$this->assertRefused(
			property: ['maxSize' => -1, 'allowedTags' => ['']],
			message: "'maxSize' at 'attachment' must be a positive number"
		);
	}//end testRefusalOrder()
}//end class
