<?php

/**
 * Characterisation (golden) test for `occ openregister:schemas:prune-retired`.
 *
 * Pins the exact console output, the exit code and the order of the writes for
 * every branch of the command: argument validation, missing slug, several rows
 * under one slug, the object guard, the archival and kept-rows refusals, dry
 * run, a failed teardown, a successful teardown with register unlinking, and
 * the count mismatch report. Written against the code before it was split into
 * smaller methods; it must keep passing unchanged afterwards.
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

use OCA\OpenRegister\Command\PruneRetiredSchemasCommand;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\SchemaDeletionService;
use OCA\OpenRegister\Service\SchemaTeardownOverride;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Golden outcomes of the prune command, allowed and refused.
 */
class PruneRetiredSchemasCommandGoldenTest extends TestCase {

	/**
	 * Ordered log of the writes the command performed.
	 *
	 * @var array<int, string>
	 */
	private array $calls = [];

	/**
	 * Build a schema entity with an id.
	 *
	 * @param int  $id       The schema id.
	 * @param bool $archival Whether it declares x-openregister-archival.
	 *
	 * @return Schema The schema.
	 */
	private function schema(int $id, bool $archival=false): Schema {
		$schema = new Schema();
		$prop   = (new ReflectionClass($schema))->getProperty('id');
		$prop->setAccessible(true);
		$prop->setValue($schema, $id);
		$schema->setSlug('doc');
		if ($archival === true) {
			$schema->setConfiguration(['x-openregister-archival' => ['retention' => ['default' => 'P10Y']]]);
		}

		return $schema;
	}//end schema()

	/**
	 * Build a register entity listing schema ids.
	 *
	 * @param int               $id      The register id.
	 * @param array<int, mixed> $schemas The stored schema list.
	 *
	 * @return Register The register.
	 */
	private function register(int $id, array $schemas): Register {
		$register = new Register();
		$prop     = (new ReflectionClass($register))->getProperty('id');
		$prop->setAccessible(true);
		$prop->setValue($register, $id);
		$register->setSlug('reg'.$id);
		$register->setSchemas($schemas);

		return $register;
	}//end register()

	/**
	 * Run the command against a scripted world.
	 *
	 * @param array<string, mixed>              $input       Command input.
	 * @param array<string, array<int, Schema>> $bySlug      Rows per slug.
	 * @param array<int, Register>              $registers   All registers.
	 * @param array<int, int>                   $objects     Object count per schema id.
	 * @param array<int, int>                   $kept        Kept-row count per schema id.
	 * @param array<int, mixed>                 $results     Teardown result (array) or Throwable per schema id.
	 * @param array<int, bool>                  $failUpdates Register ids whose update throws.
	 *
	 * @return array{0: int, 1: string, 2: array<int, string>} Exit code, display, call log.
	 */
	private function runPrune(
		array $input,
		array $bySlug=[],
		array $registers=[],
		array $objects=[],
		array $kept=[],
		array $results=[],
		array $failUpdates=[]
	): array {
		$this->calls = [];

		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('findAllByApplicationAndSlug')->willReturnCallback(
			function (string $slug, string $application) use ($bySlug): array {
				$this->calls[] = 'find '.$application.'/'.$slug;
				return ($bySlug[$slug] ?? []);
			}
		);

		$registerMapper = $this->createMock(RegisterMapper::class);
		$registerMapper->method('findAll')->willReturnCallback(
			function () use ($registers): array {
				return $registers;
			}
		);
		$registerMapper->method('update')->willReturnCallback(
			function (Register $register) use ($failUpdates): Register {
				$this->calls[] = 'update register '.$register->getId().' '.json_encode($register->getSchemas());
				if (($failUpdates[(int)$register->getId()] ?? false) === true) {
					throw new RuntimeException('db down');
				}

				return $register;
			}
		);

		$deletion = $this->createMock(SchemaDeletionService::class);
		$deletion->method('countObjectsCascadeWouldDelete')->willReturnCallback(
			function (Schema $schema) use ($objects): int {
				return ($objects[(int)$schema->getId()] ?? 0);
			}
		);
		$deletion->method('countKeptRows')->willReturnCallback(
			function (Schema $schema) use ($kept): int {
				return ($kept[(int)$schema->getId()] ?? 0);
			}
		);
		$deletion->method('cascadeDeleteSchema')->willReturnCallback(
			function (Schema $schema, SchemaTeardownOverride $override) use ($results): array {
				$this->calls[] = sprintf(
					'cascade %d archival=%s kept=%s',
					(int)$schema->getId(),
					var_export($override->archivalSchema, true),
					var_export($override->keptRows, true)
				);
				$result = ($results[(int)$schema->getId()] ?? ['deletedCount' => 0, 'tableDropped' => false]);
				if ($result instanceof \Throwable) {
					throw $result;
				}

				return $result;
			}
		);

		$tester = new CommandTester(new PruneRetiredSchemasCommand($schemaMapper, $registerMapper, $deletion));
		$code   = $tester->execute($input);

		return [$code, $tester->getDisplay(), $this->calls];
	}//end runPrune()

	/**
	 * Missing --app fails before any lookup.
	 *
	 * @return void
	 */
	public function testMissingAppFails(): void {
		[$code, $display, $calls] = $this->runPrune(['--slug' => ['doc']]);

		self::assertSame(Command::FAILURE, $code);
		self::assertSame("--app is required.\n", $display);
		self::assertSame([], $calls);
	}//end testMissingAppFails()

	/**
	 * Missing --slug fails before any lookup.
	 *
	 * @return void
	 */
	public function testMissingSlugFails(): void {
		[$code, $display, $calls] = $this->runPrune(['--app' => 'app1']);

		self::assertSame(Command::FAILURE, $code);
		self::assertSame("At least one --slug is required.\n", $display);
		self::assertSame([], $calls);
	}//end testMissingSlugFails()

	/**
	 * Dry run: not-found, object guard, archival refusal, kept-rows refusal, would-delete.
	 *
	 * @return void
	 */
	public function testDryRunMatrix(): void {
		[$code, $display, $calls] = $this->runPrune(
			['--app' => 'app1', '--slug' => ['gone', 'doc']],
			['doc' => [$this->schema(1), $this->schema(2, true), $this->schema(3), $this->schema(4, true)]],
			[$this->register(9, [1, '4'])],
			[1 => 5, 4 => 0],
			[3 => 2]
		);

		self::assertSame(Command::SUCCESS, $code);
		self::assertSame(
			"Running in DRY-RUN mode. Nothing will be deleted. Re-run with --apply to perform deletions.\n"
			."SKIP \"gone\": app \"app1\" owns no schema with that slug (already pruned, or never imported).\n"
			."\"doc\": app \"app1\" owns 4 rows under this slug. All of them are handled below.\n"
			."doc (id=1, app=app1): 5 object(s), referenced by 1 register(s)\n"
			."  SKIP: still owns objects. Re-run with --force to drop them, or migrate them first.\n"
			."doc (id=2, app=app1): 0 object(s), referenced by 0 register(s)\n"
			."  SKIP: declares x-openregister-archival, so its objects are legally retained records. Re-run with --force-archival to destroy them.\n"
			."doc (id=3, app=app1): 0 object(s), referenced by 0 register(s)\n"
			."  SKIP: holds 2 frozen, archived, locked or unreadable row(s). Re-run with --force-kept-rows to destroy them.\n"
			."doc (id=4, app=app1): 0 object(s), referenced by 1 register(s)\n"
			."  SKIP: declares x-openregister-archival, so its objects are legally retained records. Re-run with --force-archival to destroy them.\n"
			."Done. Pruned=0, skipped=4, not-found=1 (dry run, no writes performed)\n",
			$display
		);
		self::assertSame(['find app1/gone', 'find app1/doc'], $calls);
	}//end testDryRunMatrix()

	/**
	 * Dry run with every override names what would go and writes nothing.
	 *
	 * @return void
	 */
	public function testDryRunWithOverridesReportsWouldDelete(): void {
		[$code, $display, $calls] = $this->runPrune(
			['--app' => 'app1', '--slug' => ['doc'], '--force' => true, '--force-archival' => true, '--force-kept-rows' => true],
			['doc' => [$this->schema(2, true)]],
			[],
			[2 => 3],
			[2 => 1]
		);

		self::assertSame(Command::SUCCESS, $code);
		self::assertSame(
			"Running in DRY-RUN mode. Nothing will be deleted. Re-run with --apply to perform deletions.\n"
			."doc (id=2, app=app1): 3 object(s), referenced by 0 register(s)\n"
			."  WOULD DELETE (objects=3) (ARCHIVAL RECORDS) (1 KEPT ROWS)\n"
			."Done. Pruned=0, skipped=0, not-found=0 (dry run, no writes performed)\n",
			$display
		);
		self::assertSame(['find app1/doc'], $calls);
	}//end testDryRunWithOverridesReportsWouldDelete()

	/**
	 * Apply: failure, success with unlink (and failed unlink), count mismatch.
	 *
	 * @return void
	 */
	public function testApplyMatrix(): void {
		[$code, $display, $calls] = $this->runPrune(
			['--app' => 'app1', '--slug' => ['doc'], '--apply' => true, '--force' => true],
			['doc' => [$this->schema(1), $this->schema(2), $this->schema(3)]],
			[$this->register(8, [2, '2', 'x', 5]), $this->register(9, ['2']), $this->register(10, [3])],
			[1 => 1, 2 => 4, 3 => 0],
			[],
			[
				1 => new RuntimeException('boom'),
				2 => ['deletedCount' => 4, 'tableDropped' => true],
				3 => ['deletedCount' => 6, 'tableDropped' => false],
			],
			[9 => true]
		);

		self::assertSame(Command::SUCCESS, $code);
		self::assertSame(
			"\"doc\": app \"app1\" owns 3 rows under this slug. All of them are handled below.\n"
			."doc (id=1, app=app1): 1 object(s), referenced by 0 register(s)\n"
			."  FAILED: boom\n"
			."doc (id=2, app=app1): 4 object(s), referenced by 2 register(s)\n"
			."  unlinked from register id=8 (reg8)\n"
			."  NOT UNLINKED: register id=9 (reg9) still lists removed schema 2: db down\n"
			."  DELETED (objects removed=4, table dropped=yes)\n"
			."doc (id=3, app=app1): 0 object(s), referenced by 1 register(s)\n"
			."  unlinked from register id=10 (reg10)\n"
			."  DELETED (objects removed=6, table dropped=no)\n"
			."  COUNT MISMATCH: the guard saw 0 object(s), the delete removed 6. The guard was wrong about what it was protecting.\n"
			."Done. Pruned=2, skipped=1, not-found=0\n",
			$display
		);
		self::assertSame(
			[
				'find app1/doc',
				'cascade 1 archival=false kept=false',
				'cascade 2 archival=false kept=false',
				'update register 8 ["x",5]',
				'update register 9 []',
				'cascade 3 archival=false kept=false',
				'update register 10 []',
			],
			$calls
		);
	}//end testApplyMatrix()

	/**
	 * Apply without --force refuses a schema with objects; mutation: --force allows it.
	 *
	 * @return void
	 */
	public function testObjectGuardRefusesAndForceAllows(): void {
		$world = [
			['doc' => [$this->schema(1)]],
			[],
			[1 => 2],
		];

		[, $refused, $refusedCalls] = $this->runPrune(['--app' => 'a', '--slug' => ['doc'], '--apply' => true], ...$world);
		self::assertStringContainsString('SKIP: still owns objects.', $refused);
		self::assertStringContainsString('Pruned=0, skipped=1', $refused);
		self::assertSame(['find a/doc'], $refusedCalls);

		[, $allowed, $allowedCalls] = $this->runPrune(['--app' => 'a', '--slug' => ['doc'], '--apply' => true, '--force' => true], ...$world);
		self::assertStringContainsString('Pruned=1, skipped=0', $allowed);
		self::assertSame(['find a/doc', 'cascade 1 archival=false kept=false'], $allowedCalls);
	}//end testObjectGuardRefusesAndForceAllows()

	/**
	 * Archival and kept-row overrides reach the teardown as given.
	 *
	 * @return void
	 */
	public function testOverridesArePassedToTheTeardown(): void {
		[$code, $display, $calls] = $this->runPrune(
			['--app' => 'a', '--slug' => ['doc'], '--apply' => true, '--force-archival' => true, '--force-kept-rows' => true],
			['doc' => [$this->schema(5, true)]],
			[],
			[],
			[5 => 3],
			[5 => ['deletedCount' => 0, 'tableDropped' => true]]
		);

		self::assertSame(Command::SUCCESS, $code);
		self::assertSame(
			"doc (id=5, app=a): 0 object(s), referenced by 0 register(s)\n"
			."  DELETED (objects removed=0, table dropped=yes) (ARCHIVAL RECORDS) (3 KEPT ROWS)\n"
			."Done. Pruned=1, skipped=0, not-found=0\n",
			$display
		);
		self::assertSame(['find a/doc', 'cascade 5 archival=true kept=true'], $calls);
	}//end testOverridesArePassedToTheTeardown()
}//end class
