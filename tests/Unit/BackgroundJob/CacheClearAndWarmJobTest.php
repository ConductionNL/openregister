<?php

/**
 * CacheClearAndWarmJob clears the name cache before warming it.
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
use OCA\OpenRegister\BackgroundJob\CacheClearAndWarmJob;
use OCA\OpenRegister\Kernel\Contracts\ObjectNameCacheInterface;
use OCA\OpenRegister\Service\Operations\JobRunRecorderInterface;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Behaviour of the cache clear-and-warm job body.
 */
class CacheClearAndWarmJobTest extends TestCase {

	public function testClearsThenWarms(): void {
		$calls = [];
		$cache = $this->createMock(ObjectNameCacheInterface::class);
		$cache->expects($this->once())->method('clearAllCaches')->willReturnCallback(
			function () use (&$calls): void {
				$calls[] = 'clear';
			}
		);
		$cache->expects($this->once())->method('warmupNameCache')->willReturnCallback(
			function () use (&$calls): int {
				$calls[] = 'warm';
				return 12;
			}
		);

		$job = new CacheClearAndWarmJob(
			new RecordedJobRuntime($this->createMock(ITimeFactory::class), $this->createMock(JobRunRecorderInterface::class)),
			$cache
		);
		(new ReflectionMethod(CacheClearAndWarmJob::class, 'runRecorded'))->invoke($job, null);

		$this->assertSame(['clear', 'warm'], $calls);
	}
}
