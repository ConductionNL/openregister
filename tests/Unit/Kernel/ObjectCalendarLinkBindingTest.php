<?php

/**
 * The calendar integration leaf unlinks events through an Integrations port; Processes answers it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
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

use OCA\OpenRegister\Kernel\Contracts\Integrations\ObjectCalendarLinkInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectCalendarEventsInterface;
use OCA\OpenRegister\Service\CalendarEventService;
use OCA\OpenRegister\Service\CalendarLinkService;
use OCA\OpenRegister\Service\Flow\FlowRegistrar;
use OCA\OpenRegister\Service\Flow\FlowWiring;
use OCA\OpenRegister\Service\Integration\Providers\CalendarProviderServices;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Processes binds the port to the calendar event service, which keeps its existing contract.
 */
final class ObjectCalendarLinkBindingTest extends TestCase {

	public function testProcessesBindsThePortToTheCalendarEventService(): void {
		$this->assertSame(CalendarEventService::class, FlowWiring::BINDINGS[ObjectCalendarLinkInterface::class]);
		$this->assertSame(FlowWiring::BINDINGS, FlowRegistrar::BINDINGS);
	}

	public function testTheCalendarEventServiceAnswersBothContracts(): void {
		$this->assertTrue(is_subclass_of(CalendarEventService::class, ObjectCalendarLinkInterface::class));
		$this->assertTrue(is_subclass_of(CalendarEventService::class, ObjectCalendarEventsInterface::class));
	}

	public function testTheProviderBagTypesAgainstThePortAndStillTakesTheService(): void {
		$this->assertSame(ObjectCalendarLinkInterface::class, (string)(new ReflectionProperty(CalendarProviderServices::class, 'calendarEventService'))->getType());

		$service  = $this->createMock(CalendarEventService::class);
		$services = new CalendarProviderServices(calendarEventService: $service, calendarLinkService: $this->createMock(CalendarLinkService::class));
		$this->assertSame($service, $services->calendarEventService);
	}
}//end class
