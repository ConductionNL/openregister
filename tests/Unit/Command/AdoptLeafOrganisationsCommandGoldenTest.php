<?php

/**
 * Characterisation (golden) test for `occ openregister:organisations:adopt`.
 *
 * Pins the exact console output, exit code, insert order, log context and the
 * static helpers' results for every branch: missing --register, a failing and a
 * count-returning reader, skip/merge/adopt/fail in one run, dry run, and the
 * row flattening, undeclared-property and merge-target rules. Written against
 * the code before it was split into smaller methods; it must keep passing
 * unchanged afterwards.
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

use JsonSerializable;
use OCA\OpenRegister\Command\AdoptLeafOrganisationsCommand;
use OCA\OpenRegister\Db\Organisation;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Golden outcomes of the organisation adoption command.
 */
class AdoptLeafOrganisationsCommandGoldenTest extends TestCase {

	/**
	 * Ordered log of inserts and log lines.
	 *
	 * @var array<int, string>
	 */
	private array $calls = [];

	/**
	 * Run the command over scripted rows.
	 *
	 * @param array<string, mixed>     $input    Command input.
	 * @param mixed                    $rows     What the reader returns, or a Throwable it throws.
	 * @param array<int, Organisation> $existing Organisations already present.
	 * @param array<int, string>       $failOn   Uuids whose insert throws.
	 *
	 * @return array{0: int, 1: string, 2: array<int, string>} Exit code, display, call log.
	 */
	private function runAdopt(array $input, mixed $rows, array $existing=[], array $failOn=[]): array {
		$this->calls = [];

		$reader = $this->createMock(ObjectService::class);
		if ($rows instanceof \Throwable) {
			$reader->method('searchObjectsBySlug')->willThrowException($rows);
		} else {
			$reader->method('searchObjectsBySlug')->willReturn($rows);
		}

		$nextId = 100;
		$mapper = $this->createMock(OrganisationMapper::class);
		$mapper->method('findAll')->willReturn($existing);
		$mapper->method('insert')->willReturnCallback(
			function (Organisation $organisation) use ($failOn, &$nextId): Organisation {
				$this->calls[] = sprintf(
					'insert %s slug=%s name=%s oin=%s mergedInto=%s mergedAt=%s',
					$organisation->getUuid(),
					$organisation->getSlug(),
					var_export($organisation->getName(), true),
					var_export($organisation->getOin(), true),
					var_export($organisation->getMergedInto(), true),
					var_export($organisation->getMergedAt() !== null, true)
				);
				if (in_array($organisation->getUuid(), $failOn, true) === true) {
					throw new RuntimeException('unique violation');
				}

				$organisation->setId($nextId++);
				return $organisation;
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('info')->willReturnCallback(
			function (string $message, array $context): void {
				$this->calls[] = 'log '.$message.' '.json_encode($context);
			}
		);

		$tester = new CommandTester(
			new AdoptLeafOrganisationsCommand(organisationMapper: $mapper, objectService: $reader, logger: $logger)
		);
		$code   = $tester->execute($input);

		return [$code, $tester->getDisplay(), $this->calls];
	}//end runAdopt()

	/**
	 * An existing organisation.
	 *
	 * @param int         $id   The id.
	 * @param string      $uuid The uuid.
	 * @param string|null $oin  The OIN.
	 *
	 * @return Organisation The organisation.
	 */
	private function org(int $id, string $uuid, ?string $oin=null): Organisation {
		$organisation = new Organisation();
		$organisation->setId($id);
		$organisation->setUuid($uuid);
		$organisation->setOin($oin);

		return $organisation;
	}//end org()

	/**
	 * Missing register, reader failure and a count instead of rows all fail.
	 *
	 * @return void
	 */
	public function testFailuresBeforeAnyRow(): void {
		[$code, $display, $calls] = $this->runAdopt([], []);
		self::assertSame(Command::FAILURE, $code);
		self::assertSame("--register is required.\n", $display);
		self::assertSame([], $calls);

		[$code, $display, $calls] = $this->runAdopt(['--register' => 'pub', '--apply' => true], new RuntimeException('no such register'));
		self::assertSame(Command::FAILURE, $code);
		self::assertSame("Could not read pub/organization: no such register\n", $display);
		self::assertSame([], $calls);

		[$code, $display, $calls] = $this->runAdopt(['--register' => 'pub', '--schema' => 'org'], 3);
		self::assertSame(Command::FAILURE, $code);
		self::assertSame(
			"Running in DRY-RUN mode — nothing will be written. Re-run with --apply to adopt.\n"
			."The object reader returned a count rather than rows.\n",
			$display
		);
		self::assertSame([], $calls);
	}//end testFailuresBeforeAnyRow()

	/**
	 * Apply: skip no-uuid, skip already adopted, merge, in-run merge, failure, adopt.
	 *
	 * @return void
	 */
	public function testApplyMatrix(): void {
		$rows = [
			['name' => 'No uuid'],
			['@self' => ['uuid' => 'old-1'], 'name' => 'Old'],
			['@self' => ['uuid' => 'leaf-1'], 'name' => 'Utrecht', 'oin' => '0000.0001', 'colour' => 'red'],
			['uuid' => 'leaf-2', 'name' => 'Utrecht again', 'oin' => '00000001', 'a' => 1, 'b' => 2],
			['@self' => ['id' => 'leaf-3'], 'oin' => '999'],
			['id' => 'leaf-4', 'rsin' => 'R1', 'name' => ''],
			['@self' => ['uuid' => 'leaf-5'], 'oin' => '999'],
		];

		[$code, $display, $calls] = $this->runAdopt(
			['--register' => 'pub', '--apply' => true],
			$rows,
			[$this->org(9, 'old-1'), $this->org(4, 'live-1', '00000001')],
			['leaf-4']
		);

		self::assertSame(Command::FAILURE, $code);
		self::assertSame(
			"  SKIP a row with no uuid: it has no idempotency key.\n"
			."  SKIP old-1: already adopted.\n"
			."leaf-1 (Utrecht) -> merges into live-1\n"
			."  1 property has no column on Organisation and will NOT be carried over: colour\n"
			."  ADOPTED\n"
			."leaf-2 (Utrecht again) -> merges into live-1\n"
			."  2 properties have no column on Organisation and will NOT be carried over: a, b\n"
			."  ADOPTED\n"
			."leaf-3 (unnamed)\n"
			."  ADOPTED\n"
			."leaf-4 ()\n"
			."  FAILED: unique violation\n"
			."leaf-5 (unnamed) -> merges into leaf-3\n"
			."  ADOPTED\n"
			."Done. Adopted=4 (of which merged=3), skipped=2, failed=1\n",
			$display
		);
		self::assertSame(
			[
				"insert leaf-1 slug=adopted-leaf-1 name='Utrecht' oin='0000.0001' mergedInto='live-1' mergedAt=true",
				"insert leaf-2 slug=adopted-leaf-2 name='Utrecht again' oin='00000001' mergedInto='live-1' mergedAt=true",
				"insert leaf-3 slug=adopted-leaf-3 name=NULL oin='999' mergedInto=NULL mergedAt=false",
				"insert leaf-4 slug=adopted-leaf-4 name=NULL oin=NULL mergedInto=NULL mergedAt=false",
				"insert leaf-5 slug=adopted-leaf-5 name=NULL oin='999' mergedInto='leaf-3' mergedAt=true",
				'log OpenRegister: adopted leaf organisations {"register":"pub","schema":"organization","adopted":4,"merged":3,"skipped":2,"failed":1,"dryRun":false}',
			],
			$calls
		);
	}//end testApplyMatrix()

	/**
	 * Dry run reports, writes nothing, and succeeds.
	 *
	 * @return void
	 */
	public function testDryRun(): void {
		$rows = [
			['@self' => ['uuid' => 'leaf-1'], 'oin' => '1'],
			['@self' => ['uuid' => 'leaf-1'], 'oin' => '1'],
		];

		[$code, $display, $calls] = $this->runAdopt(['--register' => 'pub'], $rows, [$this->org(4, 'live-1', '1')]);

		self::assertSame(Command::SUCCESS, $code);
		self::assertSame(
			"Running in DRY-RUN mode — nothing will be written. Re-run with --apply to adopt.\n"
			."leaf-1 (unnamed) -> merges into live-1\n"
			."  WOULD ADOPT\n"
			."leaf-1 (unnamed) -> merges into live-1\n"
			."  WOULD ADOPT\n"
			."Done. Adopted=0 (of which merged=0), skipped=0, failed=0 (dry run — nothing written)\n",
			$display
		);
		self::assertSame(
			['log OpenRegister: adopted leaf organisations {"register":"pub","schema":"organization","adopted":0,"merged":0,"skipped":0,"failed":0,"dryRun":true}'],
			$calls
		);
	}//end testDryRun()

	/**
	 * Mutation check: an already-adopted uuid is refused a second insert; a new one is allowed.
	 *
	 * @return void
	 */
	public function testAlreadyAdoptedIsRefusedAndNewIsAllowed(): void {
		[, $refused, $refusedCalls] = $this->runAdopt(['--register' => 'p', '--apply' => true], [['uuid' => 'u1']], [$this->org(1, 'u1')]);
		self::assertStringContainsString('SKIP u1: already adopted.', $refused);
		self::assertSame(1, count($refusedCalls));

		[$code, , $allowedCalls] = $this->runAdopt(['--register' => 'p', '--apply' => true], [['uuid' => 'u1']], [$this->org(1, 'u2')]);
		self::assertSame(Command::SUCCESS, $code);
		self::assertSame("insert u1 slug=adopted-u1 name=NULL oin=NULL mergedInto=NULL mergedAt=false", $allowedCalls[0]);
	}//end testAlreadyAdoptedIsRefusedAndNewIsAllowed()

	/**
	 * Row flattening for objects, non-arrays and id fallbacks.
	 *
	 * @return void
	 */
	public function testToFields(): void {
		$object = new class implements JsonSerializable {
			/**
			 * Serialise.
			 *
			 * @return array<string, mixed> The row.
			 */
			public function jsonSerialize(): array {
				return ['@self' => ['id' => 'obj-1'], 'name' => 'X'];
			}
		};

		self::assertSame(['@self' => ['id' => 'obj-1'], 'name' => 'X', 'uuid' => 'obj-1'], AdoptLeafOrganisationsCommand::toFields(row: $object));
		self::assertSame([], AdoptLeafOrganisationsCommand::toFields(row: 'scalar'));
		self::assertSame([], AdoptLeafOrganisationsCommand::toFields(row: new \stdClass()));
		self::assertSame(['@self' => 'x', 'id' => 7, 'uuid' => '7'], AdoptLeafOrganisationsCommand::toFields(row: ['@self' => 'x', 'id' => 7]));
		self::assertSame(['@self' => ['uuid' => ''], 'uuid' => ''], AdoptLeafOrganisationsCommand::toFields(row: ['@self' => ['uuid' => '']]));
		self::assertSame(['uuid' => 'u'], AdoptLeafOrganisationsCommand::toFields(row: ['uuid' => 'u']));
	}//end testToFields()

	/**
	 * Undeclared properties skip @-keys, id and uuid, and come back sorted.
	 *
	 * @return void
	 */
	public function testUndeclaredProperties(): void {
		self::assertSame(
			['alpha', 'zeta'],
			AdoptLeafOrganisationsCommand::undeclaredProperties(
				row: ['zeta' => 1, '@self' => [], '@type' => 'x', 'id' => 1, 'uuid' => 'u', 'name' => 'n', 'kvk' => '1', 'alpha' => 2]
			)
		);
		self::assertSame([], AdoptLeafOrganisationsCommand::undeclaredProperties(row: []));
	}//end testUndeclaredProperties()

	/**
	 * Merge-target precedence, fall-through and tie-breaks.
	 *
	 * @return void
	 */
	public function testFindMergeTarget(): void {
		$existing = [
			['id' => 5, 'uuid' => 'a', 'oin' => null, 'rsin' => 'R', 'kvk' => 'K', 'mergedInto' => null],
			['id' => 3, 'uuid' => 'b', 'oin' => null, 'rsin' => 'R', 'kvk' => null, 'mergedInto' => 'z'],
			['id' => 8, 'uuid' => 'c', 'oin' => 'O', 'rsin' => null, 'kvk' => 'K', 'mergedInto' => null],
			['uuid' => 'd', 'kvk' => 'K'],
		];

		// OIN carried but unmatched falls through to RSIN.
		self::assertSame('a', AdoptLeafOrganisationsCommand::findMergeTarget(row: ['oin' => 'nope', 'rsin' => 'r'], existing: $existing)['uuid']);
		// KVK: no id sorts as 0, ahead of 5 and 8.
		self::assertSame('d', AdoptLeafOrganisationsCommand::findMergeTarget(row: ['kvk' => 'k'], existing: $existing)['uuid']);
		self::assertSame('c', AdoptLeafOrganisationsCommand::findMergeTarget(row: ['oin' => 'o', 'kvk' => 'K'], existing: $existing)['uuid']);
		self::assertNull(AdoptLeafOrganisationsCommand::findMergeTarget(row: ['oin' => 'x', 'rsin' => 'y', 'kvk' => 'z'], existing: $existing));
		self::assertNull(AdoptLeafOrganisationsCommand::findMergeTarget(row: ['name' => 'a'], existing: $existing));
		// Both merged away: lowest id wins.
		$merged = [
			['id' => 9, 'uuid' => 'm9', 'oin' => '1', 'mergedInto' => 'x'],
			['id' => 2, 'uuid' => 'm2', 'oin' => '1', 'mergedInto' => 'y'],
		];
		self::assertSame('m2', AdoptLeafOrganisationsCommand::findMergeTarget(row: ['oin' => '1'], existing: $merged)['uuid']);
	}//end testFindMergeTarget()

	/**
	 * Organisation building skips empty and non-scalar values.
	 *
	 * @return void
	 */
	public function testBuildOrganisation(): void {
		$organisation = AdoptLeafOrganisationsCommand::buildOrganisation(
			fields: ['uuid' => 'u', 'name' => 'N', 'summary' => '', 'description' => null, 'oin' => 12, 'type' => ['x'], 'status' => true],
			mergeTarget: null
		);

		self::assertSame('u', $organisation->getUuid());
		self::assertSame('N', $organisation->getName());
		self::assertNull($organisation->getSummary());
		self::assertNull($organisation->getDescription());
		self::assertSame('12', $organisation->getOin());
		self::assertSame('organisation', $organisation->getType());
		self::assertSame('1', $organisation->getStatus());
		self::assertSame('adopted-u', $organisation->getSlug());
		self::assertNull($organisation->getMergedInto());
		self::assertNull($organisation->getMergedAt());

		$merged = AdoptLeafOrganisationsCommand::buildOrganisation(fields: ['uuid' => 'v'], mergeTarget: ['uuid' => 't']);
		self::assertSame('t', $merged->getMergedInto());
		self::assertNotNull($merged->getMergedAt());
	}//end testBuildOrganisation()
}//end class
