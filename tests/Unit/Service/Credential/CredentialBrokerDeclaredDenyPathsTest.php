<?php

/**
 * Every deny path of the broker for app-declared providers, through the real admission chain.
 *
 * The declaration file, the approval records and the loader/validator/resolver are real; only
 * the app manager, the object store and the HTTP client are doubles. Each case asserts the
 * broker refuses before any outbound call and that the secret is never read.
 *
 * @category Tests
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

use OCA\OpenRegister\Contract\RegisterSchemaSlugs;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Service\Credential\CredentialAccessDeniedException;
use OCA\OpenRegister\Service\Credential\CredentialBrokerService;
use OCA\OpenRegister\Service\Credential\CredentialOutbound;
use OCA\OpenRegister\Service\Credential\CredentialPrincipals;
use OCA\OpenRegister\Service\Credential\CredentialSources;
use OCA\OpenRegister\Service\Credential\CredentialStore;
use OCA\OpenRegister\Service\Credential\DeclarationValidator;
use OCA\OpenRegister\Service\Credential\DeclaredProviderApprovals;
use OCA\OpenRegister\Service\Credential\DeclaredProviderLoader;
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
 * @covers \OCA\OpenRegister\Service\Credential\DeclaredProviderResolver
 * @uses   \OCA\OpenRegister\Service\Credential\DeclaredProviderLoader
 * @uses   \OCA\OpenRegister\Service\Credential\DeclaredProviderApprovals
 * @uses   \OCA\OpenRegister\Service\Credential\DeclarationValidator
 * @uses   \OCA\OpenRegister\Service\Credential\ProviderCatalogue
 * @uses   \OCA\OpenRegister\Service\Credential\CredentialSources
 * @uses   \OCA\OpenRegister\Service\Credential\CredentialPrincipals
 * @uses   \OCA\OpenRegister\Service\Credential\CredentialOutbound
 * @uses   \OCA\OpenRegister\Service\Credential\CredentialAccessDeniedException
 */
class CredentialBrokerDeclaredDenyPathsTest extends TestCase {

	private const CREDENTIAL = '00000000-0000-0000-0000-0000000000e1';

	private string $appsRoot;

	/** @var array<int, string> */
	private array $installed = [];

	/** @var array<int, array<string, mixed>> */
	private array $decisions = [];

	private DeclarationValidator $validator;

	private MockObject&IClient $client;

	private MockObject&CredentialStore $store;

	private MockObject&LoggerInterface $logger;

	protected function setUp(): void {
		$this->appsRoot = sys_get_temp_dir() . '/or-declared-deny-' . bin2hex(random_bytes(6));
		mkdir($this->appsRoot);
		$this->validator = new DeclarationValidator();
		$this->client = $this->createMock(IClient::class);
		$this->store = $this->createMock(CredentialStore::class);
		$this->store->method('get')->willReturn('YOUR_TOKEN_HERE');
		$this->logger = $this->createMock(LoggerInterface::class);
	}

	protected function tearDown(): void {
		exec('rm -rf ' . escapeshellarg($this->appsRoot));
	}

	/**
	 * A Codeberg declaration of an app.
	 *
	 * @param string $appId The app.
	 *
	 * @return array<string, mixed>
	 */
	private function codeberg(string $appId): array {
		return [
			'identifier' => $appId . ':codeberg',
			'title' => 'Codeberg',
			'baseUrl' => 'https://codeberg.org/api/v1',
			'authScheme' => ['header' => 'Authorization', 'template' => 'token {secret}'],
			'allowRules' => [['method' => 'GET', 'pathPattern' => '/repos/*']],
		];
	}

	/**
	 * Ship declarations in an installed app.
	 *
	 * @param string                              $appId     The app.
	 * @param array<string, array<string, mixed>> $providers The providers map.
	 */
	private function declare(string $appId, array $providers): void {
		$dir = $this->appsRoot . '/' . $appId . '/lib/Settings';
		if (is_dir($dir) === false) {
			mkdir($dir, 0777, true);
		}

		file_put_contents($dir . '/credential-providers.json', (string) json_encode(['providers' => $providers]));
		if (in_array($appId, $this->installed, true) === false) {
			$this->installed[] = $appId;
		}
	}

	/**
	 * Record an administrator decision.
	 *
	 * @param array<string, mixed> $entry  The declared entry decided on.
	 * @param string               $status The decision.
	 * @param string               $at     When.
	 */
	private function decide(array $entry, string $status, string $at): void {
		$this->decisions[] = [
			'providerIdentifier' => $entry['identifier'],
			'declaringApp' => explode(':', $entry['identifier'])[0],
			'declarationDigest' => $this->validator->digest($entry),
			'status' => $status,
			'decidedBy' => 'admin',
			'decidedAt' => $at,
		];
	}

	/**
	 * A broker over one credential, wired to the real declaration chain.
	 *
	 * @param string             $provider    The credential's provider.
	 * @param array<int, string> $allowedApps The credential's allowed apps.
	 */
	private function broker(string $provider = 'alpha:codeberg', array $allowedApps = ['alpha']): CredentialBrokerService {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getInstalledApps')->willReturnCallback(fn (): array => $this->installed);
		$appManager->method('getAppPath')->willReturnCallback(
			fn (string $appId): string => $appId === 'openregister' ? dirname(__DIR__, 4) : $this->appsRoot . '/' . $appId
		);
		$catalogue = new ProviderCatalogue($appManager, $this->createMock(LoggerInterface::class));

		$approvalStore = $this->createMock(ObjectOperationsInterface::class);
		$approvalStore->method('searchObjectsBySlug')->willReturnCallback(
			function (RegisterSchemaSlugs $target, array $filters): array {
				$rows = [];
				foreach ($this->decisions as $decision) {
					$object = new ObjectEntity();
					$object->setObject($decision);
					$rows[] = $object;
				}

				return $rows;
			}
		);

		$resolver = new DeclaredProviderResolver(
			loader: new DeclaredProviderLoader($appManager, $catalogue, $this->validator, $this->createMock(LoggerInterface::class)),
			approvals: new DeclaredProviderApprovals($approvalStore, $this->createMock(LoggerInterface::class)),
			validator: $this->validator
		);

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

		return new CredentialBrokerService(
			sources: new CredentialSources(objectService: $objectService, credentialStore: $this->store, catalogue: $catalogue, declared: $resolver),
			principals: new CredentialPrincipals(userSession: $session, organisationService: $this->createMock(OrganisationService::class)),
			outbound: new CredentialOutbound(clientService: $clients, logger: $this->logger)
		);
	}

	/**
	 * Expect a denial with this logged reason, no outbound call and no secret read.
	 *
	 * @param string $reason The logged reason.
	 */
	private function expectDenial(string $reason): void {
		$this->client->expects($this->never())->method('request');
		$this->store->expects($this->never())->method('get');
		$this->logger->expects($this->once())->method('warning')
			->with('[CredentialBrokerService] request denied: ' . $reason, ['credential' => self::CREDENTIAL]);
		$this->expectException(CredentialAccessDeniedException::class);
		$this->expectExceptionMessage('Request not permitted');
	}

	/**
	 * Call the broker as alpha.
	 *
	 * @param CredentialBrokerService $broker The broker.
	 * @param string                  $appId  The calling app.
	 */
	private function call(CredentialBrokerService $broker, string $appId = 'alpha'): void {
		$broker->request(credentialId: self::CREDENTIAL, appId: $appId, method: 'GET', path: '/repos/conduction/hydra');
	}

	public function testAnApprovedDeclarationIsCalled(): void {
		$entry = $this->codeberg('alpha');
		$this->declare('alpha', ['alpha:codeberg' => $entry]);
		$this->decide($entry, 'approved', '2026-11-01T10:00:00+00:00');
		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn(200);
		$response->method('getHeaders')->willReturn([]);
		$response->method('getBody')->willReturn('{}');
		$this->client->expects($this->once())->method('request')
			->with('GET', 'https://codeberg.org/api/v1/repos/conduction/hydra', $this->anything())
			->willReturn($response);

		$broker = $this->broker();
		$result = $broker->request(credentialId: self::CREDENTIAL, appId: 'alpha', method: 'GET', path: '/repos/conduction/hydra');

		$this->assertSame(200, $result['status']);
	}

	public function testAPendingDeclarationDenies(): void {
		$this->declare('alpha', ['alpha:codeberg' => $this->codeberg('alpha')]);
		$this->expectDenial('declared provider "alpha:codeberg" is not admitted (pending)');

		$this->call($this->broker());
	}

	public function testARejectedDeclarationDenies(): void {
		$entry = $this->codeberg('alpha');
		$this->declare('alpha', ['alpha:codeberg' => $entry]);
		$this->decide($entry, 'rejected', '2026-11-01T10:00:00+00:00');
		$this->expectDenial('declared provider "alpha:codeberg" is not admitted (rejected)');

		$this->call($this->broker());
	}

	public function testARevokedApprovalDeniesOnTheNextCall(): void {
		$entry = $this->codeberg('alpha');
		$this->declare('alpha', ['alpha:codeberg' => $entry]);
		$this->decide($entry, 'approved', '2026-11-01T10:00:00+00:00');
		$this->decide($entry, 'revoked', '2026-11-02T10:00:00+00:00');
		$this->expectDenial('declared provider "alpha:codeberg" is not admitted (revoked)');

		$this->call($this->broker());
	}

	public function testDigestDriftAfterApprovalDenies(): void {
		$approved = $this->codeberg('alpha');
		$this->decide($approved, 'approved', '2026-11-01T10:00:00+00:00');
		$edited = $approved;
		$edited['allowRules'][] = ['method' => 'POST', 'pathPattern' => '/repos/*'];
		$this->declare('alpha', ['alpha:codeberg' => $edited]);
		$this->expectDenial('declared provider "alpha:codeberg" is not admitted (pending)');

		$this->call($this->broker());
	}

	public function testADisabledDeclaringAppDenies(): void {
		$entry = $this->codeberg('alpha');
		$this->declare('alpha', ['alpha:codeberg' => $entry]);
		$this->decide($entry, 'approved', '2026-11-01T10:00:00+00:00');
		$this->installed = [];
		$this->expectDenial('declared provider "alpha:codeberg" is not admitted (unknown)');

		$this->call($this->broker());
	}

	public function testACrossAppBorrowDeniesEvenWhenTheCredentialAllowsTheBorrower(): void {
		$entry = $this->codeberg('alpha');
		$this->declare('alpha', ['alpha:codeberg' => $entry]);
		$this->decide($entry, 'approved', '2026-11-01T10:00:00+00:00');
		$this->expectDenial('declared provider "alpha:codeberg" is usable only by its declaring app');

		$this->call($this->broker(allowedApps: ['alpha', 'beta']), appId: 'beta');
	}

	public function testAnInjectOnlyDeclarationIsInvalidAndDenies(): void {
		$entry = $this->codeberg('alpha');
		$entry['inject_only'] = true;
		$this->declare('alpha', ['alpha:codeberg' => $entry]);
		$this->decide($entry, 'approved', '2026-11-01T10:00:00+00:00');
		$this->expectDenial('declared provider "alpha:codeberg" is not admitted (invalid)');

		$this->call($this->broker());
	}

	public function testAShadowingAttemptNeverReplacesTheReviewedEntry(): void {
		$shadow = $this->codeberg('alpha');
		$shadow['identifier'] = 'github';
		$shadow['baseUrl'] = 'https://evil.example';
		$this->declare('alpha', ['github' => $shadow]);
		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn(200);
		$response->method('getHeaders')->willReturn([]);
		$response->method('getBody')->willReturn('{}');
		$this->client->expects($this->once())->method('request')
			->with('GET', 'https://api.github.com/repos/conduction/hydra', $this->anything())
			->willReturn($response);

		$result = $this->broker(provider: 'github')
			->request(credentialId: self::CREDENTIAL, appId: 'alpha', method: 'GET', path: '/repos/conduction/hydra');

		$this->assertSame(200, $result['status']);
	}

	public function testAnotherAppsNamespaceCannotBeDeclared(): void {
		$foreign = $this->codeberg('beta');
		$this->declare('alpha', ['beta:codeberg' => $foreign]);
		$this->decide($foreign, 'approved', '2026-11-01T10:00:00+00:00');
		// alpha's file cannot speak for beta: the entry never reaches beta's namespace.
		$this->expectDenial('declared provider "beta:codeberg" is not admitted (unknown)');

		$this->call($this->broker(provider: 'beta:codeberg', allowedApps: ['beta']), appId: 'beta');
	}

	public function testAMintAgainstANonAdmittedDeclarationNeverCalls(): void {
		$this->declare('alpha', ['alpha:codeberg' => $this->codeberg('alpha')]);
		$this->expectDenial('declared provider "alpha:codeberg" is not admitted (pending)');

		// A credential row that exists for a pending declaration (minted in-process, or
		// before an edit re-pended it) is still refused at call time.
		$this->call($this->broker());
	}
}
