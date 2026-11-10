<?php

/**
 * Characterisation of SubscriptionService's event-stream filter and its per-event RBAC check.
 *
 * APCu is absent in the unit sandbox, so eventsMatching() answers [] before it
 * filters; the filter and the RBAC check are pinned directly. Both the allowed
 * and the refused outcomes are pinned: a schema the caller may not read, and a
 * schema lookup that throws, are refused.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Notifications\Service\GraphQL
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Notifications\Service\GraphQL;

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Notifications\Service\GraphQL\EventStreamFilter;
use OCA\OpenRegister\Notifications\Service\GraphQL\SubscriptionService;
use OCA\OpenRegister\Service\Object\PermissionCheckInterface;
use OCA\OpenRegister\Tests\Unit\Service\Object\PermissionAskArgs;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;

/**
 * Pins which buffered events a subscriber receives.
 */
final class SubscriptionServiceEventFilterTest extends TestCase {

	/**
	 * A buffer of four events over two schemas and two registers.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function buffer(): array {
		return [
			['id' => 'e1', 'object' => ['schema' => 1, 'register' => 10]],
			['id' => 'e2', 'object' => ['schema' => 2, 'register' => 10]],
			['id' => 'e3', 'object' => ['schema' => 1, 'register' => 20]],
			['id' => 'e4', 'object' => []],
		];
	}//end buffer()

	/**
	 * The service with a schema mapper and permission check as given.
	 *
	 * @param PermissionCheckInterface|null $permissions The permission check.
	 * @param SchemaMapperInterface|null    $schemas     The schema mapper.
	 *
	 * @return SubscriptionService
	 */
	private function service(?PermissionCheckInterface $permissions = null, ?SchemaMapperInterface $schemas = null): SubscriptionService {
		return new SubscriptionService(
			$schemas ?? $this->createMock(SchemaMapperInterface::class),
			$permissions ?? $this->createMock(PermissionCheckInterface::class)
		);
	}//end service()

	/**
	 * The ids of the events the filter keeps.
	 *
	 * @param string|null $last     The last event id the client received.
	 * @param int|null    $schema   The schema filter.
	 * @param int|null    $register The register filter.
	 *
	 * @return array<int, string>
	 */
	private function kept(?string $last, ?int $schema, ?int $register): array {
		$method = new ReflectionMethod(SubscriptionService::class, 'filterEventStream');
		$events = $method->invoke($this->service(), $this->buffer(), new EventStreamFilter($last, $schema, $register));
		return array_column($events, 'id');
	}//end kept()

	/**
	 * Whether the per-event RBAC check lets an event through.
	 *
	 * @param SubscriptionService  $service The service.
	 * @param array<string, mixed> $event   The event.
	 *
	 * @return bool
	 */
	private function visible(SubscriptionService $service, array $event): bool {
		$method = new ReflectionMethod(SubscriptionService::class, 'verifyEventRBAC');
		return $method->invoke($service, $event);
	}//end visible()

	public function testWithoutALastIdEveryEventIsKept(): void {
		$this->assertSame(['e1', 'e2', 'e3', 'e4'], $this->kept(null, null, null));
	}

	public function testOnlyEventsAfterTheLastIdAreKept(): void {
		$this->assertSame(['e3', 'e4'], $this->kept('e2', null, null));
	}

	public function testAnUnknownLastIdKeepsNothing(): void {
		$this->assertSame([], $this->kept('gone', null, null));
	}

	public function testTheSchemaFilterNarrows(): void {
		$this->assertSame(['e1', 'e3'], $this->kept(null, 1, null));
	}

	public function testTheRegisterFilterNarrows(): void {
		$this->assertSame(['e1', 'e2'], $this->kept(null, null, 10));
	}

	public function testBothFiltersAndTheLastIdCombine(): void {
		$this->assertSame(['e3'], $this->kept('e1', 1, 20));
	}

	public function testAnEventWithoutASchemaIsVisible(): void {
		$this->assertTrue($this->visible($this->service(), ['id' => 'e4', 'object' => []]));
	}

	public function testAReadableSchemaIsVisible(): void {
		$schema      = new Schema();
		$schemas     = $this->createMock(SchemaMapperInterface::class);
		$permissions = $this->createMock(PermissionCheckInterface::class);
		$schemas->method('find')->with(1)->willReturn($schema);
		$permissions->method('hasPermission')->with(...PermissionAskArgs::with($schema, 'read'))->willReturn(true);

		$this->assertTrue($this->visible($this->service($permissions, $schemas), ['object' => ['schema' => 1]]));
	}

	public function testAnUnreadableSchemaIsRefused(): void {
		$schemas     = $this->createMock(SchemaMapperInterface::class);
		$permissions = $this->createMock(PermissionCheckInterface::class);
		$schemas->method('find')->willReturn(new Schema());
		$permissions->method('hasPermission')->willReturn(false);

		$this->assertFalse($this->visible($this->service($permissions, $schemas), ['object' => ['schema' => 1]]));
	}

	public function testASchemaLookupThatThrowsIsRefused(): void {
		$schemas = $this->createMock(SchemaMapperInterface::class);
		$schemas->method('find')->willThrowException(new RuntimeException('gone'));

		$this->assertFalse($this->visible($this->service(null, $schemas), ['object' => ['schema' => 1]]));
	}

	public function testWithoutApcuNoEventIsReturned(): void {
		if (function_exists('apcu_enabled') === true && apcu_enabled() === true) {
			$this->markTestSkipped('APCu is enabled here; the buffer would be read.');
		}

		$this->assertSame([], $this->service()->eventsMatching(new EventStreamFilter('e1', 1, 10)));
	}
}//end class
