<?php

/**
 * Characterisation tests for AppImportJobRecorder's reads: which stored job
 * entries jobs() returns and how they are cast, and which app appForJob()
 * finds for a job id.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Configuration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Configuration;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers take positional args by convention.

use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Service\Configuration\AppImportJobRecorder;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Pins AppImportJobRecorder reads before their units are split.
 */
class AppImportJobRecorderReadGoldenTest extends TestCase {

	/**
	 * A recorder over the given raw app config values.
	 *
	 * @param array<string, string> $config Raw values by key.
	 *
	 * @return AppImportJobRecorder
	 */
	private function recorder(array $config): AppImportJobRecorder {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => ($config[$key] ?? $default)
		);
		$appConfig->method('getKeys')->willReturn(array_keys($config));

		return new AppImportJobRecorder($this->createMock(AuditTrailMapperInterface::class), $appConfig, new NullLogger());
	}//end recorder()

	public function testJobsKeepsWellFormedEntriesAndCastsThem(): void {
		$key = $this->keyFor(appId: 'demo');
		$recorder = $this->recorder(
			config: [
				$key => json_encode(
					[
						'appId' => 'demo',
						'jobs' => [
							['jobId' => 'j1', 'version' => 1.2, 'created' => '7', 'importedAt' => 5],
							['jobId' => 3],
							'not-a-job',
							['version' => 'x'],
							['jobId' => 'j2'],
						],
					]
				),
			]
		);

		$this->assertSame(
			[
				['jobId' => 'j1', 'version' => '1.2', 'created' => 7, 'importedAt' => '5'],
				['jobId' => 'j2', 'version' => '', 'created' => 0, 'importedAt' => ''],
			],
			$recorder->jobs(appId: 'demo')
		);
		$this->assertSame([], $recorder->jobs(appId: 'other'));
		$this->assertSame([], $this->recorder(config: [$key => json_encode(['appId' => 'else', 'jobs' => [['jobId' => 'j']]])])->jobs(appId: 'demo'));
		$this->assertSame([], $this->recorder(config: [$key => 'not json'])->jobs(appId: 'demo'));
		$this->assertSame([], $this->recorder(config: [$key => json_encode(['appId' => 'demo', 'jobs' => 'x'])])->jobs(appId: 'demo'));
	}//end testJobsKeepsWellFormedEntriesAndCastsThem()

	public function testAppForJobScansOnlyRecorderKeys(): void {
		$recorder = $this->recorder(
			config: [
				'unrelated' => json_encode(['appId' => 'nope', 'jobs' => [['jobId' => 'j1']]]),
				$this->keyFor(appId: 'a') => json_encode(['appId' => 'a', 'jobs' => ['x', ['jobId' => 'j0']]]),
				$this->keyFor(appId: 'b') => json_encode(['appId' => 'b', 'jobs' => [['jobId' => 'j1']]]),
				$this->keyFor(appId: 'c') => json_encode(['jobs' => [['jobId' => 'j2']]]),
				$this->keyFor(appId: 'd') => 'broken',
			]
		);

		$this->assertSame('b', $recorder->appForJob(importJobId: 'j1'));
		$this->assertSame('', $recorder->appForJob(importJobId: 'j2'));
		$this->assertNull($recorder->appForJob(importJobId: 'missing'));
	}//end testAppForJobScansOnlyRecorderKeys()

	/**
	 * The app config key the recorder uses for an app id.
	 *
	 * @param string $appId The app id.
	 *
	 * @return string
	 */
	private function keyFor(string $appId): string {
		return (new \ReflectionMethod(AppImportJobRecorder::class, 'key'))->invoke($this->recorder(config: []), $appId);
	}//end keyFor()
}//end class
