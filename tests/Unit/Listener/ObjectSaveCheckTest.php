<?php

declare(strict_types=1);

/**
 * The coded-value and dependent-value listeners answer check() on a sample,
 * with the same decision their handle() takes on a write.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/records-validate-without-saving/specs/objects-crud/spec.md#requirement-a-record-can-be-validated-without-saving-it
 */

namespace OCA\OpenRegister\Tests\Unit\Listener;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaCatalogInterface;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Exception\CodedValueException;
use OCA\OpenRegister\Listener\CodedValueValidationListener;
use OCA\OpenRegister\Listener\DependentValueListener;
use OCA\OpenRegister\Listener\Support\EventSchemaLookup;
use OCA\OpenRegister\Service\Object\ObjectSaveChecks;
use OCA\OpenRegister\Service\Rules\DependentValueTableInterface;
use OCA\OpenRegister\Service\Rules\DependentValueWrite;
use OCA\OpenRegister\Service\Vocabulary\CodedValueGuardInterface;
use OCA\OpenRegister\Service\Vocabulary\CodedValueWrite;
use OCA\OpenRegister\Service\Vocabulary\ConceptShapeGuardInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

#[CoversClass(CodedValueValidationListener::class)]
#[CoversClass(DependentValueListener::class)]
#[CoversClass(ObjectSaveChecks::class)]
#[UsesClass(EventSchemaLookup::class)]
#[UsesClass(CodedValueException::class)]
#[UsesClass(CodedValueWrite::class)]
#[UsesClass(DependentValueWrite::class)]
final class ObjectSaveCheckTest extends TestCase {

	private function schema(): Schema {
		$schema = new Schema();
		$schema->setProperties(['gemeente' => ['type' => 'string'], 'wijk' => ['type' => 'string']]);

		return $schema;
	}

	private function coded(CodedValueGuardInterface $guard, ?ConceptShapeGuardInterface $shapes=null, ?LoggerInterface $logger=null): CodedValueValidationListener {
		return new CodedValueValidationListener(
			$guard,
			$shapes ?? $this->createMock(ConceptShapeGuardInterface::class),
			$this->createMock(SchemaMapperInterface::class),
			$logger ?? $this->createMock(LoggerInterface::class)
		);
	}

	private function dependent(DependentValueTableInterface $tables, ?LoggerInterface $logger=null): DependentValueListener {
		return new DependentValueListener($tables, new EventSchemaLookup($this->createMock(SchemaCatalogInterface::class), $logger ?? $this->createMock(LoggerInterface::class)));
	}

	public function testACodedValueTheGuardRefusesIsNamed(): void {
		$guard = $this->createMock(CodedValueGuardInterface::class);
		$guard->expects($this->once())->method('enforce')
			->with($this->callback(static fn (CodedValueWrite $write): bool => $write->object === ['gemeente' => 'Atlantis']))
			->willThrowException(new CodedValueException('refused', ['gemeente' => 'Atlantis is not in the list.']));

		$this->assertSame(
			[['property' => 'gemeente', 'message' => 'Atlantis is not in the list.', 'rule' => 'coded-value']],
			$this->coded($guard)->check(['gemeente' => 'Atlantis'], $this->schema(), null)
		);
	}

	public function testAConceptShapeRefusalComesFirstAndIsNamed(): void {
		$guard = $this->createMock(CodedValueGuardInterface::class);
		$guard->expects($this->never())->method('enforce');
		$shapes = $this->createMock(ConceptShapeGuardInterface::class);
		$shapes->method('violations')->willReturn(['notation' => 'notation is required.']);

		$this->assertSame(
			[['property' => 'notation', 'message' => 'notation is required.', 'rule' => 'concept-shape']],
			$this->coded($guard, $shapes)->check([], $this->schema(), null)
		);
	}

	public function testAFailingCodedValueGuardRefusesNothingAsTheSaveGoesAhead(): void {
		$guard = $this->createMock(CodedValueGuardInterface::class);
		$guard->method('enforce')->willThrowException(new RuntimeException('vocabulary unreadable'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with($this->stringContains('allowing the save'));

		$this->assertSame([], $this->coded($guard, null, $logger)->check(['gemeente' => 'X'], $this->schema(), null));
	}

	public function testADependentValueTheTableRefusesIsNamedAgainstTheStoredObject(): void {
		$tables = $this->createMock(DependentValueTableInterface::class);
		$tables->method('declaresAny')->willReturn(true);
		$tables->expects($this->once())->method('violations')
			->with($this->anything(), $this->callback(static fn (DependentValueWrite $w): bool => $w->proposed('wijk') === 'Zuid' && $w->controllingValue('gemeente') === 'Utrecht'))
			->willReturn([['property' => 'wijk', 'controlledBy' => 'gemeente', 'value' => 'Zuid', 'controllingValue' => 'Utrecht', 'message' => 'Not allowed.']]);
		$stored = new ObjectEntity();
		$stored->setObject(['gemeente' => 'Utrecht']);

		$this->assertSame(
			[['property' => 'wijk', 'message' => 'Not allowed.', 'rule' => 'dependent-value']],
			$this->dependent($tables)->check(['wijk' => 'Zuid'], $this->schema(), $stored)
		);
	}

	public function testASchemaWithoutATableIsNotAskedAnything(): void {
		$tables = $this->createMock(DependentValueTableInterface::class);
		$tables->method('declaresAny')->willReturn(false);
		$tables->expects($this->never())->method('violations');

		$this->assertSame([], $this->dependent($tables)->check(['wijk' => 'Zuid'], $this->schema(), null));
	}

	public function testAFailingTableRefusesNothingAsTheSaveGoesAhead(): void {
		$tables = $this->createMock(DependentValueTableInterface::class);
		$tables->method('declaresAny')->willReturn(true);
		$tables->method('violations')->willThrowException(new RuntimeException('table unreadable'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with($this->stringContains('allowing the save'));

		$this->assertSame([], $this->dependent($tables, $logger)->check(['wijk' => 'Zuid'], $this->schema(), null));
	}

	public function testTheRegistryRunsBothInRegistrationOrder(): void {
		$guard = $this->createMock(CodedValueGuardInterface::class);
		$guard->method('enforce')->willThrowException(new CodedValueException('refused', ['gemeente' => 'coded']));
		$tables = $this->createMock(DependentValueTableInterface::class);
		$tables->method('declaresAny')->willReturn(true);
		$tables->method('violations')->willReturn([['property' => 'wijk', 'message' => 'dependent']]);
		$checks = new ObjectSaveChecks($this->coded($guard), $this->dependent($tables));

		$this->assertSame(['coded-value', 'dependent-value'], array_column($checks->violations(['gemeente' => 'X'], $this->schema(), null), 'rule'));
		$this->assertCount(2, $checks->all());
	}
}
