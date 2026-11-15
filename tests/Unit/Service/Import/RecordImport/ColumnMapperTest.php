<?php

/**
 * ColumnMapper: a proposed mapping, an explicit confirmation, and the create-only statement.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Import\RecordImport
 * @author   Conduction <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/openregister
 *
 * @spec openspec/changes/widget-record-import/specs/widget-record-import/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Import\RecordImport;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Service\Import\RecordImport\ColumnMapper;
use OCA\OpenRegister\Service\Import\RecordImport\UnconfirmedMappingException;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Import\RecordImport\ColumnMapper
 * @covers \OCA\OpenRegister\Service\Import\RecordImport\ConfirmedMapping
 * @covers \OCA\OpenRegister\Service\Import\RecordImport\UnconfirmedMappingException
 */
class ColumnMapperTest extends TestCase {

	private const PROPERTIES = ['reference', 'streetName', 'houseNumber', 'city'];

	public function testExactThenLooseNamesAreProposedAndTheRestIsUnmatched(): void {
		$proposal = (new ColumnMapper())->propose(['City', 'reference', 'street_name', 'House Number', 'postcode'], self::PROPERTIES);

		$this->assertSame(
			['City' => 'city', 'reference' => 'reference', 'street_name' => 'streetName', 'House Number' => 'houseNumber'],
			$proposal['mapped']
		);
		$this->assertSame(['postcode'], $proposal['unmatched']);
	}

	public function testAPropertyIsProposedOnceAndAnAmbiguousNameIsLeftToTheUser(): void {
		$proposal = (new ColumnMapper())->propose(['city', 'CITY', 'name'], ['city', 'Name', 'NAME']);

		$this->assertSame(['city' => 'city'], $proposal['mapped']);
		$this->assertSame(['CITY', 'name'], $proposal['unmatched']);
	}

	public function testAnUndecidedColumnRefusesTheConfirmation(): void {
		try {
			(new ColumnMapper())->confirm(['reference', 'postcode', 'note'], self::PROPERTIES, ['reference' => 'reference']);
			$this->fail('an undecided column must refuse the import');
		} catch (UnconfirmedMappingException $e) {
			$this->assertSame(['postcode', 'note'], $e->columns);
			$this->assertSame('Map or ignore every column before importing: postcode, note.', $e->getMessage());
		}
	}

	public function testAnIgnoredColumnIsADecision(): void {
		$mapping = (new ColumnMapper())->confirm(['reference', 'postcode'], self::PROPERTIES, ['reference' => 'reference', 'postcode' => null]);

		$this->assertSame(['reference' => 'reference'], $mapping->map);
		$this->assertSame(['postcode'], $mapping->ignored);
	}

	public function testAMappingToAPropertyTheSchemaLacksOrTwiceIsRefused(): void {
		$mapper = new ColumnMapper();
		foreach ([
			[['a' => 'nope'], 'Column "a" is mapped to "nope", which the schema does not have.'],
			[['a' => 'city', 'b' => 'city'], 'Two columns are mapped to "city".'],
		] as [$decisions, $message]) {
			try {
				$mapper->confirm(array_keys($decisions), self::PROPERTIES, $decisions);
				$this->fail($message);
			} catch (UnconfirmedMappingException $e) {
				$this->assertSame($message, $e->getMessage());
			}
		}

		$this->expectExceptionMessage('The identifying property "city" is not mapped from any column.');
		$mapper->confirm(['a'], self::PROPERTIES, ['a' => 'reference'], 'city');
	}

	public function testWithoutAnIdentifierTheImportSaysItCreatesEveryRow(): void {
		$mapping = (new ColumnMapper())->confirm(['reference'], self::PROPERTIES, ['reference' => 'reference']);

		$this->assertTrue($mapping->createsOnly());
		$this->assertNull($mapping->identifier);
		$this->assertSame('No identifying column is nominated: every row will be created as a new object.', $mapping->statement());
	}

	public function testWithAnIdentifierTheImportSaysItUpdates(): void {
		$mapping = (new ColumnMapper())->confirm(['ref'], self::PROPERTIES, ['ref' => 'reference'], 'reference');

		$this->assertFalse($mapping->createsOnly());
		$this->assertSame('A row whose "reference" matches an existing object updates it; any other row is created.', $mapping->statement());
	}

	public function testARowIsRenamedAndItsIgnoredAndEmptyCellsLeftOut(): void {
		$mapping = (new ColumnMapper())->confirm(
			['ref', 'Plaats', 'postcode', 'nr'],
			self::PROPERTIES,
			['ref' => 'reference', 'Plaats' => 'city', 'postcode' => null, 'nr' => 'houseNumber']
		);

		$this->assertSame(
			['reference' => 'A-1', 'houseNumber' => '0'],
			$mapping->apply(['ref' => 'A-1', 'Plaats' => '', 'postcode' => '1234AB', 'nr' => '0'])
		);
	}
}
