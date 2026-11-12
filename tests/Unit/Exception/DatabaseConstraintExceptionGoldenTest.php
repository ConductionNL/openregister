<?php

/**
 * Golden test: every database error class maps to the same user sentence.
 *
 * Pins the sentence `fromDatabaseException` derives from each recognised
 * error shape, in the order the shapes are tried, so the mapping can move to
 * a table without changing which sentence a user reads.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Exception
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Exception;

use Exception;
use OCA\OpenRegister\Exception\DatabaseConstraintException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The database-error-to-sentence mapping, case by case.
 *
 * @covers \OCA\OpenRegister\Exception\DatabaseConstraintException
 */
class DatabaseConstraintExceptionGoldenTest extends TestCase {
	/**
	 * Error message in, sentence out.
	 *
	 * @return array<string, array{0: string, 1: string}> The cases.
	 */
	public static function cases(): array {
		return [
			'schema slug, old index' => [
				"Duplicate entry 'x' for key 'schemas_organisation_slug_unique'",
				'A schema with this slug already exists for this application in your organization. Please choose a different slug or title.',
			],
			'schema slug, new index' => [
				"Duplicate entry 'x' for key 'schemas_org_app_slug_unique'",
				'A schema with this slug already exists for this application in your organization. Please choose a different slug or title.',
			],
			'register slug, old index' => [
				"Duplicate entry 'x' for key 'registers_organisation_slug_unique'",
				'A register with this slug already exists for this application in your organization. Please choose a different slug or title.',
			],
			'register slug, new index' => [
				"Duplicate entry 'x' for key 'registers_org_app_slug_unique'",
				'A register with this slug already exists for this application in your organization. Please choose a different slug or title.',
			],
			'other unique key' => [
				"Duplicate entry 'x' for key 'uuid_unique'",
				'This widget already exists. Please check your input and try again.',
			],
			'duplicate, no unique' => [
				"Duplicate entry 'x' for key 'PRIMARY'",
				'A widget with these details already exists. Please modify your input and try again.',
			],
			'duplicate without key' => [
				"Duplicate entry 'x' NOT NULL",
				'Required information is missing. Please fill in all required fields and try again.',
			],
			'foreign key, lower' => [
				'a foreign key constraint fails',
				"This widget cannot be saved because it references data that doesn't exist. Please check your configuration and try again.",
			],
			'foreign key, upper' => [
				'FOREIGN KEY (x) CHECK',
				"This widget cannot be saved because it references data that doesn't exist. Please check your configuration and try again.",
			],
			'not null, column' => [
				"Column 'title' cannot be null",
				'Required information is missing. Please fill in all required fields and try again.',
			],
			'check, lower' => [
				'check constraint failed',
				"The provided data doesn't meet the required format or constraints. Please check your input and try again.",
			],
			'check, upper' => [
				'CHECK failed, too long',
				"The provided data doesn't meet the required format or constraints. Please check your input and try again.",
			],
			'data too long' => [
				"Data too long for column 'title'",
				'Some of the provided information is too long. Please shorten your input and try again.',
			],
			'value too long' => [
				'SQLSTATE[22001]: value too long',
				'Some of the provided information is too long. Please shorten your input and try again.',
			],
			'sqlstate' => [
				'SQLSTATE[HY000]: General error',
				'There was a problem saving your widget. Please check your input and try again.',
			],
			'anything else' => [
				'the server went away',
				'There was a database error while saving your widget. Please try again or contact support if the problem persists.',
			],
		];

	}//end cases()

	/**
	 * One error shape maps to its sentence, keeping code, status and cause.
	 *
	 * @param string $dbMessage The database error message.
	 * @param string $expected The sentence the user reads.
	 *
	 * @return void
	 */
	#[DataProvider('cases')]
	public function testTheSentenceOfEachErrorShape(string $dbMessage, string $expected): void {
		$cause = new Exception($dbMessage, 23000);
		$exception = DatabaseConstraintException::fromDatabaseException($cause, 'widget');

		$this->assertSame($expected, $exception->getMessage());
		$this->assertSame(23000, $exception->getCode());
		$this->assertSame($cause, $exception->getPrevious());

	}//end testTheSentenceOfEachErrorShape()

	/**
	 * The default entity type is "item".
	 *
	 * @return void
	 */
	public function testTheDefaultEntityTypeIsItem(): void {
		$exception = DatabaseConstraintException::fromDatabaseException(new Exception('SQLSTATE[HY000]'));

		$this->assertSame('There was a problem saving your item. Please check your input and try again.', $exception->getMessage());

	}//end testTheDefaultEntityTypeIsItem()

}//end class
