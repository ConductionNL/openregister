<?php

/**
 * StandardProtocolPathsTest: which API paths speak a protocol defined elsewhere.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Middleware\Support
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/specs/openapi-generation/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Middleware\Support;

use OCA\OpenRegister\Middleware\Support\StandardProtocolPaths;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Middleware\Support\StandardProtocolPaths
 */
class StandardProtocolPathsTest extends TestCase {

	/**
	 * Paths and whether they speak a standard protocol.
	 *
	 * @return array<string, array{0: string, 1: bool}>
	 */
	public static function paths(): array {
		return [
			'MCP endpoint'            => ['/api/mcp', true],
			'MCP discovery'           => ['/api/mcp/v1/discover', true],
			'GraphQL'                 => ['/api/graphql', true],
			'GraphQL subscriptions'   => ['/api/graphql/subscribe', true],
			'WFS'                     => ['/api/geo/zaken/zaak/wfs', true],
			'WFS route template'      => ['/api/geo/{register}/{schema}/wfs', true],
			'iCalendar feed'          => ['/api/public/calendar-feeds/abc.ics', true],
			'metrics'                 => ['/api/metrics', true],
			'health'                  => ['/api/health', true],
			'federation serving'      => ['/api/federation/token/objects', true],
			'full request path'       => ['/apps/openregister/api/graphql', true],
			'objects'                 => ['/api/objects/1/2', false],
			'GeoJSON'                 => ['/api/geo/zaken/zaak/geojson', false],
			'MCP-like prefix'         => ['/api/mcpish', false],
			'full request path, own'  => ['/apps/openregister/api/registers', false],
			'metrics sub path'        => ['/api/metrics/extra', false],
		];
	}//end paths()

	/**
	 * Each path is classified as expected.
	 *
	 * @param string $path     The path.
	 * @param bool   $expected Whether it speaks a standard protocol.
	 *
	 * @return void
	 */
	#[DataProvider('paths')]
	public function testClassifiesThePath(string $path, bool $expected): void {
		$this->assertSame(expected: $expected, actual: StandardProtocolPaths::matches(path: $path));
	}//end testClassifiesThePath()
}//end class
