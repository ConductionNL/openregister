<?php

/**
 * ManagedByConfigurationTrait: which configuration, if any, lists an entity as its own.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/unit-test-coverage/tasks.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Db\Configuration;
use OCA\OpenRegister\Db\ManagedByConfigurationTrait;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Db\ManagedByConfigurationTrait
 * @uses   \OCA\OpenRegister\Db\Configuration
 */
class ManagedByConfigurationTraitTest extends TestCase {

	/**
	 * An entity with the given id whose configuration members are a configuration's applications.
	 *
	 * @param int|null $id The entity id.
	 *
	 * @return object
	 */
	private function entity(?int $id): object {
		return new class($id) {
			use ManagedByConfigurationTrait;

			public function __construct(public ?int $id) {
			}

			protected function configurationMembers(Configuration $configuration): ?array {
				return $configuration->getApplications();
			}
		};
	}

	/**
	 * A configuration listing the given application ids.
	 *
	 * @param array|null $applications The application ids.
	 *
	 * @return Configuration
	 */
	private function configuration(?array $applications): Configuration {
		$configuration = new Configuration();
		$configuration->setApplications($applications);
		return $configuration;
	}

	public function testTheFirstConfigurationListingTheIdIsReturned(): void {
		$none = $this->configuration(null);
		$other = $this->configuration([1, 2]);
		$first = $this->configuration([4, 5]);
		$second = $this->configuration([5]);

		$entity = $this->entity(5);
		$this->assertSame($first, $entity->getManagedByConfiguration([$none, $other, $first, $second]));
		$this->assertTrue($entity->isManagedByConfiguration([$none, $other, $first, $second]));
	}

	public function testTheIdMustMatchStrictly(): void {
		$entity = $this->entity(5);
		$this->assertNull($entity->getManagedByConfiguration([$this->configuration(['5'])]));
		$this->assertFalse($entity->isManagedByConfiguration([$this->configuration(['5']), $this->configuration(null)]));
		$this->assertFalse($entity->isManagedByConfiguration([]));
	}

	public function testAnUnsavedEntityIsNeverManaged(): void {
		$entity = $this->entity(null);
		$this->assertNull($entity->getManagedByConfiguration([$this->configuration([null, 0])]));
		$this->assertFalse($entity->isManagedByConfiguration([$this->configuration([null])]));
	}
}
