<?php

/**
 * Each audit export format names its file and MIME type.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/audit-trail-immutable/spec.md#requirement-the-audit-trail-must-be-exportable-for-compliance-audits
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Service\LogExportFormatter;
use PHPUnit\Framework\TestCase;

/**
 * Pins the file name and MIME type LogExportFormatter::render() gives each format.
 */
class LogExportFormatterFileTest extends TestCase {

	/**
	 * The formats and the extension and MIME type each renders to.
	 *
	 * @return array<string, array{string, string, string}> Format, extension, MIME type.
	 */
	public static function formats(): array {
		return [
			'csv' => ['csv', 'csv', 'text/csv'],
			'json' => ['JSON', 'json', 'application/json'],
			'xml' => ['xml', 'xml', 'application/xml'],
			'txt' => ['txt', 'txt', 'text/plain'],
		];
	}//end formats()

	/**
	 * A format renders to a file with its extension and MIME type.
	 *
	 * @dataProvider formats
	 *
	 * @param string $format      The asked format.
	 * @param string $extension   The expected file extension.
	 * @param string $contentType The expected MIME type.
	 *
	 * @return void
	 */
	public function testAFormatNamesItsFileAndMimeType(string $format, string $extension, string $contentType): void {
		$file = (new LogExportFormatter())->render($format, [['id' => 1, 'action' => 'create']]);

		$this->assertSame($contentType, $file['contentType']);
		$this->assertMatchesRegularExpression('/^audit_trails_\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2}\.' . $extension . '$/', $file['filename']);
	}//end testAFormatNamesItsFileAndMimeType()
}//end class
