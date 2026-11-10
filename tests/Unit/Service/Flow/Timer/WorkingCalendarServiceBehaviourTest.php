<?php

/**
 * Which working calendar a timer runs on: the named one, else the one its
 * organisation owns, else the national default; memoised per pass and
 * refused (never substituted) when the slug is unknown.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Flow\Timer
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow\Timer;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.

use OCA\OpenRegister\Exception\FlowTimerValidationException;
use OCA\OpenRegister\Service\Flow\Timer\FlowTimerDefinitionStore;
use OCA\OpenRegister\Service\Flow\Timer\WorkingCalendarService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * WorkingCalendarService::resolve() and reset().
 */
class WorkingCalendarServiceBehaviourTest extends TestCase {

	private FlowTimerDefinitionStore&MockObject $definitions;

	protected function setUp(): void {
		$this->definitions = $this->createMock(FlowTimerDefinitionStore::class);
		$this->definitions->method('calendars')->willReturn(
			[
				'nl-national' => self::definition('nl-national', null),
				'gemeente-a' => self::definition('gemeente-a', 'org-a'),
				'gemeente-b' => self::definition('gemeente-b', ' org-b '),
			]
		);
	}//end setUp()

	/**
	 * @return array<string, mixed>
	 */
	private static function definition(string $slug, ?string $organisation): array {
		$definition = ['slug' => $slug, 'workingWeekdays' => [1, 2, 3, 4, 5], 'hoursPerWorkingDay' => 8];
		if ($organisation !== null) {
			$definition['organisation'] = $organisation;
		}

		return $definition;
	}//end definition()

	public function testANamedCalendarWinsOverTheOrganisation(): void {
		$calendar = (new WorkingCalendarService($this->definitions))->resolve(' gemeente-b ', 'org-a');

		$this->assertSame('gemeente-b', $calendar->getSlug());
	}//end testANamedCalendarWinsOverTheOrganisation()

	public function testWithoutANameTheOrganisationsOwnCalendarIsUsed(): void {
		$service = new WorkingCalendarService($this->definitions);

		$this->assertSame('gemeente-a', $service->resolve(null, ' org-a ')->getSlug());
		$this->assertSame('gemeente-b', $service->resolve('', 'org-b')->getSlug());
	}//end testWithoutANameTheOrganisationsOwnCalendarIsUsed()

	public function testAnOrganisationWithoutACalendarAndNoOrganisationFallBackToTheDefault(): void {
		$service = new WorkingCalendarService($this->definitions);

		$this->assertSame('nl-national', $service->resolve(null, 'org-without-calendar')->getSlug());
		$this->assertSame('nl-national', $service->resolve('  ', '  ')->getSlug());
		$this->assertSame('nl-national', $service->resolve(null, null)->getSlug());
	}//end testAnOrganisationWithoutACalendarAndNoOrganisationFallBackToTheDefault()

	public function testAnUnknownSlugIsRefusedListingTheKnownCalendars(): void {
		$this->expectException(FlowTimerValidationException::class);
		$this->expectExceptionMessage(
			"Working calendar 'mars' does not exist; known calendars: nl-national, gemeente-a, gemeente-b. No weekday-only substitute is made."
		);

		(new WorkingCalendarService($this->definitions))->resolve('mars', null);
	}//end testAnUnknownSlugIsRefusedListingTheKnownCalendars()

	public function testAResolvedCalendarIsMemoisedUntilReset(): void {
		$definitions = $this->createMock(FlowTimerDefinitionStore::class);
		$definitions->expects($this->exactly(2))->method('calendars')
			->willReturn(['nl-national' => self::definition('nl-national', null)]);
		$definitions->expects($this->once())->method('reset');
		$service = new WorkingCalendarService($definitions);

		$first = $service->resolve(null, null);
		$this->assertSame($first, $service->resolve('nl-national', null));

		$service->reset();
		$afterReset = $service->resolve(null, null);

		$this->assertNotSame($first, $afterReset);
		$this->assertSame('nl-national', $afterReset->getSlug());
	}//end testAResolvedCalendarIsMemoisedUntilReset()
}//end class
