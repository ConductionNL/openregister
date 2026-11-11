<?php

/**
 * Characterisation (golden) test for `occ openregister:registers:dedupe`.
 *
 * Pins the exact console output, exit code and delete order for every branch:
 * no duplicates, blank slugs ignored, case-insensitive grouping, lowest id kept,
 * the object guard (refused without --force, allowed with it), dry run, a delete
 * refused by the mapper, a successful delete, and the object count that reads
 * past unreadable schemas and missing tables. Written against the code before
 * it was split into smaller methods; it must keep passing unchanged afterwards.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Command
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Command;

use OCA\OpenRegister\Command\DedupeRegistersCommand;
use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaCatalog;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Golden outcomes of the register dedupe command, allowed and refused.
 */
class DedupeRegistersCommandGoldenTest extends TestCase {

	/**
	 * Ordered log of the writes and lookups the command performed.
	 *
	 * @var array<int, string>
	 */
	private array $calls = [];

	/**
	 * Build a register with an id, slug and schema list.
	 *
	 * @param int               $id      The register id.
	 * @param string|null       $slug    The slug.
	 * @param array<int, mixed> $schemas The stored schema refs.
	 *
	 * @return Register The register.
	 */
	private function register(int $id, ?string $slug, array $schemas=[]): Register {
		$register = new Register();
		$prop     = (new ReflectionClass($register))->getProperty('id');
		$prop->setAccessible(true);
		$prop->setValue($register, $id);
		$register->setSlug($slug);
		$register->setSchemas($schemas);

		return $register;
	}//end register()

	/**
	 * Run the command against scripted registers.
	 *
	 * Schema ref 'bad' is unreadable, 'notable' has no table, 'boom' fails to
	 * count; any other ref counts as the integer in $counts (default 0).
	 *
	 * @param array<string, bool>  $input     Command options.
	 * @param array<int, Register> $registers All registers.
	 * @param array<string, int>   $counts    Object count per schema ref.
	 * @param array<int, bool>     $refuse    Register ids whose delete throws.
	 *
	 * @return array{0: int, 1: string, 2: array<int, string>} Exit code, display, call log.
	 */
	private function runDedupe(array $input, array $registers, array $counts=[], array $refuse=[]): array {
		$this->calls = [];

		$registerMapper = $this->createMock(RegisterMapperInterface::class);
		$registerMapper->method('findAll')->willReturnCallback(
			function () use ($registers): array {
				$this->calls[] = 'findAll';
				return $registers;
			}
		);
		$registerMapper->method('delete')->willReturnCallback(
			function (Register $register) use ($refuse): Register {
				$this->calls[] = 'delete '.$register->getId();
				if (($refuse[(int)$register->getId()] ?? false) === true) {
					throw new RuntimeException('has objects');
				}

				return $register;
			}
		);

		$schemaMapper = $this->createMock(SchemaMapperInterface::class);
		$schemaMapper->method('find')->willReturnCallback(
			function ($ref): Schema {
				if ($ref === 'bad') {
					throw new RuntimeException('unreadable');
				}

				$schema = new Schema();
				$schema->setSlug((string)$ref);
				return $schema;
			}
		);

		$magicMapper = $this->createMock(MagicMapperInterface::class);
		$magicMapper->method('tableExistsForRegisterSchema')->willReturnCallback(
			static fn (Register $register, Schema $schema): bool => $schema->getSlug() !== 'notable'
		);
		$magicMapper->method('countObjectsInRegisterSchemaTable')->willReturnCallback(
			static function (array $query, Register $register, Schema $schema) use ($counts): int {
				if ($schema->getSlug() === 'boom') {
					throw new RuntimeException('count failed');
				}

				return ($counts[(string)$schema->getSlug()] ?? 0);
			}
		);

		$tester = new CommandTester(new DedupeRegistersCommand(new SchemaCatalog($registerMapper, $schemaMapper, $magicMapper)));
		$code   = $tester->execute($input);

		return [$code, $tester->getDisplay(), $this->calls];
	}//end runDedupe()

	/**
	 * No duplicates (blank slugs ignored) — dry run and apply.
	 *
	 * @return void
	 */
	public function testNoDuplicates(): void {
		$registers = [$this->register(1, 'a'), $this->register(2, ''), $this->register(3, null), $this->register(4, 'b')];

		[$code, $display, $calls] = $this->runDedupe([], $registers);
		self::assertSame(Command::SUCCESS, $code);
		self::assertSame(
			"Running in DRY-RUN mode — no registers will be deleted. Re-run with --apply to perform deletions.\n"
			."No duplicate registers found.\n",
			$display
		);
		self::assertSame(['findAll'], $calls);

		[$code, $display] = $this->runDedupe(['--apply' => true], $registers);
		self::assertSame(Command::SUCCESS, $code);
		self::assertSame("No duplicate registers found.\n", $display);
	}//end testNoDuplicates()

	/**
	 * Dry run: case-insensitive grouping, lowest id kept, guard, would-delete.
	 *
	 * @return void
	 */
	public function testDryRunMatrix(): void {
		$registers = [
			$this->register(5, 'Reg', ['s1']),
			$this->register(2, 'reg'),
			$this->register(9, 'REG', ['bad', 'notable', 'boom', 's2']),
			$this->register(3, 'other'),
			$this->register(4, 'other', ['s1', 's2']),
		];

		[$code, $display, $calls] = $this->runDedupe(['--dry-run' => true], $registers, ['s1' => 2, 's2' => 0]);

		self::assertSame(Command::SUCCESS, $code);
		self::assertSame(
			"Running in DRY-RUN mode — no registers will be deleted. Re-run with --apply to perform deletions.\n"
			."Slug \"reg\": keeping canonical register id=2, 2 duplicate(s)\n"
			."  SKIP duplicate id=5 (owns 2 object(s) — re-run with --force to drop, or merge manually)\n"
			."  WOULD DELETE duplicate id=9 (objects=0)\n"
			."Slug \"other\": keeping canonical register id=3, 1 duplicate(s)\n"
			."  SKIP duplicate id=4 (owns 2 object(s) — re-run with --force to drop, or merge manually)\n"
			."Done. Duplicate slug groups=2, deleted=0, kept-non-empty=2 (dry run — no writes performed)\n",
			$display
		);
		self::assertSame(['findAll'], $calls);
	}//end testDryRunMatrix()

	/**
	 * Apply: guard refuses without --force; mapper refusal kept; success deleted.
	 *
	 * @return void
	 */
	public function testApplyMatrix(): void {
		$registers = [
			$this->register(1, 'x'),
			$this->register(2, 'x', ['s1']),
			$this->register(3, 'x'),
			$this->register(4, 'x'),
		];

		[$code, $display, $calls] = $this->runDedupe(['--apply' => true], $registers, ['s1' => 1], [3 => true]);

		self::assertSame(Command::SUCCESS, $code);
		self::assertSame(
			"Slug \"x\": keeping canonical register id=1, 3 duplicate(s)\n"
			."  SKIP duplicate id=2 (owns 1 object(s) — re-run with --force to drop, or merge manually)\n"
			."  SKIP duplicate id=3 (delete refused: has objects)\n"
			."  DELETED duplicate id=4 (objects=0)\n"
			."Done. Duplicate slug groups=1, deleted=1, kept-non-empty=2\n",
			$display
		);
		self::assertSame(['findAll', 'delete 3', 'delete 4'], $calls);
	}//end testApplyMatrix()

	/**
	 * Mutation check: --force lifts exactly the object guard and nothing else.
	 *
	 * @return void
	 */
	public function testForceAllowsANonEmptyDuplicate(): void {
		$registers = [$this->register(1, 'x'), $this->register(2, 'x', ['s1'])];

		[, $refused, $refusedCalls] = $this->runDedupe(['--apply' => true], $registers, ['s1' => 3]);
		self::assertStringContainsString('SKIP duplicate id=2 (owns 3 object(s)', $refused);
		self::assertSame(['findAll'], $refusedCalls);

		[$code, $allowed, $allowedCalls] = $this->runDedupe(['--apply' => true, '--force' => true], $registers, ['s1' => 3]);
		self::assertSame(Command::SUCCESS, $code);
		self::assertSame(
			"Slug \"x\": keeping canonical register id=1, 1 duplicate(s)\n"
			."  DELETED duplicate id=2 (objects=3)\n"
			."Done. Duplicate slug groups=1, deleted=1, kept-non-empty=0\n",
			$allowed
		);
		self::assertSame(['findAll', 'delete 2'], $allowedCalls);

		[, $dry, $dryCalls] = $this->runDedupe(['--force' => true], $registers, ['s1' => 3]);
		self::assertStringContainsString('WOULD DELETE duplicate id=2 (objects=3)', $dry);
		self::assertSame(['findAll'], $dryCalls);
	}//end testForceAllowsANonEmptyDuplicate()
}//end class
