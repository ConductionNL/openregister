<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\BackgroundJob
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\BackgroundJob;

use OCA\OpenRegister\BackgroundJob\ReviewReminderJob;
use OCA\OpenRegister\Service\Settings\ArchivalSettingsReaderInterface;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * The reminder interval derived from the configured frequency.
 */
class ReviewReminderJobBehaviourTest extends TestCase {

	private function intervalFor(string $frequency): int {
		$settings = $this->createMock(ArchivalSettingsReaderInterface::class);
		$settings->method('getArchivalSettingsOnly')->willReturn(['reviewReminderFrequency' => $frequency]);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with(ArchivalSettingsReaderInterface::class)->willReturn($settings);

		$job = new ReviewReminderJob($this->createMock(ITimeFactory::class), $container);
		return (int)(new \ReflectionProperty($job, 'interval'))->getValue($job);
	}

	public function testAZeroFrequencyFallsBackToAWeek(): void {
		$this->assertSame(604800, $this->intervalFor('PT0S'));
	}

	public function testAPositiveFrequencyIsUsedAsIs(): void {
		$this->assertSame(7200, $this->intervalFor('PT2H'));
	}
}
