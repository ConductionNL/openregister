<?php

/**
 * Characterisation test for MagicMapper::countObjectsInRegisterSchemaTable() and countObjectsOrFail().
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Tests
 * @package   OCA\OpenRegister\Tests\Unit\Db\MagicMapper
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db\MagicMapper;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\MagicMapper\MagicSearchHandler;
use OCA\OpenRegister\Db\MagicMapper\TableSearch;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Exception\EncryptedFieldFilterException;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionProperty;
use RuntimeException;

/**
 * Pins what the per-table counts ask the search handler and how they answer.
 */
class MagicMapperCountInTableTest extends TestCase {

	/**
	 * The queries and tables handed to the search handler.
	 *
	 * @var list<array<int, mixed>>
	 */
	private array $asked = [];

	/**
	 * A mapper whose table exists (or not) and whose search handler answers $answer.
	 *
	 * @param mixed $answer The handler's answer, or a Throwable to throw.
	 * @param bool  $exists Whether the table exists.
	 *
	 * @return MagicMapper
	 */
	private function mapper(mixed $answer, bool $exists=true): MagicMapper {
		$mapper = $this->getMockBuilder(MagicMapper::class)
			->disableOriginalConstructor()
			->onlyMethods(['existsTableForRegisterSchema', 'getTableNameForRegisterSchema'])
			->getMock();
		$mapper->method('existsTableForRegisterSchema')->willReturn($exists);
		$mapper->method('getTableNameForRegisterSchema')->willReturn('openregister_table_1_2');
		$handler = $this->createMock(MagicSearchHandler::class);
		$handler->method('searchTable')->willReturnCallback(
			function (array $query, TableSearch $search) use ($answer): mixed {
				$this->asked[] = [$query, $search->table->register->getId(), $search->table->schema->getId(), $search->tableName];
				if ($answer instanceof \Throwable) {
					throw $answer;
				}

				return $answer;
			}
		);
		(new ReflectionProperty(MagicMapper::class, 'logger'))->setValue($mapper, new NullLogger());
		(new ReflectionProperty(MagicMapper::class, 'searchHandler'))->setValue($mapper, $handler);

		return $mapper;
	}//end mapper()

	/**
	 * Register 1 (magic mapping off for every schema) and schema 2.
	 *
	 * @return array{0: Register, 1: Schema}
	 */
	private function table(): array {
		$register = new Register();
		$register->setId(1);
		$schema = new Schema();
		$schema->setId(2);

		return [$register, $schema];
	}//end table()

	public function testACountAsksTheHandlerWithTheCountFlag(): void {
		[$register, $schema] = $this->table();

		$this->assertSame(4, $this->mapper(answer: 4)->countObjectsInRegisterSchemaTable(['a' => 1], $register, $schema));
		$this->assertSame([[['a' => 1, '_count' => true], 1, 2, 'openregister_table_1_2']], $this->asked);
	}//end testACountAsksTheHandlerWithTheCountFlag()

	public function testACountOfAMissingTableOrAFailureOrANonNumberIsZero(): void {
		[$register, $schema] = $this->table();

		$this->assertSame(0, $this->mapper(answer: 4, exists: false)->countObjectsInRegisterSchemaTable([], $register, $schema));
		$this->assertSame(0, $this->mapper(answer: new \Exception('boom'))->countObjectsInRegisterSchemaTable([], $register, $schema));
		$this->assertSame(0, $this->mapper(answer: ['rows'])->countObjectsInRegisterSchemaTable([], $register, $schema));
	}//end testACountOfAMissingTableOrAFailureOrANonNumberIsZero()

	public function testACountOnAnEncryptedFieldFailsLoud(): void {
		[$register, $schema] = $this->table();

		$this->expectException(EncryptedFieldFilterException::class);
		$this->mapper(answer: new EncryptedFieldFilterException('enc'))->countObjectsInRegisterSchemaTable([], $register, $schema);
	}//end testACountOnAnEncryptedFieldFailsLoud()

	public function testACountOrFailAnswersTheNumberOrRefuses(): void {
		[$register, $schema] = $this->table();

		$this->assertSame(7, $this->mapper(answer: 7)->countObjectsOrFail([], $register, $schema));
		$this->assertSame(0, $this->mapper(answer: 7, exists: false)->countObjectsOrFail([], $register, $schema));
		$this->expectException(RuntimeException::class);
		$this->mapper(answer: ['rows'])->countObjectsOrFail([], $register, $schema);
	}//end testACountOrFailAnswersTheNumberOrRefuses()
}//end class
