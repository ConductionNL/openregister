<?php

/**
 * FlowScheduleService and FlowValueRenderer: a schedule that cannot be evaluated never fires, and an array placed inside a longer string renders as JSON.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Flow
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow;

use DateTimeImmutable;
use OCA\OpenRegister\Service\Flow\FlowLocator;
use OCA\OpenRegister\Service\Flow\FlowRunServiceInterface;
use OCA\OpenRegister\Service\Flow\FlowScheduleService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenRegister\Service\Flow\FlowScheduleService
 */
class FlowScheduleUnreadableBehaviourTest extends TestCase {

	/**
	 * @param array<string, mixed> $candidate
	 */
	private function service(array $candidate, FlowRunServiceInterface $runs): FlowScheduleService {
		$locator = $this->createMock(FlowLocator::class);
		$locator->method('scheduledFlows')->willReturn([$candidate]);
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnArgument(2);

		return new FlowScheduleService($locator, $runs, $config, $this->createMock(LoggerInterface::class));
	}//end service()

	/**
	 * @return array<string, mixed>
	 */
	private function candidate(string $cron, string $since): array {
		return ['id' => 'f1', 'enabled' => true, 'trigger' => 'schedule', 'cron' => $cron, 'owner' => 'pieter', 'since' => $since];
	}//end candidate()

	public function testAnUnreadableBaselineNeverFires(): void {
		$runs = $this->createMock(FlowRunServiceInterface::class);
		$runs->expects(self::never())->method('queue');

		self::assertSame([], $this->service($this->candidate('* * * * *', 'last tuesday-ish at noonish'), $runs)->fireDueFlows(new DateTimeImmutable('2026-12-02T06:03:00+00:00')));
	}//end testAnUnreadableBaselineNeverFires()

	public function testTheSameBaselineFiresWhenReadable(): void {
		$runs = $this->createMock(FlowRunServiceInterface::class);
		$runs->expects(self::once())->method('queue');

		self::assertSame(['f1'], $this->service($this->candidate('* * * * *', '2026-12-02T05:00:00+00:00'), $runs)->fireDueFlows(new DateTimeImmutable('2026-12-02T06:03:00+00:00')));
	}//end testTheSameBaselineFiresWhenReadable()
}//end class
