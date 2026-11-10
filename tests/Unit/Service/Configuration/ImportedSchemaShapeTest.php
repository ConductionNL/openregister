<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Configuration;

use OCA\OpenRegister\Service\Configuration\ImportedSchemaShape;
use OCA\OpenRegister\Service\Oas\OasRequestValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use stdClass;

/**
 * What an imported schema must look like, and how its properties are normalised.
 *
 * @covers \OCA\OpenRegister\Service\Configuration\ImportedSchemaShape
 */
class ImportedSchemaShapeTest extends TestCase {

	/**
	 * A shape check with no validator wired.
	 *
	 * @return ImportedSchemaShape
	 */
	private function shape(): ImportedSchemaShape {
		return new ImportedSchemaShape(validator: null, logger: $this->createMock(LoggerInterface::class));
	}//end shape()

	/**
	 * Properties as they arrive, with the shape they leave in.
	 *
	 * @return array<string, array{0: mixed, 1: array<string, mixed>}>
	 */
	public static function properties(): array {
		return [
			'an empty property'      => [[], ['title' => 'name', 'type' => 'string']],
			'nothing at all'         => [null, ['title' => 'name', 'type' => 'string']],
			'an object'              => [(object) ['type' => 'integer'], ['type' => 'integer', 'title' => 'name']],
			'its own title'          => [['title' => 'Own', 'type' => 'integer'], ['title' => 'Own', 'type' => 'integer']],
			'a falsy title'          => [['title' => '0', 'type' => 'integer'], ['title' => 'name', 'type' => 'integer']],
			'a null type'            => [['title' => 'T', 'type' => null], ['title' => 'T', 'type' => 'string']],
			'format string'          => [['title' => 'T', 'format' => 'string'], ['title' => 'T', 'type' => 'string']],
			'format binary'          => [['title' => 'T', 'format' => 'binary'], ['title' => 'T', 'type' => 'string']],
			'format byte'            => [['title' => 'T', 'format' => 'byte'], ['title' => 'T', 'type' => 'string']],
			'format date'            => [['title' => 'T', 'format' => 'date'], ['title' => 'T', 'format' => 'date', 'type' => 'string']],
			'item format byte'       => [
				['title' => 'T', 'items' => ['format' => 'byte', 'type' => 'string']],
				['title' => 'T', 'items' => ['type' => 'string'], 'type' => 'string'],
			],
			'items as an object'     => [
				['title' => 'T', 'items' => (object) ['format' => 'binary']],
				['title' => 'T', 'items' => [], 'type' => 'string'],
			],
			'items that are text'    => [
				['title' => 'T', 'items' => 'text'],
				['title' => 'T', 'items' => 'text', 'type' => 'string'],
			],
			'items that are null'    => [
				['title' => 'T', 'items' => null],
				['title' => 'T', 'items' => null, 'type' => 'string'],
			],
			'a filled configuration' => [
				['title' => 'T', 'objectConfiguration' => ['handling' => 'related']],
				['title' => 'T', 'objectConfiguration' => ['handling' => 'related'], 'type' => 'string'],
			],
		];
	}//end properties()

	/**
	 * Each property leaves with its expected shape, key order included.
	 *
	 * @param mixed                $property The property as it arrives.
	 * @param array<string, mixed> $expected The property as it leaves.
	 *
	 * @return void
	 */
	#[DataProvider('properties')]
	public function testAPropertyLeavesInItsExpectedShape(mixed $property, array $expected): void {
		$this->assertSame($expected, $this->shape()->normalisedProperty(key: 'name', property: $property));
	}//end testAPropertyLeavesInItsExpectedShape()

	/**
	 * Empty configurations of a property and its items become objects, so they encode as `{}`.
	 *
	 * @return void
	 */
	public function testEmptyConfigurationsBecomeObjects(): void {
		$out = $this->shape()->normalisedProperty(
			key: 7,
			property: ['objectConfiguration' => [], 'fileConfiguration' => [], 'items' => ['objectConfiguration' => [], 'fileConfiguration' => []]]
		);

		$this->assertSame(7, $out['title']);
		$this->assertInstanceOf(stdClass::class, $out['objectConfiguration']);
		$this->assertInstanceOf(stdClass::class, $out['fileConfiguration']);
		$this->assertInstanceOf(stdClass::class, $out['items']['objectConfiguration']);
		$this->assertInstanceOf(stdClass::class, $out['items']['fileConfiguration']);
	}//end testEmptyConfigurationsBecomeObjects()

	/**
	 * Without a validator the shape check stands down.
	 *
	 * @return void
	 */
	public function testWithoutAValidatorAnyShapePasses(): void {
		$this->shape()->assertShape(data: ['properties' => 'not a map']);
		$this->addToAssertionCount(1);
	}//end testWithoutAValidatorAnyShapePasses()

	/**
	 * The validator is asked about the data against the minimal meta-schema.
	 *
	 * @return void
	 */
	public function testTheValidatorGetsTheMinimalMetaSchema(): void {
		$validator = $this->createMock(OasRequestValidator::class);
		$validator->expects($this->once())->method('validate')->with(
			['properties' => []],
			[
				'type' => 'object',
				'required' => ['properties'],
				'properties' => [
					'title' => ['type' => 'string'],
					'description' => ['type' => 'string'],
					'slug' => ['type' => 'string'],
					'properties' => ['type' => 'object'],
					'required' => ['type' => 'array', 'items' => ['type' => 'string']],
				],
			]
		)->willReturn([]);

		(new ImportedSchemaShape(validator: $validator, logger: $this->createMock(LoggerInterface::class)))->assertShape(data: ['properties' => []]);
	}//end testTheValidatorGetsTheMinimalMetaSchema()

	/**
	 * Every validation error is named in the refusal.
	 *
	 * @return void
	 */
	public function testAnInvalidShapeIsRefusedNamingEveryError(): void {
		$validator = $this->createMock(OasRequestValidator::class);
		$validator->method('validate')->willReturn([['path' => '/a', 'message' => 'is wrong'], ['path' => '/b', 'message' => 'is missing']]);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('imported schema fails OAS-shape validation: /a is wrong; /b is missing');

		(new ImportedSchemaShape(validator: $validator, logger: $this->createMock(LoggerInterface::class)))->assertShape(data: []);
	}//end testAnInvalidShapeIsRefusedNamingEveryError()

	/**
	 * A usable slug passes and logs nothing.
	 *
	 * @return void
	 */
	public function testASlugPasses(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('error');

		(new ImportedSchemaShape(validator: null, logger: $logger))->assertSlug(data: ['slug' => 'person']);
	}//end testASlugPasses()

	/**
	 * Fragments without a usable slug, with the refusal each gets.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: string}>
	 */
	public static function slugless(): array {
		return [
			'no slug, no title'    => [[], "imported schema fragment is missing a 'slug' (no title); skipping fragment"],
			'an empty title'       => [['title' => ''], "imported schema fragment is missing a 'slug' (no title); skipping fragment"],
			'no slug, a title'     => [['title' => 'Person'], "imported schema fragment is missing a 'slug' (title 'Person'); skipping fragment"],
			'a blank slug'         => [['slug' => '  '], "imported schema fragment is missing a 'slug' (no title); skipping fragment"],
			'a slug that is a number' => [['slug' => 5], "imported schema fragment is missing a 'slug' (no title); skipping fragment"],
		];
	}//end slugless()

	/**
	 * A fragment without a usable slug is logged and refused with the same message.
	 *
	 * @param array<string, mixed> $data    The fragment.
	 * @param string               $message The refusal.
	 *
	 * @return void
	 */
	#[DataProvider('slugless')]
	public function testAFragmentWithoutASlugIsLoggedAndRefused(array $data, string $message): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error')->with('[ImportHandler] '.$message, $this->isType('array'));

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage($message);

		(new ImportedSchemaShape(validator: null, logger: $logger))->assertSlug(data: $data);
	}//end testAFragmentWithoutASlugIsLoggedAndRefused()
}//end class
