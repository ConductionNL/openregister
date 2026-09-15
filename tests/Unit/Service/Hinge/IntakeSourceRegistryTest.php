<?php

/**
 * Unit tests for IntakeSourceRegistry.
 *
 * Covers there being more than one source, a disabled source staying listed
 * while dropping out of what is polled, and a fresh instance with no register
 * answering "nobody declared one" rather than throwing.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Hinge
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/objects-as-the-hinge-between-cases/specs/linked-entity-types/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\Hinge;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Hinge\IntakeSourceRegistry;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class IntakeSourceRegistryTest extends TestCase {

	private function source(string $slug, string $kind, ?bool $enabled): ObjectEntity {
		$data = ['slug' => $slug, 'title' => $slug, 'kind' => $kind];
		if ($enabled !== null) {
			$data['enabled'] = $enabled;
		}

		$object = new ObjectEntity();
		$object->setUuid('uuid-' . $slug);
		$object->setObject($data);
		return $object;
	}

	/**
	 * Build the registry over an object service double.
	 *
	 * @param array|null $objects The objects the register holds, or null to make the read fail.
	 * @param array      $capture Receives the filters the read was asked for.
	 *
	 * @return IntakeSourceRegistry The registry under test.
	 */
	private function registry(?array $objects, array &$capture): IntakeSourceRegistry {
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('setRegister')->willReturnSelf();
		$objectService->method('setSchema')->willReturnSelf();

		if ($objects === null) {
			$objectService->method('findAll')->willThrowException(new \RuntimeException('register not found'));
		} else {
			$objectService->method('findAll')->willReturnCallback(
				static function (array $config) use ($objects, &$capture): array {
					$capture[] = ($config['filters'] ?? []);
					return $objects;
				}
			);
		}

		return new IntakeSourceRegistry(objectService: $objectService, logger: new NullLogger());
	}

	public function testASecondMailboxIsConfigurationNotCode(): void {
		$capture = [];
		$registry = $this->registry(
			[
				$this->source('postbus-1', 'mailbox', true),
				$this->source('postbus-2', 'mailbox', true),
			],
			$capture
		);

		$listed = $registry->all();
		$polled = $registry->enabled();

		$this->assertSame(['postbus-1', 'postbus-2'], array_column($listed, 'slug'));
		$this->assertSame(['postbus-1', 'postbus-2'], array_column($polled, 'slug'));
	}

	public function testASourceIsSwitchedOffWithoutBeingDeleted(): void {
		$capture = [];
		$registry = $this->registry(
			[
				$this->source('postbus-1', 'mailbox', true),
				$this->source('postbus-2', 'mailbox', false),
			],
			$capture
		);

		$this->assertSame(['postbus-1', 'postbus-2'], array_column($registry->all(), 'slug'));
		$this->assertSame(['postbus-1'], array_column($registry->enabled(), 'slug'));
	}

	public function testASourceSavedBeforeTheSwitchExistedIsStillPolled(): void {
		$capture = [];
		$registry = $this->registry([$this->source('map-1', 'watchedFolder', null)], $capture);

		$this->assertSame(['map-1'], array_column($registry->enabled(), 'slug'));
	}

	public function testOneKindCanBeAskedForOnItsOwn(): void {
		$capture = [];
		$registry = $this->registry([$this->source('map-1', 'watchedFolder', true)], $capture);

		$registry->enabled(kind: 'watchedFolder');

		$this->assertSame([['kind' => 'watchedFolder']], $capture);
	}

	public function testAnInstanceWithNoRegisterHasNoSourcesRatherThanAnError(): void {
		$capture = [];
		$registry = $this->registry(null, $capture);

		$this->assertSame([], $registry->all());
		$this->assertSame([], $registry->enabled());
	}
}
