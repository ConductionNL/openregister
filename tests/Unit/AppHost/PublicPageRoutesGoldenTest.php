<?php

declare(strict_types=1);

/**
 * Golden of the public-page declaration: which manifest pages are public,
 * and which paths a public route admits.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\AppHost
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

namespace OCA\OpenRegister\Tests\Unit\AppHost;

use OCA\OpenRegister\AppHost\Service\PublicPageResolver;
use PHPUnit\Framework\TestCase;

/**
 * Pins what opens without a session: allowed and refused, page by page and path by path.
 */
class PublicPageRoutesGoldenTest extends TestCase {

	public function testOnlyPublicModePagesUnderThePrefixAreDeclared(): void {
		$manifest = [
			'pages' => [
				['route' => '/public/status/:token', 'config' => ['mode' => 'public']],
				'not a page',
				['route' => 42, 'config' => ['mode' => 'public']],
				['config' => ['mode' => 'public']],
				['route' => '/public/no-config'],
				['route' => '/public/config-not-array', 'config' => 'public'],
				['route' => '/public/authenticated', 'config' => ['mode' => 'authenticated']],
				['route' => '/cases/:id', 'config' => ['mode' => 'public']],
				['route' => 'public/missing-slash', 'config' => ['mode' => 'public']],
				['route' => '/public/report', 'config' => ['mode' => 'public', 'extra' => true]],
			],
		];

		$this->assertSame(['/public/status/:token', '/public/report'], PublicPageResolver::declaredRoutes($manifest));
	}//end testOnlyPublicModePagesUnderThePrefixAreDeclared()

	public function testAManifestWithoutAPageListDeclaresNothing(): void {
		$this->assertSame([], PublicPageResolver::declaredRoutes([]));
		$this->assertSame([], PublicPageResolver::declaredRoutes(['pages' => 'all']));
	}//end testAManifestWithoutAPageListDeclaresNothing()

	/**
	 * Route, path → whether it matches.
	 *
	 * @return array<string, array{0: string, 1: string, 2: bool}>
	 */
	public static function paths(): array {
		return [
			'equal' => ['/public/report', '/public/report', true],
			'trailing slashes' => ['/public/report/', 'public/report', true],
			'param' => ['/public/status/:token', '/public/status/Ab12', true],
			'two params' => ['/public/:a/:b', '/public/x/y', true],
			'param cannot be empty' => ['/public/status/:token', '/public/status/', false],
			'empty middle segment' => ['/public/:a/end', '/public//end', false],
			'longer path' => ['/public/status/:token', '/public/status/Ab12/extra', false],
			'shorter path' => ['/public/status/:token', '/public/status', false],
			'other literal' => ['/public/report', '/public/reports', false],
			'case matters' => ['/public/report', '/Public/report', false],
			'root' => ['/', '/', false],
		];
	}//end paths()

	/**
	 * @dataProvider paths
	 *
	 * @param string $route    The manifest route.
	 * @param string $path     The requested path.
	 * @param bool   $expected Whether it matches.
	 *
	 * @return void
	 */
	public function testARouteAdmitsExactlyItsPaths(string $route, string $path, bool $expected): void {
		$this->assertSame($expected, PublicPageResolver::routeMatches($route, $path));
	}//end testARouteAdmitsExactlyItsPaths()
}//end class
