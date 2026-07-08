<?php

/**
 * DoriathCredentialStoreTest — D-C custody + D-A lazy migration behaviour.
 *
 * Pins: put() stores/rotates application-owned ciphertext named by the
 * credential UUID (envelope-encrypted against OR's public PEM via the Doriath
 * scheme seam); get() round-trips via the system-scoped private key and fails
 * CLOSED on ambiguity or missing key material; delete() is idempotent, clears
 * Doriath rows AND the residual vault row; lazy migration moves a legacy vault
 * secret exactly once and only in a session context (sessionless reads of
 * un-migrated secrets fail closed). All secrets are placeholders.
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
 * @spec openspec/changes/credential-doriath-leaf/specs/credential-broker/spec.md#doriath-backed-secret-custody
 * @spec openspec/changes/credential-doriath-leaf/specs/credential-broker/spec.md#lazy-migration-of-vault-secrets-to-doriath
 */

declare(strict_types=1);

namespace Unit\Service\Credential;

use OCA\OpenRegister\Service\Credential\DoriathCredentialStore;
use OCA\OpenRegister\Service\Credential\NextcloudVaultCredentialStore;
use OCA\OpenRegister\Tests\Fixtures\Doriath\FakeDecryptService;
use OCA\OpenRegister\Tests\Fixtures\Doriath\FakeEncryptService;
use OCA\OpenRegister\Tests\Fixtures\Doriath\FakeSecretRow;
use OCA\OpenRegister\Tests\Fixtures\Doriath\FakeSecretService;
use OCP\IAppConfig;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Security\ICredentialsManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class DoriathCredentialStoreTest extends TestCase
{
    private const UUID = '00000000-0000-0000-0000-000000000000';

    private const APPLICATION_ID = 'a1b2c3d4-0000-0000-0000-000000000000';

    private const SECRET = 'YOUR_API_KEY_HERE';

    private const PRIVATE_PEM = '<private-key-pem-placeholder>';

    private const PUBLIC_PEM = '<public-key-pem-placeholder>';

    private FakeSecretService $secretService;

    private FakeEncryptService $encryptService;

    private FakeDecryptService $decryptService;

    private NextcloudVaultCredentialStore $vaultStore;

    private ICredentialsManager $credentialsManager;

    protected function setUp(): void
    {
        $this->secretService  = new FakeSecretService();
        $this->encryptService = new FakeEncryptService();
        $this->decryptService = new FakeDecryptService();

        $this->vaultStore         = $this->createMock(NextcloudVaultCredentialStore::class);
        $this->credentialsManager = $this->createMock(ICredentialsManager::class);
        $this->credentialsManager->method('retrieve')
            ->with('', DoriathCredentialStore::PRIVATE_KEY_ID)
            ->willReturn(self::PRIVATE_PEM);
    }

    /**
     * Happy path: put() creates an application-owned ciphertext row named by the UUID.
     */
    public function testPutCreatesCiphertextRowNamedByCredentialUuid(): void
    {
        $store = $this->makeStore(session: true);

        $store->put(self::UUID, self::SECRET);

        $this->assertCount(1, $this->secretService->createCalls);
        $this->assertSame([], $this->secretService->updateCalls);
        $call = $this->secretService->createCalls[0];
        $this->assertSame(self::APPLICATION_ID, $call['applicationId']);
        $this->assertSame(self::UUID, $call['data']['name'], 'Secret name = credential UUID');
        $this->assertSame('fake-cipher:'.base64_encode(self::SECRET), $call['data']['key'], 'Ciphertext only');
        $this->assertArrayNotHasKey('folderId', $call['data'], 'Root folder (no folderId)');
        $this->assertSame([self::PUBLIC_PEM], $this->encryptService->publicKeysSeen, 'Encrypted against OR public PEM');
    }

    /**
     * Happy path: a repeated put() rotates the existing row instead of duplicating.
     */
    public function testPutRotatesExistingRow(): void
    {
        $this->secretService->rows[] = new FakeSecretRow('row-0', self::UUID, 'fake-cipher:old');
        $store = $this->makeStore(session: true);

        $store->put(self::UUID, self::SECRET);

        $this->assertSame([], $this->secretService->createCalls);
        $this->assertCount(1, $this->secretService->updateCalls);
        $call = $this->secretService->updateCalls[0];
        $this->assertSame('row-0', $call['id']);
        $this->assertSame('fake-cipher:'.base64_encode(self::SECRET), $call['data']['key']);
    }

    /**
     * Contract pin: DORIATH owns the ambiguity policy — `getByNameForApplication`
     * reports an ambiguous name (>1 own-vault matches) as null (absence, logged
     * Doriath-side, never guessed), so put() never rotates a guessed row: it
     * takes the create path exactly as for a true miss.
     */
    public function testPutTreatsDoriathAmbiguityAsAbsence(): void
    {
        $this->secretService->rows[] = new FakeSecretRow('row-0', self::UUID, 'fake-cipher:a');
        $this->secretService->rows[] = new FakeSecretRow('row-1', self::UUID, 'fake-cipher:b');
        $store = $this->makeStore(session: true);

        $store->put(self::UUID, self::SECRET);

        $this->assertSame([], $this->secretService->updateCalls, 'Never rotates a guessed row');
        $this->assertCount(1, $this->secretService->createCalls);
    }

    /**
     * Happy path: get() round-trips the secret via the system-scoped private key.
     */
    public function testGetRoundTripsSecret(): void
    {
        $this->secretService->rows[] = new FakeSecretRow(
            'row-0',
            self::UUID,
            'fake-cipher:'.base64_encode(self::SECRET)
        );
        $store = $this->makeStore(session: true);

        $this->assertSame(self::SECRET, $store->get(self::UUID));
        $this->assertSame([self::PRIVATE_PEM], $this->decryptService->privateKeysSeen);
    }

    /**
     * Error path: an ambiguous name (>1 own-vault matches) fails closed on
     * get() — Doriath resolves it to null (indistinguishable from absence,
     * warning logged Doriath-side), the vault fallback misses, and no
     * migration write occurs.
     */
    public function testGetFailsClosedOnAmbiguousRows(): void
    {
        $this->secretService->rows[] = new FakeSecretRow('row-0', self::UUID, 'fake-cipher:a');
        $this->secretService->rows[] = new FakeSecretRow('row-1', self::UUID, 'fake-cipher:b');
        $store = $this->makeStore(session: true);

        $this->assertNull($store->get(self::UUID));
        $this->assertSame([], $this->secretService->createCalls, 'No migration write on an ambiguous name');
    }

    /**
     * Error path: missing private key material fails closed on get().
     */
    public function testGetFailsClosedWithoutPrivateKey(): void
    {
        $credentialsManager = $this->createMock(ICredentialsManager::class);
        $credentialsManager->method('retrieve')->willReturn(null);
        $this->secretService->rows[] = new FakeSecretRow(
            'row-0',
            self::UUID,
            'fake-cipher:'.base64_encode(self::SECRET)
        );

        $store = $this->makeStore(session: true, credentialsManager: $credentialsManager);

        $this->assertNull($store->get(self::UUID));
    }

    /**
     * Happy path: delete() removes the Doriath row AND clears the residual vault row.
     */
    public function testDeleteRemovesDoriathRowAndResidualVaultRow(): void
    {
        $this->secretService->rows[] = new FakeSecretRow('row-0', self::UUID, 'fake-cipher:a');
        $this->vaultStore->expects($this->once())->method('delete')->with(self::UUID, 'personal');
        $store = $this->makeStore(session: true);

        $store->delete(self::UUID);

        $this->assertSame(
            [['secretId' => 'row-0', 'applicationId' => self::APPLICATION_ID]],
            $this->secretService->deleteCalls
        );
    }

    /**
     * Edge: deleting an absent secret is a no-op (idempotent), vault still cleared.
     */
    public function testDeleteAbsentSecretIsIdempotentNoOp(): void
    {
        $this->vaultStore->expects($this->once())->method('delete')->with(self::UUID, 'personal');
        $store = $this->makeStore(session: true);

        $store->delete(self::UUID);

        $this->assertSame([], $this->secretService->deleteCalls);
    }

    /**
     * Edge (D-A): a session read that misses in Doriath but hits in the vault
     * migrates the secret exactly once — re-put into Doriath, vault row deleted,
     * secret returned; the follow-up read is served from Doriath alone.
     */
    public function testLazyMigrationMovesVaultSecretExactlyOnce(): void
    {
        $this->vaultStore->expects($this->once())->method('get')
            ->with(self::UUID, 'personal')
            ->willReturn(self::SECRET);
        $this->vaultStore->expects($this->once())->method('delete')->with(self::UUID, 'personal');
        $store = $this->makeStore(session: true);

        // First read: miss in Doriath → vault hit → migrate → return.
        $this->assertSame(self::SECRET, $store->get(self::UUID));
        $this->assertCount(1, $this->secretService->createCalls, 'Migrated into Doriath');

        // Second read: served from Doriath alone (vault get/delete mocked once).
        $this->assertSame(self::SECRET, $store->get(self::UUID));
        $this->assertCount(1, $this->secretService->createCalls, 'No second migration');
    }

    /**
     * Edge (D-A): a SESSIONLESS read of an un-migrated secret fails closed —
     * the session-scoped vault is never consulted and nothing is migrated.
     */
    public function testSessionlessReadOfUnmigratedSecretFailsClosed(): void
    {
        $this->vaultStore->expects($this->never())->method('get');
        $store = $this->makeStore(session: false);

        $this->assertNull($store->get(self::UUID));
        $this->assertSame([], $this->secretService->createCalls, 'No partial migration');
    }

    /**
     * Build a store whose Doriath service resolution returns the fixtures.
     *
     * @param bool                     $session            Whether a user session exists.
     * @param ICredentialsManager|null $credentialsManager Override for the key vault mock.
     */
    private function makeStore(bool $session, ?ICredentialsManager $credentialsManager=null): DoriathCredentialStore
    {
        $appConfig = $this->createMock(IAppConfig::class);
        $appConfig->method('getValueString')->willReturnCallback(
            static function (string $app, string $key, string $default=''): string {
                if ($key === DoriathCredentialStore::APP_CONFIG_APPLICATION_ID) {
                    return self::APPLICATION_ID;
                }

                if ($key === DoriathCredentialStore::APP_CONFIG_PUBLIC_KEY_PEM) {
                    return self::PUBLIC_PEM;
                }

                return $default;
            }
        );

        $userSession = $this->createMock(IUserSession::class);
        $user        = null;
        if ($session === true) {
            $user = $this->createMock(IUser::class);
            $user->method('getUID')->willReturn('alice');
        }

        $userSession->method('getUser')->willReturn($user);

        $serviceMap = [
            'OCA\\Doriath\\Service\\SecretService'  => $this->secretService,
            'OCA\\Doriath\\Service\\EncryptService' => $this->encryptService,
            'OCA\\Doriath\\Service\\DecryptService' => $this->decryptService,
        ];

        return new class (
            $this->vaultStore,
            ($credentialsManager ?? $this->credentialsManager),
            $appConfig,
            $userSession,
            $this->createMock(LoggerInterface::class),
            $serviceMap
        ) extends DoriathCredentialStore {
            /**
             * @param array<string, object> $serviceMap FQCN → fixture instance.
             */
            public function __construct(
                NextcloudVaultCredentialStore $vaultStore,
                ICredentialsManager $credentialsManager,
                IAppConfig $appConfig,
                IUserSession $userSession,
                LoggerInterface $logger,
                private readonly array $serviceMap,
            ) {
                parent::__construct($vaultStore, $credentialsManager, $appConfig, $userSession, $logger);
            }

            protected function resolveDoriathService(string $className): ?object
            {
                return ($this->serviceMap[$className] ?? null);
            }
        };
    }
}
