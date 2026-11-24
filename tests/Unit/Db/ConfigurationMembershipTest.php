<?php

/**
 * Unit tests for Configuration::listsMember() and the entities that read it.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
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

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\Application;
use OCA\OpenRegister\Db\Configuration;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\Source;
use OCA\OpenRegister\Kernel\Contracts\ConfigurationMembershipInterface;
use OCP\AppFramework\Db\Entity;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A configuration manages an entity when the member list of the entity's kind holds its id.
 *
 * @spec exclude shared entity plumbing, not a capability of its own
 */
final class ConfigurationMembershipTest extends TestCase {

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function kinds(): array {
		return [
			'objects' => ['objects', 'setObjects'],
			'schemas' => ['schemas', 'setSchemas'],
			'registers' => ['registers', 'setRegisters'],
			'views' => ['views', 'setViews'],
			'agents' => ['agents', 'setAgents'],
			'sources' => ['sources', 'setSources'],
			'applications' => ['applications', 'setApplications'],
		];
	}

	#[DataProvider('kinds')]
	public function testEachKindReadsItsOwnList(string $kind, string $setter): void {
		$configuration = new Configuration();
		$configuration->$setter([4, 7]);

		self::assertTrue($configuration->listsMember(kind: $kind, id: 7));
		self::assertFalse($configuration->listsMember(kind: $kind, id: 5));
	}

	public function testTheComparisonIsStrict(): void {
		$configuration = new Configuration();
		$configuration->setRegisters([7]);

		self::assertFalse($configuration->listsMember(kind: 'registers', id: '7'));
	}

	public function testAnEmptyOrUnknownListHoldsNothing(): void {
		$configuration = new Configuration();
		$configuration->setRegisters(null);

		self::assertFalse($configuration->listsMember(kind: 'registers', id: 1));
		self::assertFalse($configuration->listsMember(kind: 'unknown', id: 1));
	}

	/**
	 * @return array<string, array{0: class-string<Entity>, 1: string}>
	 */
	public static function entities(): array {
		return [
			'object' => [ObjectEntity::class, 'objects'],
			'schema' => [Schema::class, 'schemas'],
			'register' => [Register::class, 'registers'],
			'source' => [Source::class, 'sources'],
			'application' => [Application::class, 'applications'],
		];
	}

	#[DataProvider('entities')]
	public function testAnEntityAsksForItsOwnKindAndReturnsTheManagingConfiguration(string $class, string $kind): void {
		$entity = new $class();
		$entity->setId(7);
		$other = $this->membership(kind: $kind, ids: [3]);
		$managing = $this->membership(kind: $kind, ids: [7]);

		self::assertSame($managing, $entity->getManagedByConfiguration([$other, $managing]));
		self::assertTrue($entity->isManagedByConfiguration([$other, $managing]));
		self::assertNull($entity->getManagedByConfiguration([$other]));
		self::assertFalse($entity->isManagedByConfiguration([]));
	}

	#[DataProvider('entities')]
	public function testAnEntityWithoutAnIdIsNeverManaged(string $class, string $kind): void {
		$entity = new $class();

		self::assertNull($entity->getManagedByConfiguration([$this->membership(kind: $kind, ids: [7])]));
	}

	public function testARealConfigurationStillManagesARegister(): void {
		$register = new Register();
		$register->setId(9);
		$configuration = new Configuration();
		$configuration->setRegisters([9]);

		self::assertSame($configuration, $register->getManagedByConfiguration([$configuration]));
	}

	/**
	 * A membership that lists the given ids under one kind only.
	 *
	 * @param string          $kind The kind.
	 * @param array<int, int> $ids  The ids.
	 *
	 * @return ConfigurationMembershipInterface
	 */
	private function membership(string $kind, array $ids): ConfigurationMembershipInterface {
		return new class ($kind, $ids) implements ConfigurationMembershipInterface {
			/**
			 * @param string          $kind The kind.
			 * @param array<int, int> $ids  The ids.
			 */
			public function __construct(private readonly string $kind, private readonly array $ids) {
			}

			public function listsMember(string $kind, int|string $id): bool {
				return $kind === $this->kind && in_array($id, $this->ids, true);
			}
		};
	}
}
