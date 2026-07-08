<?php

/**
 * DoriathApplicationRegistrarTest — per-app, identity-only Doriath registration.
 *
 * Pins: a first onboarding registers the consuming app as its OWN Doriath
 * `Application` (name = appId, description from manifest, type `internal`, NO
 * CSR, `isAdmin: false` → pending) and persists ONLY the Doriath-assigned UUID
 * under a per-app `IAppConfig` key distinct from OpenRegister's own custody-vault
 * key; a subsequent run with a live row is a no-op (never re-register, never
 * rotate); a stale UUID re-registers once; Doriath absent/disabled/unloadable
 * degrades (never throws, no writes) so existing secrets and OR's own Doriath
 * application are untouched.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Credential
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/per-app-doriath-application/specs/credential-broker/spec.md#per-app-doriath-application-registration
 */

declare(strict_types=1);

namespace Unit\Service\Credential;

use OCA\OpenRegister\Service\Credential\DoriathApplicationRegistrar;
use OCA\OpenRegister\Service\Credential\DoriathCredentialStore;
use OCA\OpenRegister\Tests\Fixtures\Doriath\FakeApplicationService;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class DoriathApplicationRegistrarTest extends TestCase
{
    private const APP_ID = 'openbuild-spectr';

    private FakeApplicationService $applicationService;

    protected function setUp(): void
    {
        $this->applicationService = new FakeApplicationService();
    }

    /**
     * Happy path (first onboarding): register a pending, identity-only, per-app
     * Doriath application (name = appId, csr null, isAdmin false) and persist the
     * assigned UUID under the per-app key.
     */
    public function testRegistersPendingIdentityOnlyApplicationOnFirstOnboarding(): void
    {
        $persisted = [];
        $appConfig = $this->createMock(IAppConfig::class);
        $appConfig->method('getValueString')->willReturn('');
        $appConfig->method('setValueString')->willReturnCallback(
            static function (string $app, string $key, string $value) use (&$persisted): bool {
                $persisted[$app.'|'.$key] = $value;
                return true;
            }
        );

        $registrar = $this->makeRegistrar(doriathEnabled: true, appConfig: $appConfig);

        $registrar->registerApplication(self::APP_ID, 'Spectr tender intelligence');

        // Exactly one register call with the identity-only shape.
        $this->assertCount(1, $this->applicationService->registerCalls);
        [$name, $description, $type, $csr, $userId, $isAdmin] = $this->applicationService->registerCalls[0];
        $this->assertSame(self::APP_ID, $name, 'Application name = consuming appId');
        $this->assertSame('Spectr tender intelligence', $description, 'Description from the manifest');
        $this->assertSame('internal', $type);
        $this->assertNull($csr, 'Identity-only: NO CSR, so no EncryptionSuite');
        $this->assertNull($userId, 'Runs without a session');
        $this->assertFalse($isAdmin, 'Non-admin path → pending row (admin approves)');

        // The Doriath-assigned UUID is persisted under the per-app key ONLY.
        $expectedKey = 'openregister|'.DoriathApplicationRegistrar::appConfigKey(self::APP_ID);
        $this->assertArrayHasKey($expectedKey, $persisted);
        $this->assertSame($this->applicationService->assignedId, $persisted[$expectedKey]);

        // Custody stays put: OR's OWN application-id key is never written here.
        $ownKey = 'openregister|'.DoriathCredentialStore::APP_CONFIG_APPLICATION_ID;
        $this->assertArrayNotHasKey($ownKey, $persisted, "OR's own Doriath application UUID untouched (identity-only)");
    }

    /**
     * Per-app key is namespaced by appId and distinct from OR's custody-vault key.
     */
    public function testPerAppKeyIsDistinctFromOrOwnKey(): void
    {
        $this->assertNotSame(
            DoriathCredentialStore::APP_CONFIG_APPLICATION_ID,
            DoriathApplicationRegistrar::appConfigKey(self::APP_ID),
            'Per-app key must not collide with OR own application-id key'
        );
        $this->assertStringContainsString(self::APP_ID, DoriathApplicationRegistrar::appConfigKey(self::APP_ID));
    }

    /**
     * Idempotency: a persisted UUID whose Doriath row is still live is a no-op —
     * no new registration, no rotation, no re-persist.
     */
    public function testIdempotentWhenLiveRow(): void
    {
        $existingId = 'a1b2c3d4-0000-0000-0000-000000000000';
        $this->applicationService->liveApplicationIds = [$existingId];

        $appConfig = $this->createMock(IAppConfig::class);
        $appConfig->method('getValueString')->willReturn($existingId);
        $appConfig->expects($this->never())->method('setValueString');

        $registrar = $this->makeRegistrar(doriathEnabled: true, appConfig: $appConfig);

        $registrar->registerApplication(self::APP_ID, 'Spectr');

        $this->assertSame([], $this->applicationService->registerCalls, 'Live row → never re-register/rotate');
    }

    /**
     * A stale persisted UUID (row removed in Doriath) re-registers exactly once.
     */
    public function testStaleRowReRegisters(): void
    {
        $appConfig = $this->createMock(IAppConfig::class);
        $appConfig->method('getValueString')->willReturn('dead-beef-0000-0000-0000-000000000000');
        $appConfig->expects($this->once())->method('setValueString');

        $registrar = $this->makeRegistrar(doriathEnabled: true, appConfig: $appConfig);

        $registrar->registerApplication(self::APP_ID, null);

        $this->assertCount(1, $this->applicationService->registerCalls, 'Stale UUID re-registers');
    }

    /**
     * Description falls back to the appId when the manifest supplies none.
     */
    public function testDescriptionFallsBackToAppId(): void
    {
        $appConfig = $this->createMock(IAppConfig::class);
        $appConfig->method('getValueString')->willReturn('');

        $registrar = $this->makeRegistrar(doriathEnabled: true, appConfig: $appConfig);

        $registrar->registerApplication(self::APP_ID, null);

        [, $description] = $this->applicationService->registerCalls[0];
        $this->assertSame(self::APP_ID, $description, 'Missing description falls back to appId');
    }

    /**
     * Degrade: Doriath disabled → warn/skip, no register call, no writes, no throw.
     */
    public function testDegradesWhenDoriathDisabled(): void
    {
        $appConfig = $this->createMock(IAppConfig::class);
        $appConfig->expects($this->never())->method('setValueString');

        $registrar = $this->makeRegistrar(doriathEnabled: false, appConfig: $appConfig);

        $registrar->registerApplication(self::APP_ID, 'Spectr');

        $this->assertSame([], $this->applicationService->registerCalls, 'Doriath disabled → no registration');
    }

    /**
     * Degrade: Doriath enabled but its ApplicationService is unloadable → no-op, no throw.
     */
    public function testDegradesWhenServiceUnavailable(): void
    {
        $appConfig = $this->createMock(IAppConfig::class);
        $appConfig->expects($this->never())->method('setValueString');

        $appManager = $this->createMock(IAppManager::class);
        $appManager->method('isEnabledForUser')->with('doriath')->willReturn(true);

        $registrar = new class ($appConfig, $appManager, $this->createMock(LoggerInterface::class)) extends DoriathApplicationRegistrar {
            protected function resolveApplicationService(): ?object
            {
                return null;
            }
        };

        $registrar->registerApplication(self::APP_ID, 'Spectr');

        // No throw is the assertion; getValueString/setValueString never touched.
        $this->addToAssertionCount(1);
    }

    /**
     * Build the registrar with the fixture ApplicationService injected.
     *
     * @param bool       $doriathEnabled Whether IAppManager reports doriath enabled.
     * @param IAppConfig $appConfig      IAppConfig mock.
     */
    private function makeRegistrar(bool $doriathEnabled, IAppConfig $appConfig): DoriathApplicationRegistrar
    {
        $appManager = $this->createMock(IAppManager::class);
        $appManager->method('isEnabledForUser')->with('doriath')->willReturn($doriathEnabled);

        $applicationService = $this->applicationService;

        return new class ($appConfig, $appManager, $this->createMock(LoggerInterface::class), $applicationService) extends DoriathApplicationRegistrar {
            public function __construct(
                IAppConfig $appConfig,
                IAppManager $appManager,
                LoggerInterface $logger,
                private readonly object $fixtureApplicationService,
            ) {
                parent::__construct($appConfig, $appManager, $logger);
            }

            protected function resolveApplicationService(): ?object
            {
                return $this->fixtureApplicationService;
            }
        };
    }
}
