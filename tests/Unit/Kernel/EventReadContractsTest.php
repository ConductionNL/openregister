<?php

/**
 * Unit tests for the Kernel read contracts the view alert and person link events carry.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Kernel
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Kernel;

use OCA\OpenRegister\Db\ContactLink;
use OCA\OpenRegister\Event\PersonLinkedEvent;
use OCA\OpenRegister\Event\PersonLinkUpdatedEvent;
use OCA\OpenRegister\Event\PersonUnlinkedEvent;
use OCA\OpenRegister\Event\ViewAlertCrossedEvent;
use OCA\OpenRegister\Kernel\Contracts\ContactLinkInterface;
use OCA\OpenRegister\Kernel\Contracts\ViewAlertInterface;
use OCA\OpenRegister\Search\Db\View;
use OCA\OpenRegister\Service\View\ViewAlert;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The events hand listeners the same objects as before, read through Kernel contracts.
 *
 * @spec openspec/changes/saved-view-count-alert/specs/saved-search-views/spec.md#requirement-a-view-alert-fires-once-per-crossing-and-re-arms
 */
final class EventReadContractsTest extends TestCase {

	public function testTheAlertGettersReadTheParsedDeclaration(): void {
		$alert = ViewAlert::parse(
			['operator' => 'lte', 'threshold' => 3, 'recipients' => ['user:ann', 'staff'], 'channels' => ['notification', 'email'], 'every' => 600]
		);

		self::assertInstanceOf(ViewAlertInterface::class, $alert);
		self::assertSame($alert->operator, $alert->getOperator());
		self::assertSame('lte', $alert->getOperator());
		self::assertSame(3, $alert->getThreshold());
		self::assertSame(['user:ann', 'staff'], $alert->getRecipients());
		self::assertSame(['notification', 'email'], $alert->getChannels());
	}

	public function testTheCrossingCarriesTheAlertItWasGiven(): void {
		$alert = ViewAlert::parse(['operator' => 'gte', 'threshold' => 10, 'recipients' => ['user:ann'], 'channels' => ['notification']]);
		$event = new ViewAlertCrossedEvent(view: new View(), alert: $alert, count: 12);

		self::assertSame($alert, $event->getAlert());
		self::assertSame(12, $event->getCount());
	}

	/**
	 * @return array<string, array{0: class-string}>
	 */
	public static function personEvents(): array {
		return [
			'linked' => [PersonLinkedEvent::class],
			'updated' => [PersonLinkUpdatedEvent::class],
			'unlinked' => [PersonUnlinkedEvent::class],
		];
	}

	#[DataProvider('personEvents')]
	public function testAPersonEventCarriesTheStoredLinkAndItsJson(string $class): void {
		$link = new ContactLink();
		$link->setObjectUuid('o-1');
		$link->setContactUid('user:ann');
		$event = new $class(link: $link);

		self::assertInstanceOf(ContactLinkInterface::class, $event->getLink());
		self::assertSame($link, $event->getLink());
		self::assertSame($link->jsonSerialize(), $event->getLink()->jsonSerialize());
	}
}
