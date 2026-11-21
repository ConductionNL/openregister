<?php

/**
 * Route key to URL template mapping.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Observability
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Observability;

use OCA\OpenRegister\Service\Observability\RouteTemplates;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Observability\RouteTemplates
 */
final class RouteTemplatesTest extends TestCase {

	/**
	 * Routes map by controller.action plus postfix, resources by lower-cased name and action.
	 *
	 * @return void
	 */
	public function testMapsRoutesAndResources(): void {
		$map = RouteTemplates::mapOf(
			routes: [
				'resources' => ['Registers' => ['url' => 'api/registers']],
				'routes' => [
					['name' => 'objects#index', 'url' => '/api/objects/{register}/{schema}', 'verb' => 'GET'],
					['name' => 'objects#index', 'url' => '/api/v1/objects/{register}/{schema}', 'verb' => 'GET', 'postfix' => 'v1'],
					['name' => 'objects#index', 'url' => '/api/other', 'verb' => 'GET'],
					['name' => 'broken', 'url' => '/api/broken'],
				],
			]
		);

		$this->assertSame(
			[
				'objects.index' => '/api/objects/{register}/{schema}',
				'objects.indexv1' => '/api/v1/objects/{register}/{schema}',
				'registers.index' => '/api/registers',
				'registers.show' => '/api/registers/{id}',
				'registers.create' => '/api/registers',
				'registers.update' => '/api/registers/{id}',
				'registers.destroy' => '/api/registers/{id}',
			],
			$map
		);
	}//end testMapsRoutesAndResources()

	/**
	 * A known key gives its template; an unknown key is kept as it is.
	 *
	 * @return void
	 */
	public function testEndpointOfFallsBackToTheKey(): void {
		$templates = RouteTemplates::forRoutes(routes: ['routes' => [['name' => 'health#index', 'url' => '/api/health']]]);

		$this->assertSame('/api/health', $templates->endpointOf(route: 'health.index'));
		$this->assertSame('nothing.here', $templates->endpointOf(route: 'nothing.here'));
	}//end testEndpointOfFallsBackToTheKey()

	/**
	 * Without an injected table the app's own routes.php is read.
	 *
	 * @return void
	 */
	public function testReadsTheAppRouteTable(): void {
		$templates = new RouteTemplates();

		$this->assertSame('/api/registers', $templates->endpointOf(route: 'registers.index'));
		$this->assertSame('/api/setup/status', $templates->endpointOf(route: 'setup.status'));
	}//end testReadsTheAppRouteTable()
}//end class
