<?php

/**
 * Golden: which required registers an install imports, keeps or fails, what it
 * records and in which order it asks the instance, pinned on exact calls.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\ExampleSet
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/specs/example-sets/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ExampleSet;

use OCA\OpenRegister\Service\ExampleSet\ExampleSetInstance;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetPeople;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetPrerequisites;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetRecord;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetRegisters;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetPrerequisites
 */
class ExampleSetPrerequisitesGoldenTest extends TestCase {
	/**
	 * Every reason to import, keep or fail a required register, in one plan.
	 *
	 * @return void
	 */
	public function testEnsureRegisters(): void {
		$log = [];
		// After an import, `fresh` and `jobsOnly` are on the instance; `stillMissing` is not.
		$present = ['emptied' => true, 'behind' => true, 'current' => true, 'currentMock' => true];
		$after   = ['missing' => true, 'emptied' => true, 'behind' => true, 'jobsOnly' => true];
		$imports = [
			'missing'      => ['jobs' => ['j1'], 'error' => ''],
			'emptied'      => ['jobs' => [], 'error' => ''],
			'behind'       => ['jobs' => ['j2', 'j3'], 'error' => ''],
			'broken'       => ['jobs' => ['j4'], 'error' => 'descriptor unreadable'],
			'stillMissing' => ['jobs' => [], 'error' => ''],
			'jobsOnly'     => ['jobs' => ['j5'], 'error' => ''],
		];
		$imported  = [];
		$registers = $this->createMock(ExampleSetRegisters::class);
		$registers->method('exists')->willReturnCallback(function (string $slug) use (&$log, $present, $after, &$imported) {
			$log[] = 'exists '.$slug;
			if (isset($imported[$slug]) === true) {
				return isset($after[$slug]);
			}

			return isset($present[$slug]);
		});
		$registers->method('isBehind')->willReturnCallback(function (string $slug, bool $mock) use (&$log) {
			$log[] = 'isBehind '.$slug.' '.var_export($mock, true);
			return $slug === 'behind';
		});
		$registers->method('import')->willReturnCallback(function (string $slug) use (&$log, $imports, &$imported) {
			$log[]           = 'import '.$slug;
			$imported[$slug] = true;
			return $imports[$slug];
		});
		$registers->method('importMock')->willReturnCallback(function (string $slug) use (&$log, $imports, &$imported) {
			$log[]           = 'importMock '.$slug;
			$imported[$slug] = true;
			return $imports[$slug];
		});
		$record = $this->createMock(ExampleSetRecord::class);
		$record->method('write')->willReturnCallback(function (string $set, array $written) use (&$log) {
			$log[] = 'write '.$set.' '.json_encode($written['registers']);
		});
		$prerequisites = new ExampleSetPrerequisites(
			$registers,
			$this->createMock(ExampleSetInstance::class),
			$record,
			$this->createMock(ExampleSetPeople::class)
		);

		$plan = [
			'set'      => 'demo',
			'requires' => [
				'missing'      => [],
				'emptied'      => ['mock' => false],
				'behind'       => ['mock' => true],
				'current'      => [],
				'currentMock'  => ['mock' => true],
				'broken'       => ['mock' => 'yes'],
				'stillMissing' => [],
				'jobsOnly'     => ['mock' => true],
			],
		];
		$book = ['registers' => ['emptied' => [], 'current' => ['old'], 'jobsOnly' => ['j0']]];

		$result = $prerequisites->ensure($plan, $book);

		$this->assertSame(
			[
				'refusals'  => [
					'register "broken" is required and could not be imported from OpenRegister\'s descriptor: descriptor unreadable',
					'register "stillMissing" is required and could not be imported from OpenRegister\'s descriptor: it is still not on this instance',
				],
				'registers' => [
					'missing'      => 'imported',
					'emptied'      => 'imported',
					'behind'       => 'imported',
					'current'      => 'kept',
					'currentMock'  => 'kept',
					'broken'       => 'failed',
					'stillMissing' => 'failed',
					'jobsOnly'     => 'imported',
				],
			],
			$result
		);
		$this->assertSame(
			['emptied' => [], 'current' => ['old'], 'jobsOnly' => ['j0', 'j5'], 'missing' => ['j1'], 'behind' => ['j2', 'j3'], 'broken' => ['j4']],
			$book['registers']
		);
		$this->assertSame(
			[
				'exists missing',
				'import missing',
				'write demo {"emptied":[],"current":["old"],"jobsOnly":["j0"],"missing":["j1"]}',
				'exists missing',
				'exists emptied',
				'import emptied',
				'exists emptied',
				'exists behind',
				'isBehind behind true',
				'importMock behind',
				'write demo {"emptied":[],"current":["old"],"jobsOnly":["j0"],"missing":["j1"],"behind":["j2","j3"]}',
				'exists behind',
				'exists current',
				'isBehind current false',
				'exists currentMock',
				'isBehind currentMock true',
				'exists broken',
				'import broken',
				'write demo {"emptied":[],"current":["old"],"jobsOnly":["j0"],"missing":["j1"],"behind":["j2","j3"],"broken":["j4"]}',
				'exists stillMissing',
				'import stillMissing',
				'exists stillMissing',
				'exists jobsOnly',
				'importMock jobsOnly',
				'write demo {"emptied":[],"current":["old"],"jobsOnly":["j0","j5"],"missing":["j1"],"behind":["j2","j3"],"broken":["j4"]}',
				'exists jobsOnly',
			],
			$log
		);
	}//end testEnsureRegisters()

	/**
	 * A plan without required registers or organisations imports and refuses nothing.
	 *
	 * @return void
	 */
	public function testNothingRequired(): void {
		$registers = $this->createMock(ExampleSetRegisters::class);
		$registers->expects($this->never())->method('exists');
		$prerequisites = new ExampleSetPrerequisites(
			$registers,
			$this->createMock(ExampleSetInstance::class),
			$this->createMock(ExampleSetRecord::class),
			$this->createMock(ExampleSetPeople::class)
		);
		$book = ['registers' => []];

		$this->assertSame(['refusals' => [], 'registers' => []], $prerequisites->ensure(['set' => 'demo'], $book));
		$this->assertSame(['registers' => []], $book);
	}//end testNothingRequired()
}//end class
