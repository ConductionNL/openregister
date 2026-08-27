<?php

/**
 * Unit tests for FleetAppId — resolving fleet app ids across the rename.
 *
 * The class exists because a cross-app id is a duck-typed runtime lookup:
 * asking for the wrong name returns "not installed" instead of failing, so
 * the integration goes quiet rather than red. These tests therefore assert
 * BOTH directions — an instance that has only the new id, and one that has
 * only the old id — because a resolver that only handled one would look
 * perfectly correct against whichever fixture you happened to write.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Support
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace Unit\Support;

use OCA\OpenRegister\Support\FleetAppId;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Tests for the fleet app id resolver.
 */
class FleetAppIdTest extends TestCase
{


    /**
     * Build an app manager that reports only the given ids as installed.
     *
     * @param list<string> $installed Ids this fake instance has.
     * @param list<string> $enabled   Ids enabled for the user (defaults to installed).
     *
     * @return IAppManager The configured mock.
     */
    private function appManager(array $installed, ?array $enabled = null): IAppManager
    {
        $enabled = ($enabled ?? $installed);

        $mock = $this->createMock(IAppManager::class);
        $mock->method('isInstalled')->willReturnCallback(
            static fn (string $id): bool => in_array($id, $installed, true)
        );
        $mock->method('isEnabledForUser')->willReturnCallback(
            static fn (string $id): bool => in_array($id, $enabled, true)
        );

        return $mock;

    }//end appManager()


    /**
     * An instance carrying the NEW id resolves to it.
     *
     * @return void
     */
    public function testResolvesTheNewIdWhenPresent(): void
    {
        $manager = $this->appManager(['integriq']);

        $this->assertSame('integriq', FleetAppId::resolve($manager, 'integriq'));
        $this->assertTrue(FleetAppId::isInstalled($manager, 'integriq'));

    }//end testResolvesTheNewIdWhenPresent()


    /**
     * An instance still on the OLD id resolves to that — this is the case a
     * hard swap to the new name would silently break.
     *
     * @return void
     */
    public function testFallsBackToTheLegacyId(): void
    {
        $manager = $this->appManager(['openconnector']);

        $this->assertSame('openconnector', FleetAppId::resolve($manager, 'integriq'));
        $this->assertTrue(FleetAppId::isInstalled($manager, 'integriq'));

    }//end testFallsBackToTheLegacyId()


    /**
     * When BOTH ids somehow answer, the newest wins — order is the contract.
     *
     * @return void
     */
    public function testPrefersTheNewestIdWhenBothResolve(): void
    {
        $manager = $this->appManager(['openconnector', 'integriq']);

        $this->assertSame('integriq', FleetAppId::resolve($manager, 'integriq'));

    }//end testPrefersTheNewestIdWhenBothResolve()


    /**
     * An absent app resolves to null rather than to a plausible-looking id.
     *
     * @return void
     */
    public function testReturnsNullWhenTheAppIsAbsent(): void
    {
        $manager = $this->appManager(['openregister']);

        $this->assertNull(FleetAppId::resolve($manager, 'integriq'));
        $this->assertFalse(FleetAppId::isInstalled($manager, 'integriq'));

    }//end testReturnsNullWhenTheAppIsAbsent()


    /**
     * Every renamed app in the map resolves from either of its two names.
     *
     * Guards the map itself: dropping a legacy entry is exactly the mistake
     * that reintroduces the silent breakage, and it would not show up in a
     * test that only covered integriq.
     *
     * @return void
     */
    public function testEveryRenamedAppResolvesFromBothNames(): void
    {
        $pairs = [
            'integriq' => 'openconnector',
            'filinq'   => 'docudesk',
            'thematiq' => 'nldesign',
            'stackiq'  => 'softwarecatalog',
            'larpinq'  => 'larpingapp',
            'dossiq'   => 'procest',
            'learniq'  => 'scholiq',
            'decidiq'  => 'decidesk',
            'buildiq'  => 'openbuild',
            'keepiq'   => 'doriath',
        ];

        foreach ($pairs as $new => $old) {
            $this->assertSame(
                $new,
                FleetAppId::resolve($this->appManager([$new]), $new),
                "expected {$new} to resolve to itself"
            );
            $this->assertSame(
                $old,
                FleetAppId::resolve($this->appManager([$old]), $new),
                "expected {$new} to fall back to {$old}"
            );
        }

    }//end testEveryRenamedAppResolvesFromBothNames()


    /**
     * An unknown app name resolves against itself, so callers outside the
     * rename map keep working unchanged.
     *
     * @return void
     */
    public function testUnknownAppFallsBackToItsOwnName(): void
    {
        $manager = $this->appManager(['openregister']);

        $this->assertSame('openregister', FleetAppId::resolve($manager, 'openregister'));

    }//end testUnknownAppFallsBackToItsOwnName()


    /**
     * isEnabledForUser checks the RESOLVED id, not the canonical one.
     *
     * Installed under the legacy id and enabled under it must report enabled;
     * checking the canonical name directly would report false.
     *
     * @return void
     */
    public function testEnabledForUserUsesTheResolvedId(): void
    {
        $manager = $this->appManager(['openconnector'], ['openconnector']);

        $this->assertTrue(FleetAppId::isEnabledForUser($manager, 'integriq'));

    }//end testEnabledForUserUsesTheResolvedId()


    /**
     * Installed but not enabled reports false.
     *
     * @return void
     */
    public function testEnabledForUserIsFalseWhenInstalledButDisabled(): void
    {
        $manager = $this->appManager(['integriq'], []);

        $this->assertFalse(FleetAppId::isEnabledForUser($manager, 'integriq'));

    }//end testEnabledForUserIsFalseWhenInstalledButDisabled()


    /**
     * A throwing app manager does not abort the search.
     *
     * A lookup that raises for one candidate must not prevent the next from
     * resolving — otherwise one bad id takes the whole integration down.
     *
     * @return void
     */
    public function testAThrowingCandidateDoesNotAbortTheSearch(): void
    {
        $mock = $this->createMock(IAppManager::class);
        $mock->method('isInstalled')->willReturnCallback(
            static function (string $id): bool {
                if ($id === 'integriq') {
                    throw new RuntimeException('app manager blew up');
                }

                return ($id === 'openconnector');
            }
        );

        $this->assertSame('openconnector', FleetAppId::resolve($mock, 'integriq'));

    }//end testAThrowingCandidateDoesNotAbortTheSearch()


    /**
     * appPath builds the path from the id the instance actually registered.
     *
     * @return void
     */
    public function testAppPathUsesTheResolvedId(): void
    {
        $this->assertSame(
            '/apps/openconnector/api/sources',
            FleetAppId::appPath($this->appManager(['openconnector']), 'integriq', 'api/sources')
        );

        $this->assertSame(
            '/apps/integriq/api/sources',
            FleetAppId::appPath($this->appManager(['integriq']), 'integriq', 'api/sources')
        );

    }//end testAppPathUsesTheResolvedId()


    /**
     * appPath returns null when the app is absent, so callers cannot
     * accidentally build a URL that is guaranteed to 404.
     *
     * @return void
     */
    public function testAppPathReturnsNullWhenAbsent(): void
    {
        $this->assertNull(
            FleetAppId::appPath($this->appManager(['openregister']), 'integriq', 'api/sources')
        );

    }//end testAppPathReturnsNullWhenAbsent()


    /**
     * appPath with no suffix yields the bare app root.
     *
     * @return void
     */
    public function testAppPathWithoutSuffix(): void
    {
        $this->assertSame(
            '/apps/integriq',
            FleetAppId::appPath($this->appManager(['integriq']), 'integriq')
        );

    }//end testAppPathWithoutSuffix()


}//end class
