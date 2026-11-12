<?php

/**
 * Golden test pinning how a View finds and serialises its managing configuration.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Search\Db
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Search\Db;

use OCA\OpenRegister\Db\Configuration;
use OCA\OpenRegister\Search\Db\View;
use PHPUnit\Framework\TestCase;

/**
 * Pins the configuration match (strict id), its serialised form and the presentation default.
 *
 * @covers \OCA\OpenRegister\Search\Db\View
 */
final class ViewManagedByGoldenTest extends TestCase {

	/**
	 * A configuration listing the given view ids.
	 *
	 * @param array<int|string> $views The view ids.
	 * @param int               $id    The configuration id.
	 *
	 * @return Configuration
	 */
	private function configuration(array $views, int $id): Configuration {
		$configuration = new Configuration();
		$configuration->setId($id);
		$configuration->setUuid('cfg-' . $id);
		$configuration->setTitle('Config ' . $id);
		$configuration->setViews($views);
		return $configuration;
	}//end configuration()

	/**
	 * The first configuration listing the view's id, compared strictly.
	 *
	 * @return void
	 */
	public function testFindsTheFirstConfigurationListingTheId(): void {
		$view = new View();
		$this->assertNull($view->getManagedByConfiguration([$this->configuration([1], 9)]));

		$view->setId(7);
		$first  = $this->configuration([3, 7], 1);
		$second = $this->configuration([7], 2);
		$this->assertSame($first, $view->getManagedByConfiguration([$this->configuration(['7'], 5), $first, $second]));
		$this->assertNull($view->getManagedByConfiguration([$this->configuration(['7'], 5)]));
		$this->assertNull($view->getManagedByConfiguration([]));
	}//end testFindsTheFirstConfigurationListingTheId()

	/**
	 * The serialised managing configuration and presentation.
	 *
	 * @return void
	 */
	public function testSerialisedManagedByAndPresentation(): void {
		$view = new View();
		$this->assertNull($view->jsonSerialize()['managedByConfiguration']);
		$this->assertSame(['viewType' => 'table'], $view->jsonSerialize()['presentation']);

		$view->setManagedByConfigurationEntity($this->configuration([], 4));
		$view->setPresentation(['viewType' => '', 'x' => 1]);
		$json = $view->jsonSerialize();
		$this->assertSame(['id' => 4, 'uuid' => 'cfg-4', 'title' => 'Config 4'], $json['managedByConfiguration']);
		$this->assertSame(['viewType' => 'table', 'x' => 1], $json['presentation']);

		$view->setPresentation(['viewType' => 'kanban']);
		$this->assertSame(['viewType' => 'kanban'], $view->jsonSerialize()['presentation']);

		$view->setManagedByConfigurationEntity(null);
		$this->assertNull($view->jsonSerialize()['managedByConfiguration']);
	}//end testSerialisedManagedByAndPresentation()
}//end class
