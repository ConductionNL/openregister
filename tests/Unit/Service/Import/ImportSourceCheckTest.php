<?php

/**
 * Unit tests for ImportSourceCheck — the source-file guards of an import preview.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Import
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/import-preview-and-conflict-policy/specs/data-import-export/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Import;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use InvalidArgumentException;
use OCA\OpenRegister\Db\ImportPreview;
use OCA\OpenRegister\Service\Import\ImportPreviewRefusedException;
use OCA\OpenRegister\Service\Import\ImportSourceCheck;
use PHPUnit\Framework\TestCase;

final class ImportSourceCheckTest extends TestCase {

	private ?string $file = null;

	protected function tearDown(): void {
		if ($this->file !== null && is_file($this->file) === true) {
			unlink($this->file);
		}
	}

	private function preview(?string $hash): ImportPreview {
		$preview = new ImportPreview();
		$preview->setSourceHash($hash);
		return $preview;
	}

	public function testAnExistingFileIsReturned(): void {
		$this->file = (string)tempnam(sys_get_temp_dir(), 'or-import');

		$this->assertSame($this->file, ImportSourceCheck::requireFile(['filePath' => $this->file]));
	}

	public function testAMissingPathIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('A source file is required to preview an import.');

		ImportSourceCheck::requireFile([]);
	}

	public function testANonExistentFileIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);

		ImportSourceCheck::requireFile(['filePath' => sys_get_temp_dir().'/does-not-exist-'.uniqid()]);
	}

	public function testADirectoryIsNotAFile(): void {
		$this->expectException(InvalidArgumentException::class);

		ImportSourceCheck::requireFile(['filePath' => sys_get_temp_dir()]);
	}

	public function testTheSameHashPasses(): void {
		ImportSourceCheck::assertSameSource($this->preview('abc123'), 'abc123');

		$this->addToAssertionCount(1);
	}

	public function testAMissingHashIsRefused(): void {
		foreach ([null, ''] as $hash) {
			try {
				ImportSourceCheck::assertSameSource($this->preview('abc123'), $hash);
				$this->fail('Expected a refusal for an absent hash.');
			} catch (ImportPreviewRefusedException $e) {
				$this->assertStringContainsString('must name the source file', $e->getMessage());
			}
		}
	}

	public function testADifferentHashIsRefused(): void {
		$this->expectException(ImportPreviewRefusedException::class);
		$this->expectExceptionMessage('The source file changed since it was previewed');

		ImportSourceCheck::assertSameSource($this->preview('abc123'), 'def456');
	}

	public function testAPreviewWithoutHashRefusesAnyOfferedHash(): void {
		$this->expectException(ImportPreviewRefusedException::class);

		ImportSourceCheck::assertSameSource($this->preview(null), 'abc123');
	}
}
