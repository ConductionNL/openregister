<?php

/**
 * The shared run of a magic-table maintenance command: its selection
 * options, the readiness gate, a selection that throws, an empty selection,
 * and the walk over every applicable table with its tally.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Command
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Command;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.

use OCA\OpenRegister\Command\MagicTableCommand;
use OCA\OpenRegister\Command\MagicTableRun;
use OCA\OpenRegister\Command\MagicTableSelection;
use OCA\OpenRegister\Command\MagicTableTarget;
use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaCatalogInterface;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * MagicTableCommand.
 *
 * @covers \OCA\OpenRegister\Command\MagicTableCommand
 * @uses \OCA\OpenRegister\Command\MagicTableRun
 * @uses \OCA\OpenRegister\Command\MagicTableSelection
 * @uses \OCA\OpenRegister\Command\MagicTableTarget
 * @uses \OCA\OpenRegister\Db\Register
 * @uses \OCA\OpenRegister\Db\Schema
 * @uses \OCA\OpenRegister\Db\Register\RegisterSchemaIds
 * @uses \OCA\OpenRegister\Db\MagicMapper\StatisticsScope
 * @uses \OCA\OpenRegister\Kernel\Contracts\ListQuery
 * @uses \OCA\OpenRegister\Kernel\Contracts\Value\MagicMappingOptions
 */
class MagicTableCommandTest extends TestCase {

	private RegisterMapperInterface $registers;

	private SchemaMapperInterface $schemas;

	private MagicMapperInterface $tables;

	/**
	 * What the probe command saw, in order.
	 *
	 * @var list<string>
	 */
	public array $log = [];

	protected function setUp(): void {
		$this->registers = $this->createMock(RegisterMapperInterface::class);
		$this->schemas   = $this->createMock(SchemaMapperInterface::class);
		$this->tables    = $this->createMock(MagicMapperInterface::class);
		$this->tables->method('tableExistsForRegisterSchema')->willReturn(true);
		$this->tables->method('getTableNameForRegisterSchema')->willReturnCallback(
			static fn (Register $register, Schema $schema): string => 't_'.$schema->getSlug()
		);
	}//end setUp()

	private function probe(bool $ready = true, ?string $skip = null): MagicTableCommand {
		$catalog = $this->createMock(SchemaCatalogInterface::class);
		$catalog->method('registers')->willReturn($this->registers);
		$catalog->method('schemas')->willReturn($this->schemas);
		$catalog->method('tables')->willReturn($this->tables);

		return new class (new MagicTableSelection($catalog), $this, $ready, $skip) extends MagicTableCommand {
			public function __construct(MagicTableSelection $selection, private MagicTableCommandTest $test, private bool $ready, private ?string $skip) {
				parent::__construct($selection);
			}

			protected function configure(): void {
				$this->setName('openregister:probe');
				$this->addSelectionOptions();
			}

			protected function isReady(MagicTableRun $run): bool {
				return $this->ready;
			}

			protected function applies(Schema $schema, MagicTableRun $run): bool {
				return $schema->getSlug() !== $this->skip;
			}

			protected function announce(MagicTableRun $run): void {
				$this->test->log[] = 'announce'.$run->dryRunNote(' (dry)');
			}

			protected function workOn(MagicTableTarget $target, MagicTableRun $run): void {
				$this->test->log[] = $target->tableName.'#'.$run->tables;
			}

			protected function finish(MagicTableRun $run): int {
				$this->test->log[] = 'finish '.$run->tables;
				return 7;
			}
		};
	}//end probe()

	private function register(array $schemaIds): Register {
		$register = new Register();
		$register->setSlug('zaken');
		$register->setSchemas($schemaIds);

		return $register;
	}//end register()

	private function schema(int $id, string $slug): Schema {
		$schema = new Schema();
		$schema->setId($id);
		$schema->setSlug($slug);

		return $schema;
	}//end schema()

	public function testTheSelectionOptionsAreDeclared(): void {
		$options = $this->probe()->getDefinition()->getOptions();

		$this->assertSame(['dry-run', 'register', 'schema'], array_keys($options));
		$this->assertFalse($options['dry-run']->acceptValue());
		$this->assertTrue($options['register']->isValueRequired());
		$this->assertSame('Limit to a single schema (slug, uuid or id)', $options['schema']->getDescription());
	}//end testTheSelectionOptionsAreDeclared()

	public function testACommandThatIsNotReadyFailsWithoutSelecting(): void {
		$this->registers->expects($this->never())->method('findAll');

		$tester = new CommandTester($this->probe(ready: false));

		$this->assertSame(Command::FAILURE, $tester->execute([]));
		$this->assertSame([], $this->log);
	}//end testACommandThatIsNotReadyFailsWithoutSelecting()

	public function testASelectionThatThrowsFailsWithItsMessage(): void {
		$this->registers->method('find')->willThrowException(new RuntimeException('Register not found: nope'));

		$tester = new CommandTester($this->probe());

		$this->assertSame(Command::FAILURE, $tester->execute(['--register' => 'nope']));
		$this->assertSame("Register not found: nope\n", $tester->getDisplay());
		$this->assertSame([], $this->log);
	}//end testASelectionThatThrowsFailsWithItsMessage()

	public function testNoSchemasIsASuccessfulNoOp(): void {
		$this->registers->method('findAll')->willReturn([$this->register([1])]);
		$this->schemas->method('findAll')->willReturn([]);

		$tester = new CommandTester($this->probe());

		$this->assertSame(Command::SUCCESS, $tester->execute([]));
		$this->assertSame("No registers or schemas resolved — nothing to do.\n", $tester->getDisplay());
		$this->assertSame([], $this->log);
	}//end testNoSchemasIsASuccessfulNoOp()

	public function testEveryApplicableTableIsWorkedOnAndTheFinishDecidesTheExitCode(): void {
		$this->registers->method('find')->with('zaken')->willReturn($this->register([1, 2, 3]));
		$this->schemas->method('findAll')->willReturn([$this->schema(1, 'case'), $this->schema(2, 'note'), $this->schema(3, 'task')]);

		$tester = new CommandTester($this->probe(skip: 'note'));

		$this->assertSame(7, $tester->execute(['--register' => 'zaken', '--dry-run' => true]));
		$this->assertSame(['announce (dry)', 't_case#1', 't_task#2', 'finish 2'], $this->log);
	}//end testEveryApplicableTableIsWorkedOnAndTheFinishDecidesTheExitCode()
}//end class
