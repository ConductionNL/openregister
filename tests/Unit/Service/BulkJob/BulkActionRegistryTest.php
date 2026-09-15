<?php

/**
 * Unit tests for BulkActionRegistry — the catalogue of bulk actions.
 *
 * Covers the registration event being dispatched once and only once, the id
 * format a leaf app has to honour, the refusal of a second action on the
 * same id, and the catalogue a consumer reads.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\BulkJob
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/bulk-action-jobs/specs/bulk-action-jobs/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\BulkJob;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use InvalidArgumentException;
use OCA\OpenRegister\BulkAction\BulkActionInterface;
use OCA\OpenRegister\BulkAction\BulkActionResult;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\BulkActionRegistrationEvent;
use OCA\OpenRegister\Service\BulkActionRegistry;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IUser;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class BulkActionRegistryTest extends TestCase {

	private function action(string $id, bool $requiresJustification = false, array $guards = []): BulkActionInterface {
		return new class($id, $requiresJustification, $guards) implements BulkActionInterface {
			public function __construct(
				private readonly string $id,
				private readonly bool $requiresJustification,
				private readonly array $guards,
			) {
			}

			public function getId(): string {
				return $this->id;
			}

			public function getLabel(): string {
				return 'A test action';
			}

			public function getDescription(): string {
				return 'Does nothing at all.';
			}

			public function requiresJustification(): bool {
				return $this->requiresJustification;
			}

			public function getGuards(): array {
				return $this->guards;
			}

			public function validateParameters(array $parameters): void {
			}

			public function apply(ObjectEntity $object, array $parameters, bool $commit, ?IUser $actor = null): BulkActionResult {
				return BulkActionResult::applied();
			}
		};
	}

	private function registry(callable $onDispatch): BulkActionRegistry {
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$registry = null;

		$dispatcher->method('dispatchTyped')->willReturnCallback(
			function (Event $event) use ($onDispatch): void {
				if ($event instanceof BulkActionRegistrationEvent) {
					$onDispatch($event);
				}
			}
		);

		$registry = new BulkActionRegistry($dispatcher, new NullLogger());

		return $registry;
	}

	public function testTheCatalogueCarriesWhatEachActionDeclares(): void {
		$registry = $this->registry(
			function (BulkActionRegistrationEvent $event): void {
				$event->registerAction($this->action('openregister:assign', true, []));
				$event->registerAction($this->action('openregister:set-properties', false, [BulkActionInterface::GUARD_HOMOGENEITY]));
			}
		);

		$catalogue = $registry->describe();

		$this->assertCount(2, $catalogue);
		$this->assertSame('openregister:assign', $catalogue[0]['id']);
		$this->assertTrue($catalogue[0]['requiresJustification']);
		$this->assertSame([], $catalogue[0]['guards']);
		$this->assertFalse($catalogue[1]['requiresJustification']);
		$this->assertSame([BulkActionInterface::GUARD_HOMOGENEITY], $catalogue[1]['guards']);
	}

	public function testTheRegistrationEventIsDispatchedOnce(): void {
		$dispatches = 0;
		$registry = $this->registry(
			function (BulkActionRegistrationEvent $event) use (&$dispatches): void {
				$dispatches++;
				$event->registerAction($this->action('openregister:assign'));
			}
		);

		$registry->all();
		$registry->all();
		$registry->has('openregister:assign');

		$this->assertSame(1, $dispatches);
	}

	public function testAnUnknownActionIsRefusedByName(): void {
		$registry = $this->registry(static function (): void {
		});

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Unknown bulk action: someapp:nothing');

		$registry->get('someapp:nothing');
	}

	public function testAMalformedIdIsRefused(): void {
		$registry = $this->registry(static function (): void {
		});

		$this->expectException(InvalidArgumentException::class);

		$registry->register($this->action('NotAnAppId'));
	}

	public function testTheSameIdCannotBeRegisteredTwice(): void {
		$registry = $this->registry(static function (): void {
		});

		$registry->register($this->action('openregister:assign'));

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Bulk action already registered: openregister:assign');

		$registry->register($this->action('openregister:assign'));
	}
}
