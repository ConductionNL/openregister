<?php

/**
 * SourceFetcherRegistrationListener: OpenRegister registers its own fetchers through the event apps use.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Listener;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use GuzzleHttp\Client;
use OCA\OpenRegister\Listener\SourceFetcherRegistrationListener;
use OCA\OpenRegister\Service\Sync\RegisterSourceFetchersEvent;
use OCA\OpenRegister\Service\Sync\RestApiSourceFetcher;
use OCA\OpenRegister\Service\Sync\SourceFetcherRegistry;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Security\ICrypto;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenRegister\Listener\SourceFetcherRegistrationListener
 * @uses \OCA\OpenRegister\Service\Sync\RegisterSourceFetchersEvent
 * @uses \OCA\OpenRegister\Service\Sync\SourceFetcherRegistry
 * @uses \OCA\OpenRegister\Service\Sync\RestApiSourceFetcher
 */
class SourceFetcherRegistrationListenerTest extends TestCase {

	private function restApi(): RestApiSourceFetcher {
		return new RestApiSourceFetcher(
			$this->createMock(Client::class),
			$this->createMock(ICrypto::class),
			$this->createMock(LoggerInterface::class)
		);
	}

	public function testTheRestFetcherArrivesThroughTheEvent(): void {
		$restApi = $this->restApi();
		$listener = new SourceFetcherRegistrationListener($restApi);
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(
			static function (Event $event) use ($listener): void {
				$listener->handle($event);
			}
		);
		$registry = new SourceFetcherRegistry([], $dispatcher);

		$this->assertSame($restApi, $registry->get('rest-api'));
		$this->assertSame($restApi, $registry->get('openregister'));
		$this->assertSame(['rest-api'], array_column($registry->types(), 'type'));
		$this->assertSame('openregister', $registry->types()[0]['application']);
	}
}
