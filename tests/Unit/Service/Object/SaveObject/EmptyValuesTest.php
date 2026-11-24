<?php

/**
 * EmptyValues: what an empty value means on save, and when an object carries no content.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object\SaveObject
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/unit-test-coverage/tasks.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object\SaveObject;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Object\SaveObject\EmptyValues;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Object\SaveObject\EmptyValues
 * @uses   \OCA\OpenRegister\Db\Schema
 */
class EmptyValuesTest extends TestCase {

	private function values(): EmptyValues {
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('getAbsoluteURL')->willReturnArgument(0);
		$urls->method('linkToRoute')->willReturn('/schemas/1');

		return new EmptyValues($urls);
	}

	private function schema(): Schema {
		$schema = new Schema();
		$schema->setTitle('Case');
		$schema->setProperties([
			'title'    => ['type' => 'string'],
			'code'     => ['type' => 'string', 'required' => true],
			'amount'   => ['type' => 'number'],
			'urgent'   => ['type' => 'boolean'],
			'address'  => ['type' => 'object'],
			'contact'  => ['type' => 'object'],
			'tags'     => ['type' => 'array'],
			'note'     => ['type' => 'string'],
		]);
		$schema->setRequired(['note']);

		return $schema;
	}

	public function testAnEmptyOptionalScalarOrObjectIsCleared(): void {
		$clean = $this->values()->sanitize(['title' => '', 'amount' => '', 'urgent' => '', 'address' => []], $this->schema());

		$this->assertSame(['title' => null, 'amount' => null, 'urgent' => null, 'address' => null], $clean);
	}

	public function testAnEmptyStringTheSchemaRequiresIsKeptForTheValidatorToRefuse(): void {
		$clean = $this->values()->sanitize(['note' => ''], $this->schema());

		$this->assertSame(['note' => ''], $clean);
	}

	public function testAPropertyOnlyFlaggedRequiredIsClearedLikeAnOptionalOne(): void {
		// The schema object this reads keeps the schema's required list and drops
		// a property's own `required: true`, so such a property reads as optional
		// here; the validator still refuses the null that results.
		$clean = $this->values()->sanitize(['code' => ''], $this->schema());

		$this->assertSame(['code' => null], $clean);
	}

	public function testEmptyStringsInsideAnArrayBecomeNull(): void {
		$clean = $this->values()->sanitize(['tags' => ['a', '', 'b']], $this->schema());

		$this->assertSame(['tags' => ['a', null, 'b']], $clean);
	}

	public function testFilledValuesAndUndeclaredFieldsAreLeftAlone(): void {
		$data  = ['title' => 'Vergunning', 'amount' => 0, 'urgent' => false, 'contact' => ['name' => 'Jan'], 'extra' => ''];
		$clean = $this->values()->sanitize($data, $this->schema());

		$this->assertSame($data, $clean);
	}

	public function testAnObjectWithOnlyMetadataOrBlanksIsEmpty(): void {
		$values = $this->values();

		$this->assertTrue($values->isEmptyObject(['@self' => ['id' => 'x'], 'id' => 'x', '_id' => 1]));
		$this->assertTrue($values->isEmptyObject(['name' => '  ', 'tags' => ['', null], 'nested' => ['inner' => '']]));
		$this->assertFalse($values->isEmptyObject(['name' => 'Jan']));
		$this->assertFalse($values->isEmptyObject(['tags' => ['', 'x']]));
		$this->assertFalse($values->isEmptyObject(['nested' => ['inner' => 'x']]));
		$this->assertFalse($values->isEmptyObject(['count' => 0]), 'zero is content');
		$this->assertFalse($values->isEmptyObject(['flag' => false]), 'false is content');
	}

	public function testContentIsAnyNonNullScalarOrANonBlankString(): void {
		$values = $this->values();

		$this->assertFalse($values->hasContent(null));
		$this->assertFalse($values->hasContent(" \t"));
		$this->assertTrue($values->hasContent('x'));
		$this->assertTrue($values->hasContent(0));
		$this->assertFalse($values->hasContent([]));
	}
}
