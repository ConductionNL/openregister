<?php
/**
 * Unit tests for OutsideCustody on its own: mint refusals, read refusals and the failure record.
 *
 * The object store, the custody leaf, the provider catalogue and the HTTP
 * client are doubles; the vault reader, the reference and the connection are real.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Credential
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Credential;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use InvalidArgumentException;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Kernel\Contracts\Value\BulkBatchDefaults;
use OCA\OpenRegister\Kernel\Contracts\Value\BulkSaveOptions;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCA\OpenRegister\Kernel\Contracts\Value\SaveActor;
use OCA\OpenRegister\Kernel\Contracts\Value\SavePolicy;
use OCA\OpenRegister\Service\CauseFrame;
use OCA\OpenRegister\Service\Credential\CredentialAccessDeniedException;
use OCA\OpenRegister\Service\Credential\CredentialBrokerService;
use OCA\OpenRegister\Service\Credential\CredentialSources;
use OCA\OpenRegister\Service\Credential\CredentialStore;
use OCA\OpenRegister\Service\Credential\CredentialUpstreamException;
use OCA\OpenRegister\Service\Credential\OutsideCustody;
use OCA\OpenRegister\Service\Credential\OutsideVaultConnection;
use OCA\OpenRegister\Service\Credential\OutsideVaultReader;
use OCA\OpenRegister\Service\Credential\ProviderCatalogue;
use OCA\OpenRegister\Service\Credential\VaultReference;
use OCA\OpenRegister\Service\WriteCause;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

#[CoversClass(OutsideCustody::class)]
#[UsesClass(OutsideVaultReader::class)]
#[UsesClass(OutsideVaultConnection::class)]
#[UsesClass(VaultReference::class)]
#[UsesClass(CredentialSources::class)]
#[UsesClass(ObjectEntity::class)]
#[UsesClass(BulkBatchDefaults::class)]
#[UsesClass(BulkSaveOptions::class)]
#[UsesClass(ObjectFindOptions::class)]
#[UsesClass(ObjectFindTarget::class)]
#[UsesClass(ObjectSaveOptions::class)]
#[UsesClass(SaveActor::class)]
#[UsesClass(SavePolicy::class)]
#[UsesClass(CauseFrame::class)]
#[UsesClass(WriteCause::class)]
final class OutsideCustodyTest extends TestCase {

	private const REF = ['connection' => 'conn-1', 'path' => 'apps/zs', 'key' => 'password'];

	/**
	 * Stored credentials by uuid; a Throwable makes find() throw.
	 *
	 * @var array<string, array<string, mixed>|\Throwable>
	 */
	private array $objects = [];

	/**
	 * Login secrets the custody leaf holds, by uuid.
	 *
	 * @var array<string, string>
	 */
	private array $secrets = ['conn-1' => 'hvs.login'];

	/**
	 * The custody leaf reads: uuid and scope.
	 *
	 * @var list<array{0: string, 1: string}>
	 */
	private array $leafReads = [];

	/**
	 * HTTP requests made: method, url and, for a login, its JSON body.
	 *
	 * @var list<array<int, mixed>>
	 */
	private array $http = [];

	/**
	 * Objects saved: the object and the options.
	 *
	 * @var list<array{0: array<string, mixed>, 1: ObjectSaveOptions}>
	 */
	private array $saved = [];

	/**
	 * Whether saveObject() throws.
	 *
	 * @var bool
	 */
	private bool $saveFails = false;

	/**
	 * Warnings logged.
	 *
	 * @var list<string>
	 */
	private array $warnings = [];

	/**
	 * The vault's status for a read.
	 *
	 * @var int
	 */
	private int $vaultStatus = 200;

	protected function setUp(): void {
		$this->objects = [
			'conn-1' => [
				'provider' => 'outside-vault',
				'owner' => 'alice',
				'instanceBaseUrl' => 'https://vault.example',
				'vaultAuthMethod' => 'token',
			],
			'cred-1' => $this->outside(),
		];
	}

	/**
	 * An outside credential of alice's, with overrides.
	 *
	 * @param array<string, mixed> $overrides Fields to replace.
	 *
	 * @return array<string, mixed>
	 */
	private function outside(array $overrides = []): array {
		return array_merge(['provider' => 'generic-bearer', 'owner' => 'alice', 'custody' => 'outside', 'vaultRef' => self::REF], $overrides);
	}

	private function custody(): OutsideCustody {
		$objectService = $this->createMock(ObjectOperationsInterface::class);
		$objectService->method('find')->willReturnCallback(
			function (string|int $id, ?ObjectFindOptions $options = null, bool $_rbac = true, bool $_multitenancy = true): ?ObjectEntity {
				$this->assertSame(CredentialBrokerService::REGISTER, $options?->register);
				$this->assertSame(CredentialBrokerService::SCHEMA, $options?->schema);
				$this->assertFalse($_rbac);
				$this->assertFalse($_multitenancy);
				$data = ($this->objects[(string) $id] ?? null);
				if ($data instanceof \Throwable) {
					throw $data;
				}

				if ($data === null) {
					return null;
				}

				$entity = new ObjectEntity();
				$entity->setUuid((string) $id);
				$entity->setObject($data);
				return $entity;
			}
		);
		$objectService->method('saveObject')->willReturnCallback(
			function (
				array|ObjectEntity $object,
				ObjectSaveOptions $options = new ObjectSaveOptions(),
				bool $_rbac = true,
				bool $_multitenancy = true
			): ObjectEntity {
				if ($this->saveFails === true) {
					throw new RuntimeException('database down');
				}

				$this->assertFalse($_rbac);
				$this->assertFalse($_multitenancy);
				$this->saved[] = [(array) $object, $options];
				return new ObjectEntity();
			}
		);

		$store = $this->createMock(CredentialStore::class);
		$store->method('get')->willReturnCallback(
			function (string $uuid, string $scope = 'personal'): ?string {
				$this->leafReads[] = [$uuid, $scope];
				return ($this->secrets[$uuid] ?? null);
			}
		);

		$catalogue = $this->createMock(ProviderCatalogue::class);
		$catalogue->method('get')->willReturnCallback(
			static fn (string $id): ?array => match ($id) {
				'outside-vault' => ['identifier' => 'outside-vault', 'kind' => 'outside-vault'],
				'github' => ['identifier' => 'github'],
				default => null,
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string $message, array $context = []): void {
				$this->warnings[] = $message;
			}
		);

		$client = $this->createMock(IClient::class);
		$client->method('get')->willReturnCallback(
			function (string $url, array $options = []): IResponse {
				$this->http[] = ['GET', $url];
				$response = $this->createMock(IResponse::class);
				$response->method('getStatusCode')->willReturn($this->vaultStatus);
				$response->method('getBody')->willReturn((string) json_encode(['data' => ['data' => ['password' => 'from-the-vault']]]));
				return $response;
			}
		);
		$client->method('post')->willReturnCallback(
			function (string $url, array $options = []): IResponse {
				$this->http[] = ['POST', $url, $options['json'] ?? []];
				$response = $this->createMock(IResponse::class);
				$response->method('getStatusCode')->willReturn(200);
				$response->method('getBody')->willReturn('{"auth":{"client_token":"s.approle"}}');
				return $response;
			}
		);
		$clientService = $this->createMock(IClientService::class);
		$clientService->method('newClient')->willReturn($client);

		$sources = new CredentialSources(objectService: $objectService, credentialStore: $store, catalogue: $catalogue);
		return new OutsideCustody(sources: $sources, reader: new OutsideVaultReader(clientService: $clientService, logger: $logger), logger: $logger);
	}

	public function testIsOutsideOnlyForTheOutsideCustodyValue(): void {
		$this->assertTrue(OutsideCustody::isOutside(['custody' => 'outside']));
		$this->assertFalse(OutsideCustody::isOutside(['custody' => 'Outside']));
		$this->assertFalse(OutsideCustody::isOutside(['custody' => 'nextcloud']));
		$this->assertFalse(OutsideCustody::isOutside([]));
	}

	public function testAMintWithAUsableConnectionAndNoSecretPasses(): void {
		$this->custody()->assertMintable($this->outside(), null);
		$this->custody()->assertMintable($this->outside(), '');

		$this->assertSame([], $this->http);
		$this->assertSame([], $this->leafReads);
	}

	public function testAMintThatCarriesASecretIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('A credential held in an outside vault takes no secret');

		$this->custody()->assertMintable($this->outside(), 'pasted-secret');
	}

	public function testAMintWithoutAReferenceIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('A vault reference names its connection and key');

		$this->custody()->assertMintable($this->outside(['vaultRef' => null]), null);
	}

	/**
	 * @return array<string, array{0: array<string, mixed>, 1: array<string, mixed>}>
	 */
	public static function unusableConnections(): array {
		$vault = ['provider' => 'outside-vault', 'owner' => 'alice', 'instanceBaseUrl' => 'https://vault.example'];
		return [
			'connection not stored' => [[], ['vaultRef' => ['connection' => 'missing'] + self::REF]],
			'connection of an unknown provider' => [['provider' => 'nope'] + $vault, []],
			'connection not of the outside-vault kind' => [['provider' => 'github'] + $vault, []],
			'personal credential, organisation connection' => [['scope' => 'organisation', 'organisation' => 'org-1'] + $vault, []],
			'personal credential, another owner' => [['owner' => 'bob'] + $vault, []],
			'personal credential and connection without an owner' => [['owner' => ''] + $vault, ['owner' => '  ']],
			'organisation credential, another organisation' => [
				['scope' => 'organisation', 'organisation' => 'org-2'] + $vault,
				['scope' => 'organisation', 'organisation' => 'org-1'],
			],
		];
	}

	/**
	 * @param array<string, mixed> $connection The stored connection (empty: none stored).
	 * @param array<string, mixed> $overrides  Overrides on the outside credential.
	 *
	 * @return void
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('unusableConnections')]
	public function testAMintNamingAConnectionItMayNotUseIsRefused(array $connection, array $overrides): void {
		unset($this->objects['conn-1']);
		if ($connection !== []) {
			$this->objects['conn-1'] = $connection;
		}

		try {
			$this->custody()->assertMintable($this->outside($overrides), null);
			$this->fail('Expected the mint to be refused');
		} catch (InvalidArgumentException $e) {
			$this->assertSame('The vault connection is not an outside-vault credential this credential may use', $e->getMessage());
		}
	}

	public function testAnOrganisationCredentialMayUseItsOrganisationsConnection(): void {
		$this->objects['conn-1'] += ['scope' => 'organisation', 'organisation' => 'org-1'];
		$this->objects['conn-1']['owner'] = 'carol';

		$secret = $this->custody()->secretFor($this->outside(['scope' => 'organisation', 'organisation' => ' org-1 ', 'owner' => 'dave']), 'cred-1');

		$this->assertSame('from-the-vault', $secret);
		$this->assertSame([['conn-1', 'organisation']], $this->leafReads);
	}

	public function testAnAdmittedCredentialReadsTheVaultWithTheConnectionsLogin(): void {
		$this->objects['conn-1']['vaultMount'] = 'kv';

		$secret = $this->custody()->secretFor($this->outside(), 'cred-1');

		$this->assertSame('from-the-vault', $secret);
		$this->assertSame([['GET', 'https://vault.example/v1/kv/data/apps/zs']], $this->http);
		$this->assertSame([['conn-1', 'personal']], $this->leafReads);
		$this->assertSame([], $this->saved);
	}

	public function testAnAppRoleConnectionLogsInWithItsTrimmedRoleIdAndTheStoredSecretId(): void {
		$this->objects['conn-1'] = array_merge($this->objects['conn-1'], ['vaultAuthMethod' => 'approle', 'vaultRoleId' => ' role-1 ']);

		$secret = $this->custody()->secretFor($this->outside(), 'cred-1');

		$this->assertSame('from-the-vault', $secret);
		$this->assertSame(['POST', 'https://vault.example/v1/auth/approle/login', ['role_id' => 'role-1', 'secret_id' => 'hvs.login']], $this->http[0]);
		$this->assertSame(['GET', 'https://vault.example/v1/secret/data/apps/zs'], $this->http[1]);
	}

	public function testAMalformedReferenceIsDeniedWithoutContactingTheVault(): void {
		try {
			$this->custody()->secretFor($this->outside(['vaultRef' => ['connection' => 'conn-1', 'path' => '../etc', 'key' => 'password']]), 'cred-1');
			$this->fail('Expected a denial');
		} catch (CredentialAccessDeniedException $e) {
			$this->assertSame('Request not permitted', $e->getMessage());
		}

		$this->assertSame([], $this->http);
		$this->assertSame(['[CredentialBroker] outside credential has no usable vault reference: cred-1'], $this->warnings);
	}

	public function testAConnectionTheCredentialMayNotUseIsDeniedWithoutContactingTheVault(): void {
		$this->objects['conn-1']['owner'] = 'bob';

		try {
			$this->custody()->secretFor($this->outside(), 'cred-1');
			$this->fail('Expected a denial');
		} catch (CredentialAccessDeniedException $e) {
			$this->assertSame('Request not permitted', $e->getMessage());
		}

		$this->assertSame([], $this->http);
		$this->assertSame([], $this->leafReads);
		$this->assertSame(['[CredentialBroker] outside credential names a vault connection it may not use: cred-1'], $this->warnings);
	}

	public function testAConnectionWhoseLookupThrowsIsDenied(): void {
		$this->objects['conn-1'] = new RuntimeException('store down');

		$this->expectException(CredentialAccessDeniedException::class);

		$this->custody()->secretFor($this->outside(), 'cred-1');
	}

	/**
	 * @return array<string, array{0: array<string, mixed>, 1: array<string, string>}>
	 */
	public static function brokenConnections(): array {
		return [
			'no login secret' => [[], []],
			'empty login secret' => [[], ['conn-1' => '']],
			'address that is not http' => [['instanceBaseUrl' => 'ftp://vault.example'], ['conn-1' => 'hvs.login']],
			'unknown login method' => [['vaultAuthMethod' => 'ldap'], ['conn-1' => 'hvs.login']],
			'AppRole without a role id' => [['vaultAuthMethod' => 'approle', 'vaultRoleId' => '  '], ['conn-1' => 'hvs.login']],
		];
	}

	/**
	 * @param array<string, mixed>  $connection Overrides on the stored connection.
	 * @param array<string, string> $secrets    The custody leaf's login secrets.
	 *
	 * @return void
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('brokenConnections')]
	public function testABrokenConnectionIsAnUpstreamErrorThatRecordsTheFailure(array $connection, array $secrets): void {
		$this->objects['conn-1'] = array_merge($this->objects['conn-1'], $connection);
		$this->secrets = $secrets;

		try {
			$this->custody()->secretFor($this->outside(), 'cred-1');
			$this->fail('Expected an upstream error');
		} catch (CredentialUpstreamException $e) {
			$this->assertSame('The outside vault connection conn-1 could not give the secret.', $e->getMessage());
		}

		$this->assertSame([], $this->http);
		$this->assertCount(1, $this->saved);
		$this->assertSame(OutsideCustody::READ_FAILED, $this->saved[0][0]['lastError']);
	}

	public function testAVaultRefusalLeavesTheFixedSentenceOnTheCredential(): void {
		$this->vaultStatus = 403;
		$this->objects['cred-1'] = $this->outside(['name' => 'Zaaksysteem', 'lastError' => null]);

		try {
			$this->custody()->secretFor($this->outside(), 'cred-1');
			$this->fail('Expected an upstream error');
		} catch (CredentialUpstreamException $e) {
			$this->assertSame('The outside vault connection conn-1 could not give the secret.', $e->getMessage());
		}

		$this->assertCount(1, $this->saved);
		[$object, $options] = $this->saved[0];
		$this->assertSame(OutsideCustody::READ_FAILED, $object['lastError']);
		$this->assertSame('Zaaksysteem', $object['name']);
		$this->assertArrayNotHasKey('@self', $object);
		$this->assertSame('cred-1', $options->uuid);
		$this->assertSame(CredentialBrokerService::REGISTER, $options->target?->register);
		$this->assertSame(CredentialBrokerService::SCHEMA, $options->target?->schema);
	}

	public function testAFailureOnACredentialThatIsGoneRecordsNothing(): void {
		$this->vaultStatus = 500;
		unset($this->objects['cred-1']);

		$this->expectException(CredentialUpstreamException::class);

		try {
			$this->custody()->secretFor($this->outside(), 'cred-1');
		} finally {
			$this->assertSame([], $this->saved);
		}
	}

	public function testAFailedFailureRecordIsLoggedAndTheUpstreamErrorStillRaised(): void {
		$this->vaultStatus = 500;
		$this->saveFails = true;

		try {
			$this->custody()->secretFor($this->outside(), 'cred-1');
			$this->fail('Expected an upstream error');
		} catch (CredentialUpstreamException $e) {
			$this->assertSame('The outside vault connection conn-1 could not give the secret.', $e->getMessage());
		}

		$this->assertContains('[CredentialBroker] could not record the outside vault failure on cred-1', $this->warnings);
	}
}
