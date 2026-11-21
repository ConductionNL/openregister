<?php

/**
 * Unit tests for ShareableConfigTypeRegistry's lazy collection of types.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Config
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Config;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Service\Config\IShareableConfigType;
use OCA\OpenRegister\Service\Config\RegisterShareableConfigTypesEvent;
use OCA\OpenRegister\Service\Config\ShareableConfigTypeRegistry;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

final class ShareableConfigTypeRegistryLoadTest extends TestCase {

	private function type(string $id): IShareableConfigType {
		$type = $this->createMock(IShareableConfigType::class);
		$type->method('getId')->willReturn($id);
		return $type;
	}

	public function testTypesAListenerRegistersThroughTheEventAreServed(): void {
		$schemas    = $this->type('schemas');
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->expects($this->once())->method('dispatchTyped')
			->with($this->isInstanceOf(RegisterShareableConfigTypesEvent::class))
			->willReturnCallback(static function (RegisterShareableConfigTypesEvent $event) use ($schemas): void {
				$event->registerType($schemas);
			});

		$registry = new ShareableConfigTypeRegistry($dispatcher, $this->createMock(LoggerInterface::class));

		$this->assertSame($schemas, $registry->get('schemas'));
		$this->assertSame(['schemas' => $schemas], $registry->all());
		$this->assertNull($registry->get('views'));
	}

	public function testALaterRegistrationUnderTheSameIdReplacesTheEarlierOne(): void {
		$registry = new ShareableConfigTypeRegistry($this->createMock(IEventDispatcher::class), $this->createMock(LoggerInterface::class));
		$first    = $this->type('x');
		$second   = $this->type('x');

		$registry->register($first);
		$registry->register($second);

		$this->assertSame($second, $registry->get('x'));
		$this->assertCount(1, $registry->all());
	}

	public function testAFailingListenerIsLoggedOnceAndLeavesTheDirectRegistrations(): void {
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->expects($this->once())->method('dispatchTyped')->willThrowException(new RuntimeException('listener broke'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error')
			->with($this->stringContains('Failed to collect types: listener broke'));

		$registry = new ShareableConfigTypeRegistry($dispatcher, $logger);
		$direct   = $this->type('direct');
		$registry->register($direct);

		$this->assertSame(['direct' => $direct], $registry->all());
		$this->assertSame($direct, $registry->get('direct'));
	}
}
