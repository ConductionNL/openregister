<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Db\ContactLink;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Kernel\Contracts\ContactMatcherInterface;
use OCA\OpenRegister\Kernel\Contracts\GitHubClientInterface;
use OCA\OpenRegister\Service\Configuration\GitHubGuards;
use OCA\OpenRegister\Service\Configuration\GitHubRequestValidator;
use OCA\OpenRegister\Service\Configuration\RateLimiterService;
use OCA\OpenRegister\Service\ContactService;
use OCA\OpenRegister\Service\Credential\OAuth2ConnectionRepository;
use OCA\OpenRegister\Service\Credential\OAuth2ConnectService;
use OCA\OpenRegister\Service\Credential\OAuth2Endpoints;
use OCA\OpenRegister\Service\Credential\OAuth2RelayGuard;
use OCA\OpenRegister\Service\Credential\OAuth2StateService;
use OCA\OpenRegister\Service\DeepLinkRegistryService;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\PersonLinkService;
use OCA\OpenRegister\Tests\Unit\Controller\Support\ContactsControllerFactory;
use OCA\OpenRegister\Tests\Unit\Controller\Support\CredentialOauth2ControllerFactory;
use OCA\OpenRegister\Tests\Unit\Controller\Support\GitHubIssuesControllerFactory;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Security\Bruteforce\IThrottler;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Pins what the OAuth2 connect start, the contact routes and the GitHub issue
 * proxy decide in their private helpers: the claims a connection state carries
 * and who may start one, the object frame of the contact routes, and the GitHub
 * guard order and read cache key.
 */
final class B15ControllerHelpersGoldenTest extends TestCase {
	/**
	 * A connection start hands exactly these claims, in this order, to the connect service.
	 *
	 * @return void
	 */
	public function testAConnectionStartCarriesTheseClaims(): void {
		$captured = [];
		$connect = $this->createMock(OAuth2ConnectService::class);
		$connect->method('oauth2Provider')->willReturn(['oauth2' => ['defaultScopes' => ['read', 'write']]]);
		$connect->method('ensureInstanceClient')->willReturnCallback(
			static function (\OCA\OpenRegister\Service\Credential\OAuth2ClientRegistration $registration) use (&$captured): array {
				$captured = $registration->claims;
				throw new RuntimeException('stop after the claims');
			}
		);
		$connections = $this->createMock(OAuth2ConnectionRepository::class);
		$connections->method('gatedOrganisation')->willReturn('org-1');
		$endpoints = $this->createMock(OAuth2Endpoints::class);
		$endpoints->method('safeReturnUrl')->willReturn('/apps/openregister');
		$endpoints->method('callbackUrl')->willReturn('https://example.test/callback');

		$controller = $this->oauthController(
			params: ['provider' => 'github', 'scope' => 'organisation', 'clientId' => ' cid ', 'allowedApps' => ['opencatalogi', 'Bad App'], 'name' => ' Mine '],
			connect: $connect,
			connections: $connections,
			endpoints: $endpoints,
			signedIn: true
		);
		$this->assertSame(500, $controller->start()->getStatus());
		$this->assertSame(
			[
				'u' => 'alice',
				'p' => 'github',
				's' => 'organisation',
				'o' => 'org-1',
				'h' => null,
				'sc' => ['read', 'write'],
				'a' => ['opencatalogi'],
				'nm' => 'Mine',
				'cl' => 'cid',
				'cr' => '',
				'cid' => '',
				'r' => '/apps/openregister',
				'cb' => 'https://example.test/callback',
			],
			$captured
		);
	}//end testAConnectionStartCarriesTheseClaims()

	/**
	 * Re-authorising a credential the caller may not manage is refused before any state is issued.
	 *
	 * @return void
	 */
	public function testReauthorisingAnotherCredentialIsRefused(): void {
		foreach ([true, false] as $manageable) {
			$connect = $this->createMock(OAuth2ConnectService::class);
			$connect->method('oauth2Provider')->willReturn([]);
			if ($manageable === true) {
				$connect->expects($this->once())->method('ensureInstanceClient')->willThrowException(new RuntimeException('stop'));
			} else {
				$connect->expects($this->never())->method('ensureInstanceClient');
			}

			$connections = $this->createMock(OAuth2ConnectionRepository::class);
			$connections->method('findManageable')->willReturn($manageable === true ? new ObjectEntity() : null);
			$controller = $this->oauthController(
				params: ['provider' => 'github', 'credentialId' => 'cred-9'],
				connect: $connect,
				connections: $connections,
				endpoints: $this->createMock(OAuth2Endpoints::class),
				signedIn: true
			);
			$this->assertSame($manageable === true ? 500 : 403, $controller->start()->getStatus());
		}

		$anonymous = $this->oauthController(
			params: ['provider' => 'github'],
			connect: $this->createMock(OAuth2ConnectService::class),
			connections: $this->createMock(OAuth2ConnectionRepository::class),
			endpoints: $this->createMock(OAuth2Endpoints::class),
			signedIn: false
		);
		$this->assertSame(401, $anonymous->start()->getStatus());
	}//end testReauthorisingAnotherCredentialIsRefused()

	/**
	 * The contact routes answer 404 for a missing object, and a write needs the update right.
	 *
	 * @return void
	 */
	public function testTheContactRoutesFrameTheObject(): void {
		$cases = [
			'index, missing' => ['index', 'missing', 404],
			'index, found' => ['index', 'refused', 200],
			'create, missing' => ['create', 'missing', 404],
			'create, refused' => ['create', 'refused', 403],
			'create, allowed' => ['create', 'allowed', 201],
		];
		foreach ($cases as $label => [$action, $situation, $status]) {
			$object = new ObjectEntity();
			$object->setUuid('object-1');
			$object->setRegister('1');
			$object->setSchema('2');
			$objects = $this->createMock(ObjectService::class);
			$objects->method('getObject')->willReturn($situation === 'missing' ? null : $object);
			$objects->method('getCurrentSchemaEntity')->willReturn(new Schema());
			$permissions = $this->createMock(PermissionHandler::class);
			$permissions->method('hasPermission')->willReturn($situation === 'allowed');
			$personLinks = $this->createMock(PersonLinkService::class);
			$personLinks->method('listForObject')->willReturn([]);
			$personLinks->method('linkPerson')->willReturn(new ContactLink());
			$request = $this->createMock(IRequest::class);
			$request->method('getParams')->willReturn(['userId' => 'bob']);
			$controller = ContactsControllerFactory::create(
				'openregister',
				$request,
				$this->createMock(ContactService::class),
				$objects,
				$this->createMock(ContactMatcherInterface::class),
				$this->createMock(DeepLinkRegistryService::class),
				$this->createMock(IL10N::class),
				$this->createMock(LoggerInterface::class),
				$personLinks,
				$permissions
			);
			$this->assertSame($status, $controller->$action('r', 's', 'o')->getStatus(), $label);
		}
	}//end testTheContactRoutesFrameTheObject()

	/**
	 * The GitHub guards run in this order, and a read is cached under this key.
	 *
	 * @return void
	 */
	public function testTheGitHubGuardsRunInOrderAndTheReadKeyIsStable(): void {
		$calls = [];
		$guards = $this->createMock(GitHubGuards::class);
		$guards->method('runGuards')->willReturnCallback(
			static function (array $pipeline): mixed {
				foreach ($pipeline as $guard) {
					$guard();
				}

				return null;
			}
		);
		foreach (['enforceFeatureFlag', 'enforceRepoAllowlist', 'enforceGetRateLimit'] as $method) {
			$guards->method($method)->willReturnCallback(
				static function (mixed ...$args) use (&$calls, $method): mixed {
					$calls[] = $method . '(' . json_encode($args) . ')';
					return null;
				}
			);
		}

		$validator = $this->createMock(GitHubRequestValidator::class);
		foreach (['validateRepoFormat', 'validatePerPage', 'validateSort', 'validateLabels', 'validateTitleLength', 'validateBodyLength', 'validateSpecRef'] as $method) {
			$validator->method($method)->willReturnCallback(
				static function (mixed ...$args) use (&$calls, $method): mixed {
					$calls[] = $method . '(' . json_encode($args) . ')';
					return null;
				}
			);
		}

		$rateLimiter = $this->createMock(RateLimiterService::class);
		$rateLimiter->method('isOperational')->willReturnCallback(
			static function () use (&$calls): bool {
				$calls[] = 'isOperational';
				return true;
			}
		);
		$rateLimiter->method('checkFixedWindow')->willReturnCallback(
			static function (string $bucketKey, int $windowSeconds) use (&$calls): ?int {
				$calls[] = 'checkFixedWindow(' . $bucketKey . ')';
				return null;
			}
		);
		$cache = $this->createMock(ICache::class);
		$cache->method('get')->willReturnCallback(
			static function (string $key) use (&$calls): array {
				$calls[] = 'cache(' . $key . ')';
				return ['cached' => true];
			}
		);
		$cacheFactory = $this->createMock(ICacheFactory::class);
		$cacheFactory->method('createDistributed')->willReturn($cache);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);
		$github = $this->createMock(GitHubClientInterface::class);
		$github->method('createIssue')->willReturn(['number' => 1]);

		$params = ['repo' => 'o/r', 'state' => 'open', 'sort' => 'created', 'per_page' => '30', 'labels' => 'b, a', 'title' => 'A title', 'body' => 'A body', 'specRef' => 'cap'];
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => ($params[$key] ?? $default)
		);
		$controller = GitHubIssuesControllerFactory::create(
			'openregister',
			$request,
			$github,
			$guards,
			$validator,
			$rateLimiter,
			$userSession,
			$cacheFactory,
			$this->createMock(LoggerInterface::class),
			$this->createMock(IGroupManager::class)
		);

		$this->assertSame(200, $controller->index()->getStatus());
		$controller->create();
		$this->assertSame(
			[
				'enforceFeatureFlag([])',
				'validateRepoFormat(["o\/r"])',
				'enforceRepoAllowlist(["o\/r",true])',
				'validatePerPage([30])',
				'validateSort(["created"])',
				'validateLabels([["b","a"]])',
				'cache(list:o/r:open:created:30:a,b)',
				'enforceFeatureFlag([])',
				'validateRepoFormat(["o\/r"])',
				'enforceRepoAllowlist(["o\/r",false])',
				'validateTitleLength(["A title"])',
				'validateBodyLength(["A body"])',
				'validateSpecRef(["cap"])',
				'isOperational',
				'checkFixedWindow(feature_submission:alice)',
			],
			$calls
		);
	}//end testTheGitHubGuardsRunInOrderAndTheReadKeyIsStable()

	/**
	 * Build the OAuth2 connect controller around the given collaborators.
	 *
	 * @param array<string, mixed> $params The request parameters.
	 * @param OAuth2ConnectService $connect The connect service.
	 * @param OAuth2ConnectionRepository $connections The connection repository.
	 * @param OAuth2Endpoints $endpoints The endpoints.
	 * @param bool $signedIn Whether somebody is signed in.
	 *
	 * @return object
	 */
	private function oauthController(
		array $params,
		OAuth2ConnectService $connect,
		OAuth2ConnectionRepository $connections,
		OAuth2Endpoints $endpoints,
		bool $signedIn
	): object {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => ($params[$key] ?? $default)
		);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($signedIn === true ? $user : null);

		return CredentialOauth2ControllerFactory::create(
			'openregister',
			$request,
			$connect,
			$this->createMock(OAuth2StateService::class),
			$this->createMock(OAuth2RelayGuard::class),
			$connections,
			$endpoints,
			$userSession,
			$this->createMock(IThrottler::class),
			$this->createMock(LoggerInterface::class)
		);
	}//end oauthController()
}//end class
