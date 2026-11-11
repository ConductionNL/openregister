<?php

/**
 * Routes::standard() with $extra entries that carry no name.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\AppHost
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\AppHost;

use InvalidArgumentException;
use OCA\OpenRegister\AppHost\Routes;
use PHPUnit\Framework\TestCase;

/**
 * The duplicate-name guard skips a nameless $extra entry but still refuses a named pair.
 */
class RoutesBehaviourTest extends TestCase {

	public function testANamelessExtraRouteIsKeptBeforeTheCatchAll(): void {
		$nameless = ['url' => '/api/ping', 'verb' => 'GET'];
		$routes = Routes::standard([$nameless, ['name' => 'pets#index', 'url' => '/api/pets', 'verb' => 'GET']])['routes'];

		$this->assertSame($nameless, $routes[count($routes) - 3]);
		$this->assertSame('dashboard#catchAll', $routes[count($routes) - 1]['name']);
	}//end testANamelessExtraRouteIsKeptBeforeTheCatchAll()

	public function testANamelessEntryDoesNotHideANamedDuplicate(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Duplicate route name "pets#index"');

		Routes::standard(
			[
				['name' => 'pets#index', 'url' => '/api/pets', 'verb' => 'GET'],
				['url' => '/api/ping', 'verb' => 'GET'],
				['name' => 'pets#index', 'url' => '/api/pets', 'verb' => 'POST'],
			]
		);
	}//end testANamelessEntryDoesNotHideANamedDuplicate()

	public function testTwoNamelessExtraRoutesAreRefusedAsRegisteringTwiceNotAsDuplicateNames(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Route "" registers twice in AppHost Routes::standard(): POST /api/pong replaces GET /api/ping.');

		Routes::standard([['url' => '/api/ping', 'verb' => 'GET'], ['url' => '/api/pong', 'verb' => 'POST']]);
	}//end testTwoNamelessExtraRoutesAreRefusedAsRegisteringTwiceNotAsDuplicateNames()
}//end class
