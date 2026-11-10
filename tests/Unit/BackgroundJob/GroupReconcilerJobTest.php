<?php

/**
 * GroupReconcilerJob sweeps hourly and never lets a failure escape.
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

use OCA\OpenRegister\BackgroundJob\JobRuntime;
use OCA\OpenRegister\BackgroundJob\GroupReconcilerJob;
use OCA\OpenRegister\Service\Authorization\GroupReconcilerInterface;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * Behaviour of the group reconciler sweep.
 */
class GroupReconcilerJobTest extends TestCase {

	public function testIntervalIsHourly(): void {
		$job = new GroupReconcilerJob(
			new JobRuntime($this->createMock(ITimeFactory::class), $this->createMock(LoggerInterface::class)),
			$this->createMock(GroupReconcilerInterface::class)
		);

		$this->assertSame(3600, $job->getInterval());
	}

	public function testSweepDelegatesAndLogsNothing(): void {
		$reconciler = $this->createMock(GroupReconcilerInterface::class);
		$reconciler->expects($this->once())->method('reconcile')->willReturn([]);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('error');

		$job = new GroupReconcilerJob(new JobRuntime($this->createMock(ITimeFactory::class), $logger), $reconciler);
		(new ReflectionMethod(GroupReconcilerJob::class, 'run'))->invoke($job, null);
	}

	public function testSweepFailureIsLoggedNotThrown(): void {
		$reconciler = $this->createMock(GroupReconcilerInterface::class);
		$reconciler->method('reconcile')->willThrowException(new RuntimeException('ldap down'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error')->with(
			'[GroupReconcilerJob] sweep failed: ldap down',
			$this->callback(fn (array $context): bool => isset($context['file'], $context['line']) === true)
		);

		$job = new GroupReconcilerJob(new JobRuntime($this->createMock(ITimeFactory::class), $logger), $reconciler);
		(new ReflectionMethod(GroupReconcilerJob::class, 'run'))->invoke($job, null);
	}
}
