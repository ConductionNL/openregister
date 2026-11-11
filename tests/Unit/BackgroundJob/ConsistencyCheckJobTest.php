<?php

/**
 * ConsistencyCheckJob stores the check findings, stamped, as the last result.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\BackgroundJob
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\BackgroundJob;

use OCA\OpenRegister\BackgroundJob\RecordedJobRuntime;
use OCA\OpenRegister\BackgroundJob\ConsistencyCheckJob;
use OCA\OpenRegister\Service\Operations\ConsistencyCheckServiceInterface;
use OCA\OpenRegister\Service\Operations\JobRunRecorderInterface;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Behaviour of the consistency check job's recorded body.
 */
class ConsistencyCheckJobTest extends TestCase {

	/**
	 * Run the job body and return what it stored.
	 *
	 * @param array $findings What the check returns.
	 *
	 * @return array{0: string, 1: string, 2: string} app id, key and value stored.
	 */
	private function runWith(array $findings): array {
		$check = $this->createMock(ConsistencyCheckServiceInterface::class);
		$check->expects($this->once())->method('check')->willReturn($findings);

		$stored = [];
		$config = $this->createMock(IConfig::class);
		$config->expects($this->once())->method('setAppValue')->willReturnCallback(
			function (string $app, string $key, string $value) use (&$stored): void {
				$stored = [$app, $key, $value];
			}
		);

		$job = new ConsistencyCheckJob(
			new RecordedJobRuntime($this->createMock(ITimeFactory::class), $this->createMock(JobRunRecorderInterface::class)),
			$check,
			$config
		);
		(new ReflectionMethod(ConsistencyCheckJob::class, 'runRecorded'))->invoke($job, null);

		return $stored;
	}

	public function testFindingsAreStoredAsJsonWithARunTimestamp(): void {
		[$app, $key, $value] = $this->runWith(['orphans' => 3, 'issues' => ['a']]);

		$this->assertSame('openregister', $app);
		$this->assertSame('operations_consistency_last', $key);
		$decoded = json_decode($value, true);
		$this->assertSame(3, $decoded['orphans']);
		$this->assertSame(['a'], $decoded['issues']);
		$this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/', $decoded['ranAt']);
	}

	public function testUnencodableFindingsStoreAnEmptyObject(): void {
		[$app, $key, $value] = $this->runWith(['bad' => "\xB1\x31"]);

		$this->assertSame('operations_consistency_last', $key);
		$this->assertSame('{}', $value);
	}
}
