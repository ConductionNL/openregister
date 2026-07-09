<?php

/**
 * RegisterOpenRegisterWithDoriathTest — D-B self-registration repair behaviour.
 *
 * Pins: degrade (warn, never throw) when Doriath is unavailable; skip-fast
 * idempotency when the persisted application UUID still matches a live Doriath
 * row; stale-UUID re-registration; and the full first-run sequence — RSA-4096
 * keypair, SYSTEM-scoped private key, valid PKCS#10 CSR (CN `openregister`),
 * in-process admin registration, and persistence of ONLY public material in
 * IAppConfig.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Repair
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
 * @spec openspec/changes/credential-doriath-leaf/specs/credential-broker/spec.md#openregister-self-registration-as-a-doriath-application
 */

declare(strict_types=1);

namespace Unit\Repair;

use OCA\OpenRegister\Repair\RegisterOpenRegisterWithDoriath;
use OCA\OpenRegister\Service\Credential\DoriathCredentialStore;
use OCA\OpenRegister\Tests\Fixtures\Doriath\FakeApplicationService;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use OCP\Security\ICredentialsManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class RegisterOpenRegisterWithDoriathTest extends TestCase
{
    private FakeApplicationService $applicationService;

    protected function setUp(): void
    {
        $this->applicationService = new FakeApplicationService();
    }

    /**
     * Error path: Doriath absent/disabled → warn and complete, no writes, no throw.
     */
    public function testDegradesWhenDoriathUnavailable(): void
    {
        $appConfig = $this->createMock(IAppConfig::class);
        $appConfig->expects($this->never())->method('setValueString');

        $credentialsManager = $this->createMock(ICredentialsManager::class);
        $credentialsManager->expects($this->never())->method('store');

        $output = $this->createMock(IOutput::class);
        $output->expects($this->once())->method('warning');

        $step = $this->makeStep(
            doriathEnabled: false,
            appConfig: $appConfig,
            credentialsManager: $credentialsManager
        );

        $step->run($output);

        $this->assertSame([], $this->applicationService->registerCalls);
    }

    /**
     * Happy path (idempotency): persisted UUID + live Doriath row → no-op re-run.
     */
    public function testSkipsWhenAlreadyRegisteredWithLiveRow(): void
    {
        $existingId = 'a1b2c3d4-0000-0000-0000-000000000000';
        $this->applicationService->liveApplicationIds = [$existingId];

        $appConfig = $this->createMock(IAppConfig::class);
        $appConfig->method('getValueString')->willReturn($existingId);
        $appConfig->expects($this->never())->method('setValueString');

        $credentialsManager = $this->createMock(ICredentialsManager::class);
        $credentialsManager->expects($this->never())->method('store');

        $output = $this->createMock(IOutput::class);
        $output->expects($this->once())->method('info');

        $step = $this->makeStep(
            doriathEnabled: true,
            appConfig: $appConfig,
            credentialsManager: $credentialsManager
        );

        $step->run($output);

        $this->assertSame([], $this->applicationService->registerCalls, 'No new keypair/CSR/registration');
    }

    /**
     * Happy path (first run): keypair + system-scoped private key + PKCS#10 CSR
     * + admin in-process registration + IAppConfig persistence (public only).
     */
    public function testFirstRunRegistersAndPersists(): void
    {
        $storedPrivateKey = null;
        $credentialsManager = $this->createMock(ICredentialsManager::class);
        $credentialsManager->expects($this->once())->method('store')
            ->willReturnCallback(
                function (string $userId, string $identifier, $value) use (&$storedPrivateKey): void {
                    $this->assertSame('', $userId, 'SYSTEM scope (empty user id)');
                    $this->assertSame(DoriathCredentialStore::PRIVATE_KEY_ID, $identifier);
                    $storedPrivateKey = $value;
                }
            );

        $persisted = [];
        $appConfig = $this->createMock(IAppConfig::class);
        $appConfig->method('getValueString')->willReturn('');
        $appConfig->method('setValueString')->willReturnCallback(
            static function (string $app, string $key, string $value) use (&$persisted): bool {
                $persisted[$key] = $value;
                return true;
            }
        );

        $output = $this->createMock(IOutput::class);
        $output->expects($this->never())->method('warning');

        $step = $this->makeStep(
            doriathEnabled: true,
            appConfig: $appConfig,
            credentialsManager: $credentialsManager
        );

        $step->run($output);

        // Registration call shape: name, description, type internal, CSR, no user, admin.
        $this->assertCount(1, $this->applicationService->registerCalls);
        [$name, $description, $type, $csrPem, $userId, $isAdmin] = $this->applicationService->registerCalls[0];
        $this->assertSame('openregister', $name);
        $this->assertIsString($description);
        $this->assertSame('internal', $type);
        $this->assertNull($userId, 'Repair runs without a session');
        $this->assertTrue($isAdmin, 'Admin registration auto-approves + provisions the suite');

        // CSR: valid PKCS#10, CN openregister, RSA-4096 public key.
        $this->assertIsString($csrPem);
        $this->assertStringContainsString('BEGIN CERTIFICATE REQUEST', $csrPem);
        $subject = openssl_csr_get_subject($csrPem);
        $this->assertIsArray($subject);
        $this->assertSame('openregister', $subject['CN']);
        $csrKey = openssl_csr_get_public_key($csrPem);
        $this->assertNotFalse($csrKey);
        $details = openssl_pkey_get_details($csrKey);
        $this->assertIsArray($details);
        $this->assertSame(4096, $details['bits'], 'RSA-4096 (Doriath minimum)');

        // Private key: system-scoped only, never in IAppConfig.
        $this->assertIsString($storedPrivateKey);
        $this->assertStringContainsString('PRIVATE KEY', $storedPrivateKey);

        // IAppConfig: Doriath-assigned UUID + PUBLIC key PEM only.
        $this->assertSame(
            $this->applicationService->assignedId,
            $persisted[DoriathCredentialStore::APP_CONFIG_APPLICATION_ID]
        );
        $this->assertStringContainsString('PUBLIC KEY', $persisted[DoriathCredentialStore::APP_CONFIG_PUBLIC_KEY_PEM]);
        $this->assertStringNotContainsString('PRIVATE', $persisted[DoriathCredentialStore::APP_CONFIG_PUBLIC_KEY_PEM]);
    }

    /**
     * Edge: a persisted UUID whose Doriath row is gone (stale) re-registers.
     */
    public function testStaleRegistrationReRegisters(): void
    {
        $appConfig = $this->createMock(IAppConfig::class);
        $appConfig->method('getValueString')->willReturn('dead-beef-0000-0000-0000-000000000000');

        $credentialsManager = $this->createMock(ICredentialsManager::class);
        $credentialsManager->expects($this->once())->method('store');

        $step = $this->makeStep(
            doriathEnabled: true,
            appConfig: $appConfig,
            credentialsManager: $credentialsManager
        );

        $step->run($this->createMock(IOutput::class));

        $this->assertCount(1, $this->applicationService->registerCalls, 'Stale UUID triggers re-registration');
    }

    /**
     * Build the repair step with the fixture ApplicationService injected.
     *
     * @param bool                $doriathEnabled     Whether IAppManager reports doriath enabled.
     * @param IAppConfig          $appConfig          IAppConfig mock.
     * @param ICredentialsManager $credentialsManager Credentials-manager mock.
     */
    private function makeStep(
        bool $doriathEnabled,
        IAppConfig $appConfig,
        ICredentialsManager $credentialsManager
    ): RegisterOpenRegisterWithDoriath {
        $appManager = $this->createMock(IAppManager::class);
        $appManager->method('isEnabledForUser')->with('doriath')->willReturn($doriathEnabled);

        $applicationService = $this->applicationService;

        return new class (
            $appManager,
            $appConfig,
            $credentialsManager,
            $this->createMock(LoggerInterface::class),
            $applicationService
        ) extends RegisterOpenRegisterWithDoriath {
            public function __construct(
                IAppManager $appManager,
                IAppConfig $appConfig,
                ICredentialsManager $credentialsManager,
                LoggerInterface $logger,
                private readonly object $fixtureApplicationService,
            ) {
                parent::__construct($appManager, $appConfig, $credentialsManager, $logger);
            }

            protected function resolveApplicationService(): ?object
            {
                return $this->fixtureApplicationService;
            }
        };
    }
}
