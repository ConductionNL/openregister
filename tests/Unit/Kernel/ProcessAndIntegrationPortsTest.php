<?php

/**
 * The Twig functions, user calendar events and task gauge ports are bound to the classes behind them.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Kernel
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Kernel;

use OCA\OpenRegister\AppHost\AppHostWiring;
use OCA\OpenRegister\AppHost\IMetricsProvider;
use OCA\OpenRegister\Kernel\Contracts\Core\TwigFunctionsInstallerInterface;
use OCA\OpenRegister\Kernel\Contracts\Core\UserCalendarEventsInterface;
use OCA\OpenRegister\Kernel\Contracts\Integrations\TaskGaugeInterface;
use OCA\OpenRegister\Service\CalendarEventService;
use OCA\OpenRegister\Service\Flow\FlowWiring;
use OCA\OpenRegister\Service\Task\TaskMetricsProvider;
use OCA\OpenRegister\Service\Task\TaskMetricsProviderInterface;
use OCA\OpenRegister\Twig\MappingTwigInstaller;
use OCA\OpenRegister\Twig\MappingTwigInstallerInterface;
use PHPUnit\Framework\TestCase;

/**
 * Each port is owned by the lower area, implemented by the higher one and bound in that area's own wiring.
 */
final class ProcessAndIntegrationPortsTest extends TestCase {

	public function testTheMappingInstallerIsTheTwigFunctionsInstaller(): void {
		$this->assertTrue(is_subclass_of(MappingTwigInstallerInterface::class, TwigFunctionsInstallerInterface::class));
		$this->assertTrue(is_subclass_of(MappingTwigInstaller::class, TwigFunctionsInstallerInterface::class));
		$this->assertSame(['installOn'], get_class_methods(TwigFunctionsInstallerInterface::class));
		$this->assertSame(MappingTwigInstaller::class, AppHostWiring::BINDINGS[TwigFunctionsInstallerInterface::class]);
		$this->assertSame(MappingTwigInstaller::class, AppHostWiring::BINDINGS[MappingTwigInstallerInterface::class]);
	}

	public function testTheCalendarEventServiceListsTheUserEvents(): void {
		$this->assertTrue(is_subclass_of(CalendarEventService::class, UserCalendarEventsInterface::class));
		$this->assertSame(['getAllUserEvents'], get_class_methods(UserCalendarEventsInterface::class));
		$this->assertSame(CalendarEventService::class, FlowWiring::BINDINGS[UserCalendarEventsInterface::class]);
	}

	public function testTheTaskMetricsProviderIsTheTaskGauge(): void {
		$this->assertTrue(is_subclass_of(TaskGaugeInterface::class, IMetricsProvider::class));
		$this->assertTrue(is_subclass_of(TaskMetricsProviderInterface::class, TaskGaugeInterface::class));
		$this->assertTrue(is_subclass_of(TaskMetricsProvider::class, TaskGaugeInterface::class));
		$this->assertSame(TaskMetricsProvider::class, FlowWiring::BINDINGS[TaskGaugeInterface::class]);
	}
}//end class
