<?php

/**
 * ConnectionReporter unit tests.
 *
 * The reporter tells integriq's connection registry what OpenRegister observed.
 * Every test here guards one way it could quietly stop doing that: sending the
 * wrong app or key, sending a status integriq would drop, turning a working
 * connection test into a 500 because a listener threw, logging a fault when
 * integriq is simply not installed, or asking about a connection the save never
 * touched.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Connection
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/adopt-connection-registry/specs/app-connections/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Connection;

use OCA\Integriq\Event\ConnectionRefreshRequestedEvent;
use OCA\Integriq\Event\ConnectionStatusReportedEvent;
use OCA\OpenRegister\Service\Connection\ConnectionReporter;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * Unit tests for ConnectionReporter.
 *
 * The integriq event classes come from tests/stubs/Integriq/Event, which mirror
 * design D6 of the hydra change connection-registry.
 *
 * @covers \OCA\OpenRegister\Service\Connection\ConnectionReporter
 */
class ConnectionReporterTest extends TestCase {

	/**
	 * Mocked event dispatcher.
	 *
	 * @var IEventDispatcher&MockObject
	 */
	private IEventDispatcher $dispatcher;

	/**
	 * Mocked logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * Every event handed to the dispatcher.
	 *
	 * @var array<int, Event>
	 */
	private array $sent = [];

	/**
	 * Set up the fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->dispatcher = $this->createMock(IEventDispatcher::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->sent = [];
		$this->dispatcher->method('dispatchTyped')->willReturnCallback(
			function (Event $event): void {
				$this->sent[] = $event;
			}
		);
	}//end setUp()

	/**
	 * The reporter as production builds it.
	 *
	 * @return ConnectionReporter
	 */
	private function reporter(): ConnectionReporter {
		return new ConnectionReporter(eventDispatcher: $this->dispatcher, logger: $this->logger);
	}//end reporter()

	/**
	 * The reporter as it behaves on an instance without integriq.
	 *
	 * The stubs make both event classes resolvable in this process, so absence
	 * is simulated at the one seam that asks.
	 *
	 * @return ConnectionReporter
	 */
	private function reporterWithoutIntegriq(): ConnectionReporter {
		return new class($this->dispatcher, $this->logger) extends ConnectionReporter {

			/**
			 * Integriq is not installed, so no class resolves.
			 *
			 * @param string $eventClass The class name asked for.
			 *
			 * @return string|null Always null.
			 */
			protected function resolveEventClass(string $eventClass): ?string {
				return null;
			}//end resolveEventClass()
		};
	}//end reporterWithoutIntegriq()

	/**
	 * A report reaches integriq as one event with OpenRegister's id and the words observed.
	 *
	 * @return void
	 */
	public function testAReportIsSentWithTheAppKeyStatusAndMessage(): void {
		$this->assertTrue($this->reporter()->report(key: 'edepot', status: 'error', message: 'Connection failed over sftp.'));

		$this->assertCount(1, $this->sent);
		$event = $this->sent[0];
		$this->assertInstanceOf(ConnectionStatusReportedEvent::class, $event);
		$this->assertSame('openregister', $event->app);
		$this->assertSame('edepot', $event->key);
		$this->assertSame('error', $event->status);
		$this->assertSame('Connection failed over sftp.', $event->message);
	}//end testAReportIsSentWithTheAppKeyStatusAndMessage()

	/**
	 * The event names are the ones the contract fixes, and they resolve here.
	 *
	 * A class-name string is exactly the reference that rots into a silent
	 * no-op after a rename, so it is compared to the stub's real name.
	 *
	 * @return void
	 */
	public function testTheEventNamesAreTheContractNames(): void {
		$this->assertSame(ConnectionStatusReportedEvent::class, ConnectionReporter::STATUS_EVENT);
		$this->assertSame(ConnectionRefreshRequestedEvent::class, ConnectionReporter::REFRESH_EVENT);
	}//end testTheEventNamesAreTheContractNames()

	/**
	 * The class lookup answers null for a class nobody ships.
	 *
	 * This is the real guard, not the test double: an instance without
	 * integriq has no class, and the lookup must say so instead of throwing.
	 *
	 * @return void
	 */
	public function testTheLookupAnswersNullForAnAbsentClass(): void {
		$method = new ReflectionMethod(ConnectionReporter::class, 'resolveEventClass');

		$this->assertNull($method->invoke($this->reporter(), 'OCA\\Nobody\\Event\\ShipsThisEvent'));
		$this->assertSame(
			'\\' . ConnectionReporter::STATUS_EVENT,
			$method->invoke($this->reporter(), ConnectionReporter::STATUS_EVENT)
		);
	}//end testTheLookupAnswersNullForAnAbsentClass()

	/**
	 * Without integriq nothing is sent, nothing is logged and nothing throws.
	 *
	 * @return void
	 */
	public function testWithoutIntegriqNothingIsSentOrLogged(): void {
		$this->dispatcher->expects($this->never())->method('dispatchTyped');
		$this->logger->expects($this->never())->method('warning');
		$this->logger->expects($this->never())->method('error');

		$reporter = $this->reporterWithoutIntegriq();

		$this->assertFalse($reporter->report(key: 'llm', status: 'configured', message: 'ok'));
		$this->assertSame([], $reporter->refreshFromSave(savedKeys: ['github_api_token']));
	}//end testWithoutIntegriqNothingIsSentOrLogged()

	/**
	 * An unknown key is refused with a warning and never sent.
	 *
	 * @return void
	 */
	public function testAnUnknownKeyIsRefused(): void {
		$this->logger->expects($this->once())->method('warning')
			->with($this->stringContains('unknown connection key'), ['key' => 'whatsapp']);

		$this->assertFalse($this->reporter()->report(key: 'whatsapp', status: 'configured'));
		$this->assertSame([], $this->sent);
	}//end testAnUnknownKeyIsRefused()

	/**
	 * A status outside the six is refused, and each of the six is sent.
	 *
	 * @return void
	 */
	public function testStatusMustBeOneOfTheSix(): void {
		$reporter = $this->reporter();

		$this->assertFalse($reporter->report(key: 'llm', status: 'degraded'));
		$this->assertSame([], $this->sent);

		foreach (ConnectionReporter::STATUSES as $status) {
			$this->assertTrue($reporter->report(key: 'llm', status: $status));
		}

		$this->assertCount(6, $this->sent);
		$this->assertContains('limited', ConnectionReporter::STATUSES);
	}//end testStatusMustBeOneOfTheSix()

	/**
	 * A listener that throws never escapes into the request that reported.
	 *
	 * @return void
	 */
	public function testAThrowingListenerNeverEscapes(): void {
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willThrowException(new RuntimeException('registry down'));
		$this->logger->expects($this->exactly(2))->method('warning')
			->with($this->stringContains('Could not send'), $this->anything());

		$reporter = new ConnectionReporter(eventDispatcher: $dispatcher, logger: $this->logger);

		$this->assertFalse($reporter->report(key: 'anonymiser', status: 'configured', message: 'Detected.'));
		$this->assertSame([], $reporter->refreshFromSave(savedKeys: ['github_api_token']));
	}//end testAThrowingListenerNeverEscapes()

	/**
	 * A save asks for a refresh of the connection whose keys it wrote, and sends no status.
	 *
	 * @return void
	 */
	public function testASaveRequestsARefreshForTheTouchedConnection(): void {
		$refreshed = $this->reporter()->refreshFromSave(savedKeys: ['gitlab_api_token', 'gitlab_api_url']);

		$this->assertSame(['gitlab'], $refreshed);
		$this->assertCount(1, $this->sent);
		$event = $this->sent[0];
		$this->assertInstanceOf(ConnectionRefreshRequestedEvent::class, $event);
		$this->assertSame('openregister', $event->app);
		$this->assertSame('gitlab', $event->key);
	}//end testASaveRequestsARefreshForTheTouchedConnection()

	/**
	 * A save that wrote no declared key sends nothing.
	 *
	 * @return void
	 */
	public function testASaveOnlyTouchesTheConnectionsItNamed(): void {
		$this->assertSame([], $this->reporter()->refreshFromSave(savedKeys: ['gitlab_api_url']));
		$this->assertSame([], $this->sent);
	}//end testASaveOnlyTouchesTheConnectionsItNamed()
}//end class
