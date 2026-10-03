<?php

/**
 * Unit tests for working out which token made the current call.
 *
 * The distinction these tests exist to hold is the one REQ-ATS-002 rests on:
 * an entry naming a token has to mean a machine wrote this, so a browser
 * session must resolve to nothing at all.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Audit
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/audit-trail-shipped-and-purpose-bound/specs/enhanced-audit-trail/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\Audit;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Db\Consumer;
use OCA\OpenRegister\Db\ConsumerMapper;
use OCA\OpenRegister\Service\Audit\TokenContext;
use OCA\OpenRegister\Service\Audit\TokenResolver;
use OCP\Authentication\Token\IProvider as ITokenProvider;
use OCP\Authentication\Token\IToken;
use OCP\ISession;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

final class TokenResolverTest extends TestCase {
	private function session(?string $appPassword): ISession {
		$session = $this->createMock(ISession::class);
		$session->method('get')->willReturn($appPassword);

		return $session;
	}//end session()

	private function token(int $id, string $name, string $uid): IToken {
		$token = $this->createMock(IToken::class);
		$token->method('getId')->willReturn($id);
		$token->method('getName')->willReturn($name);
		$token->method('getUID')->willReturn($uid);

		return $token;
	}//end token()

	private function userSession(?string $uid, string $displayName = ''): IUserSession {
		$userSession = $this->createMock(IUserSession::class);
		if ($uid === null) {
			$userSession->method('getUser')->willReturn(null);

			return $userSession;
		}

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$user->method('getDisplayName')->willReturn($displayName);
		$userSession->method('getUser')->willReturn($user);

		return $userSession;
	}//end userSession()

	private function consumers(array $rows): ConsumerMapper {
		$mapper = $this->createMock(ConsumerMapper::class);
		$mapper->method('findAll')->willReturn($rows);

		return $mapper;
	}//end consumers()

	private function consumer(string $uuid, string $name): Consumer {
		$consumer = new Consumer();
		$consumer->setUuid($uuid);
		$consumer->setName($name);

		return $consumer;
	}//end consumer()

	public function testAnInteractiveSessionResolvesToNoToken(): void {
		// No app password means a person is clicking, and a row naming a token
		// would be a false claim about who wrote it.
		$resolver = new TokenResolver(
			$this->session(null),
			$this->createMock(ITokenProvider::class),
			$this->userSession('alice', 'Alice'),
			$this->consumers([])
		);

		self::assertNull($resolver->resolve());
	}//end testAnInteractiveSessionResolvesToNoToken()

	public function testATokenCallNamesTheTokenAndItsOwner(): void {
		$provider = $this->createMock(ITokenProvider::class);
		$provider->method('getToken')->willReturn($this->token(4711, 'Leverancier koppeling', 'svc-leverancier'));

		$resolver = new TokenResolver(
			$this->session('the-app-password'),
			$provider,
			$this->userSession('svc-leverancier', 'Koppeling leverancier'),
			$this->consumers([])
		);

		$identity = $resolver->resolve();

		self::assertNotNull($identity);
		self::assertSame('app-password', $identity->mechanism());
		self::assertSame('4711', $identity->reference());
		self::assertSame('Leverancier koppeling', $identity->name());
		self::assertSame('svc-leverancier', $identity->ownerUid());
		self::assertSame('Koppeling leverancier', $identity->ownerName());
		self::assertTrue($identity->isAttributable());
	}//end testATokenCallNamesTheTokenAndItsOwner()

	public function testTheTokenValueIsNeverCarried(): void {
		// The app password is in hand here and must not travel any further: the
		// trail is shipped off the instance and kept for years.
		$provider = $this->createMock(ITokenProvider::class);
		$provider->method('getToken')->willReturn($this->token(4711, 'Leverancier koppeling', 'svc-leverancier'));

		$resolver = new TokenResolver(
			$this->session('super-secret-app-password'),
			$provider,
			$this->userSession('svc-leverancier', 'Koppeling leverancier'),
			$this->consumers([])
		);

		$identity = $resolver->resolve();

		self::assertNotNull($identity);
		self::assertStringNotContainsString(
			'super-secret-app-password',
			(string)json_encode($identity->toArray())
		);
	}//end testTheTokenValueIsNeverCarried()

	public function testTheSingleConsumerRunningAsTheOwnerIsNamed(): void {
		$provider = $this->createMock(ITokenProvider::class);
		$provider->method('getToken')->willReturn($this->token(4711, 'koppeling', 'svc-leverancier'));

		$resolver = new TokenResolver(
			$this->session('the-app-password'),
			$provider,
			$this->userSession('svc-leverancier'),
			$this->consumers([$this->consumer('consumer-uuid', 'Leverancier BV')])
		);

		$identity = $resolver->resolve();

		self::assertNotNull($identity);
		self::assertSame('Leverancier BV', $identity->consumerName());
		self::assertSame('consumer-uuid', $identity->consumerUuid());
	}//end testTheSingleConsumerRunningAsTheOwnerIsNamed()

	public function testTwoConsumersSharingOneUserResolveToNeither(): void {
		// Naming the first of two would be a confident wrong answer, and
		// somebody acts on this field when they are already suspicious.
		$provider = $this->createMock(ITokenProvider::class);
		$provider->method('getToken')->willReturn($this->token(4711, 'koppeling', 'svc-gedeeld'));

		$resolver = new TokenResolver(
			$this->session('the-app-password'),
			$provider,
			$this->userSession('svc-gedeeld'),
			$this->consumers([
				$this->consumer('uuid-a', 'Leverancier A'),
				$this->consumer('uuid-b', 'Leverancier B'),
			])
		);

		$identity = $resolver->resolve();

		self::assertNotNull($identity);
		self::assertNull($identity->consumerName());
		// The token itself is still known, so the row is still attributable.
		self::assertTrue($identity->isAttributable());
	}//end testTwoConsumersSharingOneUserResolveToNeither()

	public function testAnUnresolvableTokenIsNotAnError(): void {
		// An expired or revoked app password throws out of the provider. The
		// save it is describing must still finish.
		$provider = $this->createMock(ITokenProvider::class);
		$provider->method('getToken')->willThrowException(new \RuntimeException('token invalid'));

		$resolver = new TokenResolver(
			$this->session('the-app-password'),
			$provider,
			$this->userSession('svc-leverancier'),
			$this->consumers([])
		);

		self::assertNull($resolver->resolve());
	}//end testAnUnresolvableTokenIsNotAnError()

	public function testTheContextResolvesOncePerRequest(): void {
		// A bulk import writes thousands of rows in one request, and a token
		// lookup per row is the difference between an import that finishes and
		// one that does not. The absence is cached too.
		$resolver = $this->createMock(TokenResolver::class);
		$resolver->expects(self::once())->method('resolve')->willReturn(null);

		$context = new TokenContext($resolver);

		self::assertNull($context->identity());
		self::assertNull($context->identity());
		self::assertNull($context->identity());
	}//end testTheContextResolvesOncePerRequest()

	public function testAClaimWinsOverResolution(): void {
		// The authorisation layer knows which consumer presented a JWT, and the
		// resolver cannot work that out from a session with no app password.
		$resolver = $this->createMock(TokenResolver::class);
		$resolver->method('resolve')->willReturn(null);

		$context = new TokenContext($resolver);
		$context->claim(
			new \OCA\OpenRegister\Service\Audit\TokenIdentity(
				'jwt',
				'jti-1',
				'Leverancier BV',
				'svc-leverancier',
				null,
				'consumer-uuid',
				'Leverancier BV'
			)
		);

		$identity = $context->identity();

		self::assertNotNull($identity);
		self::assertSame('jwt', $identity->mechanism());
		self::assertSame('Leverancier BV', $identity->consumerName());
	}//end testAClaimWinsOverResolution()
}//end class
