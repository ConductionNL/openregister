<?php

/**
 * Unit tests for {@see \OCA\OpenRegister\Search\Service\MapMarkerSource}.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Search\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Search\Service;

use OCA\OpenRegister\Search\Service\MapMarkerSource;
use PHPUnit\Framework\TestCase;

/**
 * A map marker source owns its register/schema scope; callers cannot override it.
 *
 * @covers \OCA\OpenRegister\Search\Service\MapMarkerSource
 */
class MapMarkerSourceTest extends TestCase {

	/**
	 * Reserved scope and paging keys are dropped and replaced by the source's own scope.
	 *
	 * @return void
	 */
	public function testObjectFiltersOwnTheScope(): void {
		$source = new MapMarkerSource(
			register: 4,
			schema: 'places',
			geoProperty: 'location',
			filters: ['register' => 99, 'schema' => 'x', 'limit' => 5, 'offset' => 1, 'page' => 2, 'type' => 'park']
		);

		$this->assertSame(['type' => 'park', 'register' => 4, 'schema' => 'places'], $source->objectFilters());
		$this->assertSame('location', $source->geoProperty);

	}//end testObjectFiltersOwnTheScope()

	/**
	 * Without filters only the scope remains.
	 *
	 * @return void
	 */
	public function testNoFilters(): void {
		$this->assertSame(['register' => 'r', 'schema' => 1], (new MapMarkerSource('r', 1))->objectFilters());

	}//end testNoFilters()
}//end class
