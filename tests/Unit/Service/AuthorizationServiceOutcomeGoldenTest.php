<?php

/**
 * Golden test: what Basic, OAuth and API-key authorisation admit and refuse
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Db\Consumer;
use OCA\OpenRegister\Db\ConsumerMapper;
use OCA\OpenRegister\Exception\AuthenticationException;
use OCA\OpenRegister\Service\Audit\TokenContext;
use OCA\OpenRegister\Service\Audit\TokenIdentity;
use OCA\OpenRegister\Service\AuthorizationService;
use OCA\OpenRegister\Service\Rbac\TokenGrantSource;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Pins, for authorizeBasic, authorizeOAuth and authorizeApiKey, every refusal
 * (exception message and details) and every admission (who the request acts
 * as, which grant is bound, which token identity is claimed, in which order).
 */
class AuthorizationServiceOutcomeGoldenTest extends TestCase {

	/** @var array<int, string> Side effects in the order they happened. */
	private array $events = [];

	/** @var array<int, Consumer> OpenRegister's consumer table. */
	private array $consumers = [];

	private string $authorizationHeader = '';

	private bool $loggedIn = false;

	private ?IUser $sessionUser = null;

	private bool $withRequest = true;

	private bool $withContext = true;

	/**
	 * The service over doubles that record their side effects.
	 *
	 * @return AuthorizationService
	 */
	private function service(): AuthorizationService {
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturnCallback(fn (string $uid): ?IUser => ($uid === 'ghost') ? null : $this->user($uid));
		$users->method('checkPassword')->willReturnCallback(
			fn (string $login, string $password) => ($password === 'p:a:ss') ? $this->user($login) : false
		);

		$session = $this->createMock(IUserSession::class);
		$session->method('setVolatileActiveUser')->willReturnCallback(
			function (?IUser $user): void {
				$this->events[] = 'actAs:' . ($user?->getUID() ?? 'null');
			}
		);
		$session->method('setUser')->willReturnCallback(
			function (): void {
				$this->events[] = 'setUser';
			}
		);
		$session->method('isLoggedIn')->willReturnCallback(fn (): bool => $this->loggedIn);
		$session->method('getUser')->willReturnCallback(fn (): ?IUser => $this->sessionUser);

		$mapper = $this->createMock(ConsumerMapper::class);
		$mapper->method('findAll')->willReturnCallback(fn (): array => $this->consumers);

		$grants = $this->createMock(TokenGrantSource::class);
		$grants->method('bindFromConsumer')->willReturnCallback(
			function (?array $storedAuthorization, string $tokenId) {
				$this->events[] = 'grant:' . $tokenId . ':' . json_encode($storedAuthorization);
				return null;
			}
		);

		$context = $this->createMock(TokenContext::class);
		$context->method('claim')->willReturnCallback(
			function (?TokenIdentity $identity): void {
				$this->events[] = 'claim:' . json_encode($identity?->toArray());
			}
		);

		$staff = $this->createMock(IGroup::class);
		$staff->method('getGID')->willReturn('staff');
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('getUserGroups')->willReturnCallback(
			fn (IUser $user) => ($user->getUID() === 'piet') ? [$staff] : []
		);

		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturnCallback(
			fn (string $name) => (strtolower($name) === 'authorization') ? $this->authorizationHeader : ''
		);

		$tokenContext = null;
		if ($this->withContext === true) {
			$tokenContext = $context;
		}

		$theRequest = null;
		if ($this->withRequest === true) {
			$theRequest = $request;
		}

		return new AuthorizationService(
			$users,
			$session,
			$mapper,
			$grants,
			$tokenContext,
			null,
			$groups,
			$theRequest,
		);
	}//end service()

	/**
	 * A user double.
	 *
	 * @param string $uid The uid.
	 *
	 * @return IUser
	 */
	private function user(string $uid): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$user->method('getEMailAddress')->willReturn(null);
		return $user;
	}//end user()

	/**
	 * An API-key consumer in the table.
	 *
	 * @param array{name: string, uuid: string|null, userId: string, key: string} $row The consumer.
	 *
	 * @return void
	 */
	private function keyConsumer(array $row): void {
		$consumer = new Consumer();
		if ($row['uuid'] !== null) {
			$consumer->setUuid($row['uuid']);
		}

		$consumer->setName($row['name']);
		$consumer->setUserId($row['userId']);
		$consumer->setAuthorizationType('apiKey');
		$consumer->setAuthorizationConfiguration(['apiKey' => $row['key'], 'tokenGrant' => ['r' => 1]]);
		$this->consumers[] = $consumer;
	}//end keyConsumer()

	/**
	 * Run a call and describe its outcome.
	 *
	 * @param callable $call The call.
	 *
	 * @return array<string, mixed>
	 */
	private function outcome(callable $call): array {
		try {
			$call();
		} catch (AuthenticationException $e) {
			return ['refused' => $e->getMessage(), 'details' => $e->getDetails(), 'events' => $this->events];
		}

		return ['admitted' => true, 'events' => $this->events];
	}//end outcome()

	/**
	 * Refusals and admissions of HTTP Basic.
	 *
	 * @return array<string, array{0: string, 1: array<int, string>, 2: array<int, string>, 3: array<string, mixed>}>
	 */
	public static function basicCases(): array {
		$refused = ['refused' => 'Invalid username or password', 'details' => [], 'events' => []];
		return [
			'malformed base64' => ['Basic !!!', [], [], $refused],
			'no colon' => ['Basic ' . base64_encode('piet'), [], [], $refused],
			'wrong password' => ['Basic ' . base64_encode('piet:nope'), [], [], $refused],
			'password keeps colons' => ['Basic ' . base64_encode('piet:p:a:ss'), [], [], ['admitted' => true, 'events' => ['actAs:piet']]],
			'in allowed group' => ['Basic ' . base64_encode('piet:p:a:ss'), [], ['staff'], ['admitted' => true, 'events' => ['actAs:piet']]],
			'named user' => ['Basic ' . base64_encode('klaas:p:a:ss'), ['klaas'], [], ['admitted' => true, 'events' => ['actAs:klaas']]],
		];
	}//end basicCases()

	/**
	 * Basic authorisation outcomes.
	 *
	 * @param string               $header   The header.
	 * @param array<int, string>   $users    Allowed users.
	 * @param array<int, string>   $groups   Allowed groups.
	 * @param array<string, mixed> $expected The outcome.
	 *
	 * @return void
	 *
	 * @dataProvider basicCases
	 */
	public function testBasic(string $header, array $users, array $groups, array $expected): void {
		$service = $this->service();
		$this->assertSame($expected, $this->outcome(fn () => $service->authorizeBasic($header, $users, $groups)));
		$this->assertNull($service->getResolvedConsumer());
	}//end testBasic()

	/**
	 * A Basic user outside the allow-list is refused and never acted as.
	 *
	 * @return void
	 */
	public function testBasicOutsideTheAllowList(): void {
		$outcome = $this->outcome(fn () => $this->service()->authorizeBasic('Basic ' . base64_encode('klaas:p:a:ss'), [], ['staff']));
		$this->assertArrayHasKey('refused', $outcome);
		$this->assertSame([], $outcome['events']);
		$this->assertSame($this->allowListRefusal(), [$outcome['refused'], $outcome['details']]);
	}//end testBasicOutsideTheAllowList()

	/**
	 * What the endpoint allow-list throws for a user outside it, read from the allow-list itself.
	 *
	 * @return array{0: string, 1: array<string, mixed>}
	 */
	private function allowListRefusal(): array {
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('getUserGroups')->willReturn([]);
		try {
			(new \OCA\OpenRegister\Service\Consumer\EndpointAllowList(groupManager: $groups))->assertAllowed(
				user: $this->user('klaas'),
				users: [],
				groups: ['staff']
			);
		} catch (AuthenticationException $e) {
			return [$e->getMessage(), $e->getDetails()];
		}

		$this->fail('The allow-list admitted a user outside it.');
	}//end allowListRefusal()

	/**
	 * OAuth refusals and admissions.
	 *
	 * @return void
	 */
	public function testOAuth(): void {
		$method = ['refused' => 'Invalid method', 'details' => ['reason' => 'The authentication method you are using is not allowed on this resource.'], 'events' => []];
		$empty = ['refused' => 'Invalid token', 'details' => ['reason' => 'Bearer token value is empty.'], 'events' => []];
		$cookie = ['refused' => 'Not authorized', 'details' => ['reason' => 'OAuth endpoints require Bearer token authentication, not session cookie auth.'], 'events' => []];
		$expired = ['refused' => 'Not authorized', 'details' => ['reason' => 'The token you used has either expired or was not recognized as a valid token'], 'events' => []];

		$this->assertSame($method, $this->outcome(fn () => $this->service()->authorizeOAuth('Basic abc')));
		$this->assertSame($empty, $this->outcome(fn () => $this->service()->authorizeOAuth('Bearer   ')));
		$this->assertSame($cookie, $this->outcome(fn () => $this->service()->authorizeOAuth('Bearer t')));

		$this->authorizationHeader = 'Bearer t';
		$this->withRequest = false;
		$this->assertSame($cookie, $this->outcome(fn () => $this->service()->authorizeOAuth('Bearer t')), 'No request fails closed.');
		$this->withRequest = true;

		$this->assertSame($expired, $this->outcome(fn () => $this->service()->authorizeOAuth('Bearer t')));
		$this->loggedIn = true;
		$this->assertSame($expired, $this->outcome(fn () => $this->service()->authorizeOAuth('Bearer t')), 'Logged in without a user.');
		$this->sessionUser = $this->user('klaas');
		$this->loggedIn = false;
		$this->assertSame($expired, $this->outcome(fn () => $this->service()->authorizeOAuth('Bearer t')), 'A user that is not logged in.');
		$this->loggedIn = true;

		$outside = $this->outcome(fn () => $this->service()->authorizeOAuth('Bearer t', [], ['staff']));
		$this->assertSame($this->allowListRefusal(), [$outside['refused'] ?? null, $outside['details'] ?? null]);
		$this->assertSame(['admitted' => true, 'events' => []], $this->outcome(fn () => $this->service()->authorizeOAuth('Bearer t', ['klaas'])));
		$this->assertSame(['admitted' => true, 'events' => []], $this->outcome(fn () => $this->service()->authorizeOAuth('Bearerx')), 'Only the request header must carry the space.');
	}//end testOAuth()

	/**
	 * API-key refusals and admissions, with what is bound and claimed.
	 *
	 * @return void
	 */
	public function testApiKey(): void {
		$invalid = ['refused' => 'Invalid API key', 'details' => [], 'events' => []];
		$this->keyConsumer(['name' => 'portaal', 'uuid' => 'c0ffee00-0000-4000-8000-000000000002', 'userId' => 'api-user', 'key' => 'k-123']);
		$this->keyConsumer(['name' => 'anoniem', 'uuid' => null, 'userId' => '', 'key' => 'k-456']);

		$this->assertSame($invalid, $this->outcome(fn () => $this->service()->authorizeApiKey('', ['' => 'piet'])), 'An empty key never matches.');
		$this->assertSame($invalid, $this->outcome(fn () => $this->service()->authorizeApiKey('nope', ['k-1' => 'piet'])));
		$this->assertSame($invalid, $this->outcome(fn () => $this->service()->authorizeApiKey('k-1', ['k-1' => 'ghost'])), 'A key whose user is gone.');

		$service = $this->service();
		$this->assertSame(['admitted' => true, 'events' => ['actAs:piet']], $this->outcome(fn () => $service->authorizeApiKey('k-2', ['k-1' => 'klaas', 'k-2' => 'piet', 'k-3' => 'ghost'])));
		$this->assertNull($service->getResolvedConsumer());

		$this->events = [];
		$this->assertSame(['admitted' => true, 'events' => ['actAs:klaas']], $this->outcome(fn () => $this->service()->authorizeApiKey('k-123', ['k-123' => 'klaas'])), 'The endpoint key wins over a consumer key.');

		$this->events = [];
		$service = $this->service();
		$identity = new TokenIdentity(
			mechanism: 'apiKey',
			reference: 'c0ffee00-0000-4000-8000-000000000002',
			name: 'portaal',
			ownerUid: 'api-user',
			ownerName: null,
			consumerUuid: 'c0ffee00-0000-4000-8000-000000000002',
			consumerName: 'portaal'
		);
		$this->assertSame(
			[
				'admitted' => true,
				'events' => [
					'grant:c0ffee00-0000-4000-8000-000000000002:{"apiKey":"k-123","tokenGrant":{"r":1}}',
					'actAs:api-user',
					'claim:' . json_encode($identity->toArray()),
				],
			],
			$this->outcome(fn () => $service->authorizeApiKey('k-123', []))
		);
		$this->assertSame('portaal', $service->getResolvedConsumer()?->name);

		$this->events = [];
		$this->withContext = false;
		$service = $this->service();
		$this->assertSame(
			['admitted' => true, 'events' => ['grant:anoniem:{"apiKey":"k-456","tokenGrant":{"r":1}}']],
			$this->outcome(fn () => $service->authorizeApiKey('k-456', [])),
			'No uuid: the grant id is the name; no user: nobody to act as; no context: nothing claimed.'
		);
		$this->assertSame('anoniem', $service->getResolvedConsumer()?->name);

		$this->events = [];
		$service->authorizeBasic('Basic ' . base64_encode('piet:p:a:ss'));
		$this->assertNull($service->getResolvedConsumer(), 'A later call clears the consumer.');
		$this->assertSame(['actAs:piet'], $this->events);
	}//end testApiKey()
}//end class
