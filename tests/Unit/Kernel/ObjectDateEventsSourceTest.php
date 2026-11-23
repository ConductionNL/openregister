<?php

/**
 * ObjectDateEventsSourceInterface is answered by the object calendar feed generator.
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

use OCA\OpenRegister\Db\CalendarFeedToken;
use OCA\OpenRegister\Kernel\Contracts\Integrations\ObjectDateEventsSourceInterface;
use OCA\OpenRegister\Service\Calendar\ObjectCalendarFeedService;
use OCA\OpenRegister\Service\Flow\FlowWiring;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * The platform calendar asks the Integrations port; the feed generator (Processes) answers it.
 */
final class ObjectDateEventsSourceTest extends TestCase {

	public function testTheProcessesWiringBindsThePortToTheFeedGenerator(): void {
		$this->assertSame(ObjectCalendarFeedService::class, FlowWiring::BINDINGS[ObjectDateEventsSourceInterface::class]);
		$this->assertTrue(is_subclass_of(ObjectCalendarFeedService::class, ObjectDateEventsSourceInterface::class));
	}

	public function testTheFeedTokenScopesAreThePortScopes(): void {
		$this->assertSame('schema', ObjectDateEventsSourceInterface::SCOPE_SCHEMA);
		$this->assertSame('view', ObjectDateEventsSourceInterface::SCOPE_VIEW);
		$this->assertSame(ObjectDateEventsSourceInterface::SCOPE_SCHEMA, CalendarFeedToken::SCOPE_SCHEMA);
		$this->assertSame(ObjectDateEventsSourceInterface::SCOPE_VIEW, CalendarFeedToken::SCOPE_VIEW);
	}

	public function testAConfigurationWithDateKindsDeclaresDates(): void {
		$config = ['enabled' => true, 'dates' => ['beslistermijn' => ['kind' => 'deadline', 'alarmOffsetDays' => 2]]];

		$this->assertTrue($this->generator()->declaresDates(calendarConfig: $config));
	}

	public function testAConfigurationWithoutDateKindsDeclaresNone(): void {
		$this->assertFalse($this->generator()->declaresDates(calendarConfig: ['enabled' => true, 'dtstart' => 'datum']));
	}

	public function testAnUnreadableDeclarationThrows(): void {
		$this->expectException(Throwable::class);

		$this->generator()->declaresDates(calendarConfig: ['dates' => ['beslistermijn' => ['kind' => 'no-such-kind']]]);
	}

	private function generator(): ObjectCalendarFeedService {
		return $this->createPartialMock(ObjectCalendarFeedService::class, ['eventsFor']);
	}
}//end class
