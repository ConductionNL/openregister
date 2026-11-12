<?php

/**
 * Shareable config types reach the registry through the registration listener.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
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

use OCA\OpenRegister\Listener\ShareableConfigTypeRegistrationListener;
use OCA\OpenRegister\Service\Config\IShareableConfigType;
use OCA\OpenRegister\Service\Config\RegisterShareableConfigTypesEvent;
use OCA\OpenRegister\Service\Config\SchemaShareableConfigScannerInterface;
use OCA\OpenRegister\Service\Config\ShareableConfigTypeRegistry;
use OCA\OpenRegister\Service\Config\Types\ConfigSetShareableConfigTypeInterface;
use OCA\OpenRegister\Service\Config\Types\FlowShareableConfigTypeInterface;
use OCA\OpenRegister\Service\Config\Types\RegisterSchemaShareableConfigTypeInterface;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The registry collects its types once, by dispatching the registration event the listener answers.
 *
 * @covers \OCA\OpenRegister\Listener\ShareableConfigTypeRegistrationListener
 * @covers \OCA\OpenRegister\Service\Config\RegisterShareableConfigTypesEvent
 * @covers \OCA\OpenRegister\Service\Config\ShareableConfigTypeRegistry
 */
final class ShareableConfigTypeRegistrationListenerTest extends TestCase {

	private IEventDispatcher&MockObject $dispatcher;

	private LoggerInterface&MockObject $logger;

	/**
	 * Fresh collaborators per test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->dispatcher = $this->createMock(IEventDispatcher::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * A type stub of the given interface with the given id.
	 *
	 * @param class-string<IShareableConfigType> $interface The interface.
	 * @param string $id The id.
	 *
	 * @return IShareableConfigType The type.
	 */
	private function type(string $interface, string $id): IShareableConfigType {
		$type = $this->createMock($interface);
		$type->method('getId')->willReturn($id);
		return $type;
	}//end type()

	/**
	 * The listener with the three built-in types and a scanner yielding the given types.
	 *
	 * @param array<IShareableConfigType> $scanned The scanned schema types.
	 *
	 * @return array{0: ShareableConfigTypeRegistrationListener, 1: list<IShareableConfigType>} The listener and the built-ins.
	 */
	private function listener(array $scanned): array {
		$builtIns = [
			$this->type(interface: FlowShareableConfigTypeInterface::class, id: 'flow'),
			$this->type(interface: RegisterSchemaShareableConfigTypeInterface::class, id: 'register'),
			$this->type(interface: ConfigSetShareableConfigTypeInterface::class, id: 'configset'),
		];
		$scanner = $this->createMock(SchemaShareableConfigScannerInterface::class);
		$scanner->method('scan')->willReturn($scanned);

		return [
			new ShareableConfigTypeRegistrationListener(
				flows: $builtIns[0],
				registers: $builtIns[1],
				configSet: $builtIns[2],
				scanner: $scanner
			),
			$builtIns,
		];
	}//end listener()

	/**
	 * The registry, on first read, dispatches once and holds the built-in and scanned types by id.
	 *
	 * @return void
	 */
	public function testTheRegistryCollectsBuiltInAndScannedTypesOnce(): void {
		$scanned = $this->type(interface: IShareableConfigType::class, id: 'schema:contacts');
		[$listener, $builtIns] = $this->listener(scanned: [$scanned]);
		$this->dispatcher->expects($this->once())->method('dispatchTyped')
			->willReturnCallback(static function (Event $event) use ($listener): void {
				$listener->handle($event);
			});
		$registry = new ShareableConfigTypeRegistry(dispatcher: $this->dispatcher, logger: $this->logger);

		$all = $registry->all();

		$this->assertSame(['flow', 'register', 'configset', 'schema:contacts'], array_keys($all));
		$this->assertSame($builtIns[1], $all['register']);
		$this->assertSame($scanned, $registry->get(id: 'schema:contacts'));
		$this->assertNull($registry->get(id: 'unknown'));
	}//end testTheRegistryCollectsBuiltInAndScannedTypesOnce()

	/**
	 * With nothing scanned only the three built-ins register.
	 *
	 * @return void
	 */
	public function testWithoutScannedSchemasOnlyTheBuiltInsRegister(): void {
		[$listener] = $this->listener(scanned: []);
		$registry = new ShareableConfigTypeRegistry(dispatcher: $this->dispatcher, logger: $this->logger);

		$listener->handle(new RegisterShareableConfigTypesEvent(registry: $registry));

		$this->assertSame(['flow', 'register', 'configset'], array_keys($registry->all()));
	}//end testWithoutScannedSchemasOnlyTheBuiltInsRegister()

	/**
	 * Any other event registers nothing.
	 *
	 * @return void
	 */
	public function testOtherEventsRegisterNothing(): void {
		$scanner = $this->createMock(SchemaShareableConfigScannerInterface::class);
		$scanner->expects($this->never())->method('scan');
		$listener = new ShareableConfigTypeRegistrationListener(
			flows: $this->createMock(FlowShareableConfigTypeInterface::class),
			registers: $this->createMock(RegisterSchemaShareableConfigTypeInterface::class),
			configSet: $this->createMock(ConfigSetShareableConfigTypeInterface::class),
			scanner: $scanner
		);

		$listener->handle(new Event());

		$registry = new ShareableConfigTypeRegistry(dispatcher: $this->dispatcher, logger: $this->logger);
		$this->assertSame([], $registry->all());
	}//end testOtherEventsRegisterNothing()

	/**
	 * A dispatch that throws is logged once and leaves the registry empty, not broken.
	 *
	 * @return void
	 */
	public function testAFailingDispatchIsLoggedOnceAndLeavesTheRegistryEmpty(): void {
		$this->dispatcher->expects($this->once())->method('dispatchTyped')->willThrowException(new RuntimeException('boom'));
		$this->logger->expects($this->once())->method('error')
			->with('[ShareableConfigTypeRegistry] Failed to collect types: boom', $this->anything());
		$registry = new ShareableConfigTypeRegistry(dispatcher: $this->dispatcher, logger: $this->logger);

		$this->assertSame([], $registry->all());
		$this->assertNull($registry->get(id: 'flow'));
	}//end testAFailingDispatchIsLoggedOnceAndLeavesTheRegistryEmpty()
}//end class
