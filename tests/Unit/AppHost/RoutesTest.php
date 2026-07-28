<?php
/**
 * AppHost Routes::standard() canonical-table + merge tests.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\AppHost
 *
 * @author    Conduction Development Team <info@conduction.nl>
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
 * Covers the canonical route set, name parity, extra-merge and catch-all order.
 */
class RoutesTest extends TestCase
{
    /**
     * Extract route names from a Routes::standard() result.
     *
     * @param array<string, mixed> $result Routes::standard() output.
     *
     * @return array<int, string>
     */
    private function names(array $result): array
    {
        return array_map(static fn ($r) => $r['name'], $result['routes']);
    }//end names()

    public function testCanonicalRouteNamesMatchPetstoreReference(): void
    {
        $names = $this->names(Routes::standard());

        // Bit-compatible with the petstore reference skeleton, plus the
        // canonical ADR-066 `settings#update` write verb (PUT /api/settings).
        $this->assertSame(
            [
                'dashboard#page',
                'settings#index',
                'settings#create',
                'settings#update',
                'settings#load',
                'preferences#getPreference',
                'preferences#setPreference',
                'metrics#index',
                'health#index',
                'dashboard#catchAll',
            ],
            $names
        );
    }//end testCanonicalRouteNamesMatchPetstoreReference()

    public function testSettingsUpdateRouteIsPutOnApiSettings(): void
    {
        $routes = Routes::standard()['routes'];
        $update = array_values(array_filter($routes, static fn ($r) => $r['name'] === 'settings#update'))[0];

        $this->assertSame('/api/settings', $update['url']);
        $this->assertSame('PUT', $update['verb']);
    }//end testSettingsUpdateRouteIsPutOnApiSettings()

    public function testCatchAllIsLastAndHasPathRequirement(): void
    {
        $routes = Routes::standard()['routes'];
        $last   = end($routes);

        $this->assertSame('dashboard#catchAll', $last['name']);
        $this->assertSame('/{path}', $last['url']);
        $this->assertSame('.+', $last['requirements']['path']);
    }//end testCatchAllIsLastAndHasPathRequirement()

    public function testIndexRouteIsGetSlash(): void
    {
        $routes = Routes::standard()['routes'];
        $this->assertSame('/', $routes[0]['url']);
        $this->assertSame('GET', $routes[0]['verb']);
        $this->assertSame('dashboard#page', $routes[0]['name']);
    }//end testIndexRouteIsGetSlash()

    public function testExtraRoutesAreInsertedBeforeCatchAll(): void
    {
        $result = Routes::standard([
            ['name' => 'pets#index', 'url' => '/api/pets', 'verb' => 'GET'],
        ]);
        $names = $this->names($result);

        $petsIdx     = array_search('pets#index', $names, true);
        $catchAllIdx = array_search('dashboard#catchAll', $names, true);

        $this->assertNotFalse($petsIdx);
        $this->assertLessThan($catchAllIdx, $petsIdx, 'extra routes must precede the SPA catch-all');
    }//end testExtraRoutesAreInsertedBeforeCatchAll()

    public function testExtraRouteOverridesCanonicalByName(): void
    {
        $result = Routes::standard([
            ['name' => 'health#index', 'url' => '/api/health', 'verb' => 'GET', 'requirements' => ['custom' => '1']],
        ]);

        $healthRoutes = array_values(array_filter($result['routes'], static fn ($r) => $r['name'] === 'health#index'));
        // Exactly one health#index — the override, not the canonical one.
        $this->assertCount(1, $healthRoutes);
        $this->assertSame(['custom' => '1'], $healthRoutes[0]['requirements']);
    }//end testExtraRouteOverridesCanonicalByName()

    public function testDuplicateNameWithinExtraThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Routes::standard([
            ['name' => 'pets#index', 'url' => '/api/pets', 'verb' => 'GET'],
            ['name' => 'pets#index', 'url' => '/api/pets/all', 'verb' => 'GET'],
        ]);
    }//end testDuplicateNameWithinExtraThrows()
}//end class
