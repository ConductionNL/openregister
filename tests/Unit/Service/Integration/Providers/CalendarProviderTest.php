<?php

/**
 * Unit tests for CalendarProvider after Tier-2 rewire.
 *
 * Covers:
 *  - metadata getters and isEnabled()
 *  - list(): now goes through CalendarLinkService::getLinkedEvents (UNION
 *    of link-table + X-OR-* scan), not the legacy CalendarEventService scan
 *  - delete(): legacy "calendarId/eventUri" shape still strips X-OR-*;
 *    new bare-uid shape calls CalendarLinkService::unlinkEvent
 *  - health(): reports unavailable when calendar app is not installed
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Integration\Providers
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2024 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Integration\Providers;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters

use OCA\OpenRegister\Service\CalendarEventService;
use OCA\OpenRegister\Service\CalendarLinkService;
use OCA\OpenRegister\Service\Integration\Providers\CalendarProvider;
use OCP\App\IAppManager;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class CalendarProviderTest extends TestCase {
	private function buildL10n(): IL10N {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		return $l10n;
	}

	private function buildLogger(): LoggerInterface {
		return $this->createMock(LoggerInterface::class);
	}

	public function testMetadataGetters(): void {
		$provider = new CalendarProvider(
			$this->createMock(CalendarEventService::class),
			$this->createMock(CalendarLinkService::class),
			$this->createMock(IAppManager::class),
			$this->buildL10n(),
			$this->buildLogger(),
		);

		$this->assertSame('calendar', $provider->getId());
		$this->assertSame('Meetings', $provider->getLabel());
		$this->assertSame('Calendar', $provider->getIcon());
		$this->assertSame('comms', $provider->getGroup());
		$this->assertSame('calendar', $provider->getRequiredApp());
		$this->assertSame('link-table', $provider->getStorageStrategy());
	}

	public function testListGoesThroughLinkServiceUnion(): void {
		$link = $this->createMock(CalendarLinkService::class);
		$link->expects($this->once())
			->method('getLinkedEvents')
			->with('obj-1')
			->willReturn([
				['uid' => 'ev-1', 'source' => 'both', 'summary' => 'Hello'],
			]);

		$provider = new CalendarProvider(
			$this->createMock(CalendarEventService::class),
			$link,
			$this->createMock(IAppManager::class),
			$this->buildL10n(),
			$this->buildLogger(),
		);

		$result = $provider->list('r', 's', 'obj-1');
		$this->assertCount(1, $result);
		$this->assertSame('both', $result[0]['source']);
	}

	public function testDeleteLegacyShapeStripsXor(): void {
		$eventSvc = $this->createMock(CalendarEventService::class);
		$eventSvc->expects($this->once())
			->method('unlinkEvent')
			->with('7', 'event.ics');

		$linkSvc = $this->createMock(CalendarLinkService::class);
		$linkSvc->expects($this->never())->method('unlinkEvent');

		$provider = new CalendarProvider(
			$eventSvc,
			$linkSvc,
			$this->createMock(IAppManager::class),
			$this->buildL10n(),
			$this->buildLogger(),
		);

		$provider->delete('r', 's', 'obj-1', '7/event.ics');
	}

	public function testDeleteBareUidUsesLinkService(): void {
		$eventSvc = $this->createMock(CalendarEventService::class);
		$eventSvc->expects($this->never())->method('unlinkEvent');

		$linkSvc = $this->createMock(CalendarLinkService::class);
		$linkSvc->expects($this->once())
			->method('unlinkEvent')
			->with('obj-1', 'ev-uid');

		$provider = new CalendarProvider(
			$eventSvc,
			$linkSvc,
			$this->createMock(IAppManager::class),
			$this->buildL10n(),
			$this->buildLogger(),
		);

		$provider->delete('r', 's', 'obj-1', 'ev-uid');
	}

	public function testHealthReportsUnavailableWhenNotInstalled(): void {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isInstalled')->with('calendar')->willReturn(false);

		$provider = new CalendarProvider(
			$this->createMock(CalendarEventService::class),
			$this->createMock(CalendarLinkService::class),
			$appManager,
			$this->buildL10n(),
			$this->buildLogger(),
		);

		$this->assertFalse($provider->isEnabled());
		$h = $provider->health();
		$this->assertSame('unavailable', $h['status']);
	}
}
