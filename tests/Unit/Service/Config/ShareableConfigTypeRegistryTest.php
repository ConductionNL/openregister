<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace Unit\Service\Config;

use OCA\OpenRegister\Service\Config\IShareableConfigType;
use OCA\OpenRegister\Service\Config\ShareableConfigTypeRegistry;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\TestCase;

class ShareableConfigTypeRegistryTest extends TestCase {
	private function type(string $id): IShareableConfigType {
		return new class($id) implements IShareableConfigType {
			public function __construct(
				private string $id,
			) {
			}
			public function getId(): string {
				return $this->id;
			}
			public function getDisplayName(): string {
				return $this->id;
			}
			public function getTopic(): string {
				return $this->id . '-topic';
			}
			public function serialise(array $selection): array {
				return [];
			}
			public function deserialise(array $bundle): array {
				return [];
			}
		};
	}

	public function testRegisteredTypesAreReturnedById(): void {
		$registry = new ShareableConfigTypeRegistry(
			$this->createMock(IEventDispatcher::class),
			$this->createMock(\Psr\Log\LoggerInterface::class)
		);

		$registry->register($this->type('a.one'));
		$registry->register($this->type('b.two'));

		$this->assertCount(2, $registry->all());
		$this->assertSame('a.one', $registry->get('a.one')->getId());
		$this->assertNull($registry->get('missing'));
	}

	public function testTheRegistrationEventIsDispatchedOnceOnFirstRead(): void {
		$dispatcher = $this->createMock(IEventDispatcher::class);
		// Read the catalogue three times; the event dispatches exactly once.
		$dispatcher->expects($this->once())->method('dispatchTyped');

		$registry = new ShareableConfigTypeRegistry($dispatcher, $this->createMock(\Psr\Log\LoggerInterface::class));
		$registry->all();
		$registry->all();
		$registry->get('x');
	}
}
