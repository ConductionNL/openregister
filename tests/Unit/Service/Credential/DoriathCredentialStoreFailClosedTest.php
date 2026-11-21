<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Credential
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Credential;

use OCA\OpenRegister\Service\Credential\DoriathCredentialStore;
use OCA\OpenRegister\Service\Credential\DoriathKeyMaterial;
use OCA\OpenRegister\Service\Credential\NextcloudVaultCredentialStore;
use OCA\OpenRegister\Tests\Fixtures\Doriath\FakeDecryptService;
use OCA\OpenRegister\Tests\Fixtures\Doriath\FakeEncryptService;
use OCA\OpenRegister\Tests\Fixtures\Doriath\FakeSecretRow;
use OCA\OpenRegister\Tests\Fixtures\Doriath\FakeSecretService;
use OCP\IAppConfig;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Security\ICredentialsManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The Doriath-backed store refuses to write when it is not provisioned and
 * answers null, never a guess, when a read cannot be completed.
 *
 * @covers \OCA\OpenRegister\Service\Credential\DoriathCredentialStore
 * @uses   \OCA\OpenRegister\Service\Credential\DoriathKeyMaterial
 */
class DoriathCredentialStoreFailClosedTest extends TestCase {

	private const UUID = '00000000-0000-0000-0000-000000000001';

	private const APPLICATION_ID = 'app-0001';

	private FakeSecretService $secrets;

	/** @var NextcloudVaultCredentialStore&MockObject */
	private NextcloudVaultCredentialStore $vault;

	/** @var LoggerInterface&MockObject */
	private LoggerInterface $logger;

	protected function setUp(): void {
		$this->secrets = new FakeSecretService();
		$this->vault   = $this->createMock(NextcloudVaultCredentialStore::class);
		$this->logger  = $this->createMock(LoggerInterface::class);
	}

	/**
	 * @param array<string, string> $config   App config values by key.
	 * @param array<string, object> $services Relative class to fixture.
	 */
	private function store(array $config, array $services, bool $session = true, ?object $secretService = null): DoriathCredentialStore {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => ($config[$key] ?? $default)
		);

		$credentials = $this->createMock(ICredentialsManager::class);
		$credentials->method('retrieve')->willReturn('<private-pem>');

		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($session === true ? $this->createMock(IUser::class) : null);

		return new class($this->vault, new DoriathKeyMaterial($credentials, $appConfig), $userSession, $this->logger, $services) extends DoriathCredentialStore {
			/**
			 * @param array<string, object> $services Relative class to fixture.
			 */
			public function __construct(
				NextcloudVaultCredentialStore $vaultStore,
				DoriathKeyMaterial $keyMaterial,
				IUserSession $userSession,
				LoggerInterface $logger,
				private readonly array $services,
			) {
				parent::__construct($vaultStore, $keyMaterial, $userSession, $logger);
			}

			protected function resolveDoriathService(string $relativeClass): ?object {
				return ($this->services[$relativeClass] ?? null);
			}
		};
	}

	/** @return array<string, string> */
	private static function provisioned(): array {
		return [
			DoriathCredentialStore::APP_CONFIG_APPLICATION_ID => self::APPLICATION_ID,
			DoriathCredentialStore::APP_CONFIG_PUBLIC_KEY_PEM => '<public-pem>',
		];
	}

	/** @return array<string, object> */
	private function services(): array {
		return [
			'Service\\SecretService'  => $this->secrets,
			'Service\\EncryptService' => new FakeEncryptService(),
			'Service\\DecryptService' => new FakeDecryptService(),
		];
	}

	public function testAWriteWithoutAnApplicationIsRefused(): void {
		$store = $this->store(config: [DoriathCredentialStore::APP_CONFIG_PUBLIC_KEY_PEM => '<public-pem>'], services: $this->services());

		try {
			$store->put(self::UUID, 'secret');
			$this->fail('An unprovisioned store must refuse the write');
		} catch (RuntimeException $e) {
			$this->assertSame('Credential store is not provisioned', $e->getMessage());
		}

		$this->assertSame([], $this->secrets->createCalls);
	}

	public function testAWriteWithoutAPublicKeyIsRefused(): void {
		$store = $this->store(config: [DoriathCredentialStore::APP_CONFIG_APPLICATION_ID => self::APPLICATION_ID], services: $this->services());

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Credential store is not provisioned');

		$store->put(self::UUID, 'secret');
	}

	public function testAWriteWithoutTheBackendIsRefused(): void {
		$store = $this->store(config: self::provisioned(), services: []);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Credential store backend unavailable');

		$store->put(self::UUID, 'secret');
	}

	public function testAReadWithoutAnApplicationIsNullAndLooksNothingUp(): void {
		$this->vault->expects($this->never())->method('get');

		$this->assertNull($this->store(config: [], services: $this->services())->get(self::UUID));
		$this->assertSame(0, $this->secrets->lookupCalls);
	}

	public function testAFailedLookupIsNullAndNeverMigrates(): void {
		$this->vault->expects($this->never())->method('get');
		$this->logger->expects($this->once())->method('warning')
			->with('[DoriathCredentialStore] secret lookup failed', ['credential' => self::UUID, 'error' => 'Credential store backend unavailable']);

		$this->assertNull($this->store(config: self::provisioned(), services: [])->get(self::UUID));
	}

	public function testAnUndecryptableRowIsNull(): void {
		$this->secrets->rows[] = new FakeSecretRow('row-0', self::UUID, 'foreign-cipher');
		$this->logger->expects($this->once())->method('warning')
			->with('[DoriathCredentialStore] decryption failed — failing closed', ['credential' => self::UUID, 'error' => 'Fixture cannot decrypt foreign ciphertext']);

		$this->assertNull($this->store(config: self::provisioned(), services: $this->services())->get(self::UUID));
	}

	public function testAMissingDecryptServiceIsNull(): void {
		$this->secrets->rows[] = new FakeSecretRow('row-0', self::UUID, 'fake-cipher:'.base64_encode('s'));
		$services = $this->services();
		unset($services['Service\\DecryptService']);

		$this->assertNull($this->store(config: self::provisioned(), services: $services)->get(self::UUID));
	}

	public function testAnUnmigratedSecretWithoutVaultRowIsNull(): void {
		$this->vault->expects($this->once())->method('get')->with(self::UUID, 'personal')->willReturn(null);
		$this->vault->expects($this->never())->method('delete');

		$this->assertNull($this->store(config: self::provisioned(), services: $this->services())->get(self::UUID));
		$this->assertSame([], $this->secrets->createCalls);
	}

	public function testAFailedMigrationKeepsTheVaultRowAndStillAnswers(): void {
		$services = $this->services();
		unset($services['Service\\EncryptService']);
		$this->vault->method('get')->willReturn('legacy-secret');
		$this->vault->expects($this->never())->method('delete');
		$this->logger->expects($this->once())->method('warning')
			->with('[DoriathCredentialStore] lazy migration failed — vault row kept', ['credential' => self::UUID, 'error' => 'Credential store backend unavailable']);

		$this->assertSame('legacy-secret', $this->store(config: self::provisioned(), services: $services)->get(self::UUID));
		$this->assertSame([], $this->secrets->createCalls);
	}

	public function testDeletingWithoutAnApplicationOnlyClearsTheVault(): void {
		$this->vault->expects($this->once())->method('delete')->with(self::UUID, 'shared');

		$this->store(config: [], services: $this->services())->delete(self::UUID, 'shared');

		$this->assertSame(0, $this->secrets->lookupCalls);
		$this->assertSame([], $this->secrets->deleteCalls);
	}

	public function testDeletingWithoutTheBackendIsRefusedAfterTheVaultIsCleared(): void {
		$this->vault->expects($this->once())->method('delete')->with(self::UUID, 'personal');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Credential store backend unavailable');

		$this->store(config: self::provisioned(), services: [])->delete(self::UUID);
	}
}
