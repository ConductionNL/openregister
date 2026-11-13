<?php

/**
 * Unit tests for {@see \OCA\OpenRegister\Service\Oas\OasNames}.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Oas
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Oas;

use OCA\OpenRegister\Service\Oas\OasNames;
use PHPUnit\Framework\TestCase;

/**
 * Naming rules for generated OpenAPI identifiers.
 *
 * @covers \OCA\OpenRegister\Service\Oas\OasNames
 */
class OasNamesTest extends TestCase {

	/**
	 * Runs of characters other than alphanumerics and dashes become one dash, trimmed and lower-cased.
	 *
	 * @return void
	 */
	public function testSlug(): void {
		$names = new OasNames();

		$this->assertSame('my-register-2', $names->slug('  My Register #2!! '));
		$this->assertSame('a-b--c', $names->slug('a__b--c'));
		$this->assertSame('', $names->slug('***'));

	}//end testSlug()

	/**
	 * PascalCase is built from the slug's dash-separated words.
	 *
	 * @return void
	 */
	public function testPascalCase(): void {
		$names = new OasNames();

		$this->assertSame('MyRegister2', $names->pascalCase('my register 2'));
		$this->assertSame('PublicationsArchive', $names->pascalCase('Publications_ARCHIVE'));

	}//end testPascalCase()

	/**
	 * Schema names keep dots, dashes and underscores, collapse other characters, and never start with a digit.
	 *
	 * @return void
	 */
	public function testSchemaName(): void {
		$names = new OasNames();

		$this->assertSame('Person', $names->schemaName('Person'));
		$this->assertSame('Zaak_type.v1-b', $names->schemaName('Zaak type.v1-b'));
		$this->assertSame('a_b', $names->schemaName('__a (& b)__'));
		$this->assertSame('Schema_2024_Report', $names->schemaName('2024 Report'));

	}//end testSchemaName()

	/**
	 * Empty, null and all-symbol titles fall back to UnknownSchema.
	 *
	 * @return void
	 */
	public function testSchemaNameFallback(): void {
		$names = new OasNames();

		$this->assertSame('UnknownSchema', $names->schemaName(null));
		$this->assertSame('UnknownSchema', $names->schemaName(''));
		$this->assertSame('UnknownSchema', $names->schemaName('!!!'));

	}//end testSchemaNameFallback()
}//end class
