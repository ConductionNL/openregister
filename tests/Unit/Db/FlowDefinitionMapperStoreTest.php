<?php

/**
 * FlowDefinitionMapper::store(): reuse, insert and the lost-race re-read.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/flow-definition-versioning/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use DateTime;
use OCA\OpenRegister\Db\FlowDefinition;
use OCA\OpenRegister\Db\FlowDefinitionMapper;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class FlowDefinitionMapperStoreTest extends TestCase {
	/**
	 * A mapper whose reads and inserts are doubled.
	 *
	 * @param array<int, FlowDefinition|null> $reads The successive findByHash() answers.
	 *
	 * @return FlowDefinitionMapper&MockObject
	 */
	private function mapper(array $reads): FlowDefinitionMapper {
		$mapper = $this->getMockBuilder(FlowDefinitionMapper::class)
			->disableOriginalConstructor()
			->onlyMethods(['findByHash', 'insert'])
			->getMock();
		$mapper->method('findByHash')->with('h-1')->willReturnOnConsecutiveCalls(...$reads);

		return $mapper;
	}//end mapper()

	public function testAStoredHashIsReturnedWithoutAnInsert(): void {
		$existing = new FlowDefinition();
		$mapper = $this->mapper([$existing]);
		$mapper->expects($this->never())->method('insert');

		self::assertSame($existing, $this->store($mapper));
	}//end testAStoredHashIsReturnedWithoutAnInsert()

	public function testANewHashIsInsertedWithItsDefinitionProvenanceAndCreated(): void {
		$mapper = $this->mapper([null]);
		$mapper->expects($this->once())->method('insert')->willReturnArgument(0);

		$stored = $this->store($mapper);

		self::assertSame('h-1', $stored->getHash());
		self::assertSame('{"nodes":[]}', $stored->getDefinition());
		self::assertSame('flow-1', $stored->getFlowUuid());
		self::assertInstanceOf(DateTime::class, $stored->getCreated());
	}//end testANewHashIsInsertedWithItsDefinitionProvenanceAndCreated()

	public function testALostInsertReReadsTheHash(): void {
		$winner = new FlowDefinition();
		$mapper = $this->mapper([null, $winner]);
		$mapper->method('insert')->willThrowException(new RuntimeException('unique violation'));

		self::assertSame($winner, $this->store($mapper));
	}//end testALostInsertReReadsTheHash()

	public function testAFailedInsertWithNothingStoredIsNull(): void {
		$mapper = $this->mapper([null, null]);
		$mapper->method('insert')->willThrowException(new RuntimeException('disk full'));

		self::assertNull($this->store($mapper));
	}//end testAFailedInsertWithNothingStoredIsNull()

	/**
	 * Store the fixed definition through the mapper.
	 *
	 * @param FlowDefinitionMapper $mapper The mapper.
	 *
	 * @return FlowDefinition|null
	 */
	private function store(FlowDefinitionMapper $mapper): ?FlowDefinition {
		$definition = new FlowDefinition();
		$definition->setHash('h-1');
		$definition->setDefinition('{"nodes":[]}');
		$definition->setFlowUuid('flow-1');

		return $mapper->store($definition);
	}//end store()
}//end class
