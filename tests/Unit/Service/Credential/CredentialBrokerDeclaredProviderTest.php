<?php

/**
 * CredentialBrokerDeclaredProviderTest — the broker on an app-declared provider:
 * an admitted declaration is guarded by the same guards as a reviewed entry, and
 * every declaration that is not admitted, or a credential shared beyond the
 * declaring app, is denied before any outbound call.
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
 * @spec openspec/changes/app-declared-credential-providers/specs/credential-broker/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\Credential;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Credential\CredentialAccessDeniedException;
use OCA\OpenRegister\Service\Credential\CredentialBrokerService;
use OCA\OpenRegister\Service\Credential\CredentialOutbound;
use OCA\OpenRegister\Service\Credential\CredentialPrincipals;
use OCA\OpenRegister\Service\Credential\CredentialSources;
use OCA\OpenRegister\Service\Credential\CredentialStore;
use OCA\OpenRegister\Service\Credential\DeclaredProviderResolver;
use OCA\OpenRegister\Service\Credential\ProviderCatalogue;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\OrganisationService;
use OCP\App\IAppManager;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenRegister\Service\Credential\CredentialBrokerService
 * @uses   \OCA\OpenRegister\Service\Credential\CredentialSources
 * @uses   \OCA\OpenRegister\Service\Credential\CredentialPrincipals
 * @uses   \OCA\OpenRegister\Service\Credential\CredentialOutbound
 * @uses   \OCA\OpenRegister\Service\Credential\CredentialAccessDeniedException
 * @uses   \OCA\OpenRegister\Service\Credential\ProviderCatalogue
 * @uses   \OCA\OpenRegister\Service\Credential\DeclaredProviderResolver
 * @uses   \OCA\OpenRegister\Service\Credential\InjectableSecretRequest
 */
class CredentialBrokerDeclaredProviderTest extends TestCase {

	private const TOKEN = 'YOUR_TOKEN_HERE';

	private const CREDENTIAL = '00000000-0000-0000-0000-0000000000d1';

	/**
	 * The admitted entry the resolver serves for `alpha:codeberg`.
	 *
	 * @var array<string, mixed>
	 */
	private const ENTRY = [
		'identifier' => 'alpha:codeberg',
		'title' => 'Codeberg',
		'baseUrl' => 'https://codeberg.org/api/v1',
		'authScheme' => ['header' => 'Authorization', 'template' => 'token {secret}'],
		'allowRules' => [['method' => 'GET', 'pathPattern' => '/repos/*']],
		'inject_only' => false,
		'origin' => 'alpha',
	];

	private MockObject&IClient $client;

	private MockObject&LoggerInterface $logger;

	private MockObject&CredentialStore $store;

	private MockObject&DeclaredProviderResolver $declared;

	protected function setUp(): void {
		$this->client = $this->createMock(IClient::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->store = $this->createMock(CredentialStore::class);
		$this->store->method('get')->willReturn(self::TOKEN);
		$this->declared = $this->createPartialMock(DeclaredProviderResolver::class, ['resolve']);
	}

	/**
	 * A broker over one credential owned by the session user.
	 *
	 * @param string             $provider    The credential's provider.
	 * @param array<int, string> $allowedApps The credential's allowed apps.
	 * @param bool               $withLayer   Whether the declaration layer is wired.
	 */
	private function broker(string $provider = 'alpha:codeberg', array $allowedApps = ['alpha'], bool $withLayer = true): CredentialBrokerService {
		$credential = new ObjectEntity();
		$credential->setUuid(self::CREDENTIAL);
		$credential->setOwner('alice');
		$credential->setObject(['name' => 'forge', 'provider' => $provider, 'owner' => 'alice', 'allowedApps' => $allowedApps]);

		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('find')->willReturn($credential);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$clients = $this->createMock(IClientService::class);
		$clients->method('newClient')->willReturn($this->client);

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getAppPath')->willReturn(dirname(__DIR__, 4));

		$declared = null;
		if ($withLayer === true) {
			$declared = $this->declared;
		}

		return new CredentialBrokerService(
			sources: new CredentialSources(
				objectService: $objectService,
				credentialStore: $this->store,
				catalogue: new ProviderCatalogue($appManager, $this->createMock(LoggerInterface::class)),
				declared: $declared
			),
			principals: new CredentialPrincipals(userSession: $session, organisationService: $this->createMock(OrganisationService::class)),
			outbound: new CredentialOutbound(clientService: $clients, logger: $this->logger)
		);
	}

	private function admitted(): void {
		$this->declared->method('resolve')->with('alpha:codeberg')
			->willReturn(['status' => 'admitted', 'origin' => 'alpha', 'lane' => 'approval', 'entry' => self::ENTRY]);
	}

	private function expectDenial(string $reason): void {
		$this->client->expects($this->never())->method('request');
		$this->logger->expects($this->once())->method('warning')
			->with('[CredentialBrokerService] request denied: ' . $reason, ['credential' => self::CREDENTIAL]);
		$this->expectException(CredentialAccessDeniedException::class);
		$this->expectExceptionMessage('Request not permitted');
	}

	public function testAnAdmittedDeclarationIsCalledHostLockedWithTheBrokerAuthHeader(): void {
		$this->admitted();
		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn(200);
		$response->method('getHeaders')->willReturn([]);
		$response->method('getBody')->willReturn('[]');
		$this->client->expects($this->once())->method('request')
			->with('GET', 'https://codeberg.org/api/v1/repos/conduction/hydra', $this->callback(
				fn (array $options): bool => $options['headers']['Authorization'] === 'token ' . self::TOKEN
			))
			->willReturn($response);

		$result = $this->broker()->request(credentialId: self::CREDENTIAL, appId: 'alpha', method: 'GET', path: '/repos/conduction/hydra');

		$this->assertSame(200, $result['status']);
	}

	public function testTheAllowRuleGuardStillAppliesToAnAdmittedDeclaration(): void {
		$this->admitted();
		$this->expectDenial('no allow-rule matches DELETE /repos/conduction/hydra');

		$this->broker()->request(credentialId: self::CREDENTIAL, appId: 'alpha', method: 'DELETE', path: '/repos/conduction/hydra');
	}

	/**
	 * @dataProvider unadmittedStatuses
	 */
	public function testADeclarationThatIsNotAdmittedDenies(string $status): void {
		$this->declared->method('resolve')->willReturn(['status' => $status, 'origin' => 'alpha', 'lane' => '', 'entry' => null]);
		$this->expectDenial('declared provider "alpha:codeberg" is not admitted (' . $status . ')');

		$this->broker()->request(credentialId: self::CREDENTIAL, appId: 'alpha', method: 'GET', path: '/repos/x');
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function unadmittedStatuses(): array {
		return [
			'pending' => ['pending'],
			'rejected' => ['rejected'],
			'revoked' => ['revoked'],
			'invalid declaration' => ['invalid'],
			'declaring app disabled' => ['unknown'],
		];
	}

	public function testASecondAppCannotBorrowTheCredentialEvenWhenAllowed(): void {
		$this->admitted();
		$this->expectDenial('declared provider "alpha:codeberg" is usable only by its declaring app');

		$this->broker(allowedApps: ['alpha', 'beta'])->request(credentialId: self::CREDENTIAL, appId: 'beta', method: 'GET', path: '/repos/x');
	}

	public function testACredentialAllowingOnlyAnotherAppDenies(): void {
		$this->admitted();
		$this->expectDenial('declared provider "alpha:codeberg" is usable only by its declaring app');

		$this->broker(allowedApps: ['beta'])->request(credentialId: self::CREDENTIAL, appId: 'beta', method: 'GET', path: '/repos/x');
	}

	public function testTheAllowedAppGuardRunsFirst(): void {
		$this->declared->expects($this->never())->method('resolve');
		$this->expectDenial('app "beta" not in allowedApps');

		$this->broker()->request(credentialId: self::CREDENTIAL, appId: 'beta', method: 'GET', path: '/repos/x');
	}

	public function testResolveInjectableNeverHandsOutADeclaredSecret(): void {
		$this->admitted();
		$this->store->expects($this->never())->method('get');

		$this->assertNull($this->broker()->resolveInjectable(credentialId: self::CREDENTIAL, appId: 'alpha'));
	}

	public function testWithoutTheDeclarationLayerADeclaredIdentifierIsUnknown(): void {
		$this->expectDenial('unknown provider "alpha:codeberg"');

		$this->broker(withLayer: false)->request(credentialId: self::CREDENTIAL, appId: 'alpha', method: 'GET', path: '/repos/x');
	}

	public function testAnUnnamespacedUnknownProviderNeverReachesTheDeclarationLayer(): void {
		$this->declared->expects($this->never())->method('resolve');
		$this->expectDenial('unknown provider "codeberg"');

		$this->broker(provider: 'codeberg')->request(credentialId: self::CREDENTIAL, appId: 'alpha', method: 'GET', path: '/repos/x');
	}

	public function testTheReviewedCatalogueWinsWithoutConsultingDeclarations(): void {
		$this->declared->expects($this->never())->method('resolve');
		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn(200);
		$response->method('getHeaders')->willReturn([]);
		$response->method('getBody')->willReturn('{}');
		$this->client->expects($this->once())->method('request')
			->with('GET', 'https://api.github.com/repos/conduction/hydra', $this->anything())
			->willReturn($response);

		$result = $this->broker(provider: 'github', allowedApps: ['alpha', 'beta'])
			->request(credentialId: self::CREDENTIAL, appId: 'beta', method: 'GET', path: '/repos/conduction/hydra');

		$this->assertSame(200, $result['status']);
	}
}
