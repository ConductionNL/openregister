<?php

/**
 * Characterisation (golden) test for `occ openregister:schemas:dedupe-shared`.
 *
 * Pins the exact console output, exit code and the arguments passed to the
 * dedupe service for: an invalid --keep, the register filter cast, an empty
 * plan, a dry run, the refusal to write an unattributed plan, and a write with
 * one success and one failure. Written against the code before execute() was
 * split; it must keep passing unchanged afterwards.
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

use OCA\OpenRegister\Command\DedupeSharedSchemasCommand;
use OCA\OpenRegister\Service\SharedSchema\SchemaAttribution;
use OCA\OpenRegister\Service\SharedSchemaDedupeService;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Golden outcomes of the shared-schema dedupe command.
 */
class DedupeSharedSchemasCommandGoldenTest extends TestCase {

	/**
	 * Ordered log of service calls.
	 *
	 * @var array<int, string>
	 */
	private array $calls = [];

	/**
	 * One plan entry.
	 *
	 * @param int      $schemaId The schema id.
	 * @param int|null $owner    The owning register, or null.
	 * @param array    $splits   Split targets.
	 *
	 * @return array<string, mixed> The entry.
	 */
	private function entry(int $schemaId, ?int $owner, array $splits): array {
		return [
			'schemaId'    => $schemaId,
			'schemaSlug'  => 's'.$schemaId,
			'registerIds' => array_merge([($owner ?? 1)], array_keys($splits)),
			'status'      => ($owner === null ? SchemaAttribution::STATUS_MULTI_MATCH : SchemaAttribution::STATUS_ONE_MATCH),
			'matches'     => ($owner === null ? [1, 2] : [$owner]),
			'owner'       => $owner,
			'ownerSource' => ($owner === null ? 'unattributed' : 'configuration'),
			'splits'      => $splits,
		];
	}//end entry()

	/**
	 * One split target.
	 *
	 * @param string $slug The register slug.
	 *
	 * @return array<string, mixed> The split.
	 */
	private function split(string $slug): array {
		return [
			'registerSlug' => $slug,
			'application'  => $slug,
			'path'         => 'configuration',
			'definition'   => ['slug' => 'x'],
			'table'        => 'openregister_table_'.$slug,
			'rows'         => 3,
			'unmapped'     => [],
		];
	}//end split()

	/**
	 * Run the command.
	 *
	 * @param array<string, mixed>     $input The input.
	 * @param array<int, array>|null   $plan  The plan inspect() returns; null makes parseKeep throw.
	 *
	 * @return array{0: int, 1: string, 2: array<int, string>} Exit code, display, call log.
	 */
	private function runDedupe(array $input, ?array $plan): array {
		$this->calls = [];

		$dedupe = $this->createMock(SharedSchemaDedupeService::class);
		$dedupe->method('parseKeep')->willReturnCallback(
			function (array $raw) use ($plan): array {
				$this->calls[] = 'parseKeep '.json_encode($raw);
				if ($plan === null) {
					throw new RuntimeException('bad --keep value "x"');
				}

				return ['perSchema' => [], 'global' => null];
			}
		);
		$dedupe->method('inspect')->willReturnCallback(
			function (?int $registerId, array $keep) use ($plan): array {
				$this->calls[] = 'inspect '.var_export($registerId, true);
				return $plan;
			}
		);
		$split = function (array $entry, int $target, bool $strict): array {
			$this->calls[] = 'applySplit '.$entry['schemaId'].' -> '.$target.' strict='.var_export($strict, true);
			if ($target === 99) {
				throw new RuntimeException('lock timeout');
			}

			return ['newSchemaId' => 500, 'rows' => 3, 'unmapped' => [], 'backup' => null];
		};
		$dedupe->method('applySplit')->willReturnCallback(fn (array $entry, int $target): array => $split($entry, $target, false));
		$dedupe->method('applySplitStrictly')->willReturnCallback(fn (array $entry, int $target): array => $split($entry, $target, true));

		$tester = new CommandTester(new DedupeSharedSchemasCommand($dedupe));
		$code   = $tester->execute($input);

		return [$code, $tester->getDisplay(), $this->calls];
	}//end runDedupe()

	/**
	 * Invalid --keep, empty plan, register cast.
	 *
	 * @return void
	 */
	public function testEarlyExits(): void {
		[$code, $display, $calls] = $this->runDedupe(['--keep' => ['x']], null);
		self::assertSame(Command::INVALID, $code);
		self::assertSame("bad --keep value \"x\"\n", $display);
		self::assertSame(['parseKeep ["x"]'], $calls);

		[$code, $display, $calls] = $this->runDedupe(['--register' => '16'], []);
		self::assertSame(Command::SUCCESS, $code);
		self::assertSame("No schema is shared by more than one register. Nothing to do.\n", $display);
		self::assertSame(['parseKeep []', 'inspect 16'], $calls);

		[, , $calls] = $this->runDedupe([], []);
		self::assertSame(['parseKeep []', 'inspect NULL'], $calls);
	}//end testEarlyExits()

	/**
	 * Dry run, refusal, and write; the dry-run and write displays share the plan rendering.
	 *
	 * @return void
	 */
	public function testDryRunRefusalAndWrite(): void {
		$plan = [
			$this->entry(10, 1, [2 => $this->split('b')]),
			$this->entry(11, null, [3 => $this->split('c')]),
		];

		[$dryCode, $dry, $dryCalls] = $this->runDedupe([], $plan);
		self::assertSame(['parseKeep []', 'inspect NULL'], $dryCalls);
		self::assertSame(file_get_contents(__DIR__.'/Golden/dedupe-shared-dry.txt'), $dry);

		[$code, $refused, $calls] = $this->runDedupe(['--write' => true], $plan);
		self::assertSame(Command::FAILURE, $code);
		self::assertSame(['parseKeep []', 'inspect NULL'], $calls);
		self::assertSame(file_get_contents(__DIR__.'/Golden/dedupe-shared-refused.txt'), $refused);

		$attributed = [
			$this->entry(10, 1, [2 => $this->split('b'), 99 => $this->split('z')]),
		];
		[$code, $written, $calls] = $this->runDedupe(['--write' => true, '--strict' => true], $attributed);
		self::assertSame(Command::FAILURE, $code);
		self::assertSame(['parseKeep []', 'inspect NULL', 'applySplit 10 -> 2 strict=true', 'applySplit 10 -> 99 strict=true'], $calls);
		self::assertSame(file_get_contents(__DIR__.'/Golden/dedupe-shared-written.txt'), $written);

		self::assertSame(Command::SUCCESS, $dryCode);
	}//end testDryRunRefusalAndWrite()
}//end class
