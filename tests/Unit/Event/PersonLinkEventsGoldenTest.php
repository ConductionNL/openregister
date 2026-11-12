<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Event;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- PHPUnit arrange/act/assert conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Db\ContactLink;
use OCA\OpenRegister\Event\PersonLinkedEvent;
use OCA\OpenRegister\Event\PersonLinkUpdatedEvent;
use OCA\OpenRegister\Event\PersonUnlinkedEvent;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Pins what dossiq reads off the person link events.
 */
class PersonLinkEventsGoldenTest extends TestCase {
	public static function events(): array {
		return [
			'linked' => [PersonLinkedEvent::class],
			'updated' => [PersonLinkUpdatedEvent::class],
			'unlinked' => [PersonUnlinkedEvent::class],
		];
	}//end events()

	#[DataProvider('events')]
	public function testTheLinkTravelsUnderItsNamedArgument(string $class): void {
		$link  = new ContactLink();
		$event = new $class(link: $link);

		$this->assertSame($link, $event->getLink());
		$this->assertInstanceOf(Event::class, $event);
		$this->assertFalse((new ReflectionClass($class))->isAbstract());
	}//end testTheLinkTravelsUnderItsNamedArgument()

	#[DataProvider('events')]
	public function testNoEventIsAnother(string $class): void {
		foreach (array_column(self::events(), 0) as $other) {
			$this->assertFalse(is_subclass_of($class, $other), $class . ' is a ' . $other);
		}
	}//end testNoEventIsAnother()
}//end class
