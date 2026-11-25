<?php

/**
 * What the token resolver answers when its sources fail.
 *
 * Each read it makes (the session, the token store, the token's own name and
 * owner, the signed-in user, the consumer lookup) can throw; every failure
 * leaves that part of the identity unknown instead of breaking the audited act.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Audit
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/enhanced-audit-trail/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\Audit;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Db\ConsumerMapperInterface;
use OCA\OpenRegister\Service\Audit\NamedIdentifier;
use OCA\OpenRegister\Service\Audit\TokenIdentity;
use OCA\OpenRegister\Service\Audit\TokenResolver;
use OCA\OpenRegister\Service\Consumer\AccountConsumerLookup;
use OCP\Authentication\Token\IProvider as ITokenProvider;
use OCP\Authentication\Token\IToken;
use OCP\ISession;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(TokenResolver::class)]
#[UsesClass(TokenIdentity::class)]
#[UsesClass(NamedIdentifier::class)]
#[UsesClass(AccountConsumerLookup::class)]
final class TokenResolverFailurePathsTest extends TestCase {

	private function session(string $password): ISession {
		$session = $this->createMock(ISession::class);
		$session->method('get')->with(TokenResolver::SESSION_KEY)->willReturn($password);

		return $session;
	}//end session()

	private function resolver(
		ISession $session,
		ITokenProvider $tokens,
		?IUserSession $users = null,
		?ConsumerMapperInterface $consumers = null
	): TokenResolver {
		return new TokenResolver(
			$session,
			$tokens,
			($users ?? $this->createMock(IUserSession::class)),
			new AccountConsumerLookup(($consumers ?? $this->createMock(ConsumerMapperInterface::class)))
		);
	}//end resolver()

	public function testAnUnavailableSessionResolvesNoToken(): void {
		$session = $this->createMock(ISession::class);
		$session->method('get')->willThrowException(new RuntimeException('no session'));
		$tokens = $this->createMock(ITokenProvider::class);
		$tokens->expects(self::never())->method('getToken');

		self::assertNull($this->resolver($session, $tokens)->resolve());
	}//end testAnUnavailableSessionResolvesNoToken()

	public function testATokenTheStoreCannotFindResolvesNoToken(): void {
		$tokens = $this->createMock(ITokenProvider::class);
		$tokens->expects(self::once())->method('getToken')->with('pw-1')->willThrowException(new RuntimeException('invalid'));

		self::assertNull($this->resolver($this->session('pw-1'), $tokens)->resolve());
	}//end testATokenTheStoreCannotFindResolvesNoToken()

	public function testAnUnreadableNameAndOwnerLeaveBothUnknownAndLookUpNoConsumer(): void {
		$token = $this->createMock(IToken::class);
		$token->method('getId')->willReturn(42);
		$token->method('getName')->willThrowException(new RuntimeException('no name'));
		$token->method('getUID')->willThrowException(new RuntimeException('no owner'));
		$tokens = $this->createMock(ITokenProvider::class);
		$tokens->method('getToken')->willReturn($token);
		$users = $this->createMock(IUserSession::class);
		$users->expects(self::never())->method('getUser');
		$consumers = $this->createMock(ConsumerMapperInterface::class);
		$consumers->expects(self::never())->method('findAll');

		$identity = $this->resolver($this->session('pw-1'), $tokens, $users, $consumers)->resolve();

		self::assertNotNull($identity);
		self::assertSame('app-password', $identity->mechanism());
		self::assertSame('42', $identity->reference());
		self::assertNull($identity->name());
		self::assertNull($identity->ownerUid());
		self::assertNull($identity->ownerName());
		self::assertNull($identity->consumerUuid());
	}//end testAnUnreadableNameAndOwnerLeaveBothUnknownAndLookUpNoConsumer()

	public function testABlankNameAndOwnerCountAsUnknown(): void {
		$token = $this->createMock(IToken::class);
		$token->method('getId')->willReturn(7);
		$token->method('getName')->willReturn('   ');
		$token->method('getUID')->willReturn('  ');
		$tokens = $this->createMock(ITokenProvider::class);
		$tokens->method('getToken')->willReturn($token);

		$identity = $this->resolver($this->session('pw-1'), $tokens)->resolve();

		self::assertNotNull($identity);
		self::assertNull($identity->name());
		self::assertNull($identity->ownerUid());
	}//end testABlankNameAndOwnerCountAsUnknown()

	public function testAFailingUserSessionAndConsumerLookupLeaveOwnerNameAndConsumerUnknown(): void {
		$token = $this->createMock(IToken::class);
		$token->method('getId')->willReturn(9);
		$token->method('getName')->willReturn('CI deploy');
		$token->method('getUID')->willReturn('svc-ci');
		$tokens = $this->createMock(ITokenProvider::class);
		$tokens->method('getToken')->willReturn($token);
		$users = $this->createMock(IUserSession::class);
		$users->method('getUser')->willThrowException(new RuntimeException('no user session'));
		$consumers = $this->createMock(ConsumerMapperInterface::class);
		$consumers->expects(self::once())->method('findAll')
			->with(self::anything(), self::anything(), ['user_id' => 'svc-ci'])
			->willThrowException(new RuntimeException('db down'));

		$identity = $this->resolver($this->session('pw-1'), $tokens, $users, $consumers)->resolve();

		self::assertNotNull($identity);
		self::assertSame('CI deploy', $identity->name());
		self::assertSame('svc-ci', $identity->ownerUid());
		self::assertNull($identity->ownerName());
		self::assertNull($identity->consumerUuid());
		self::assertNull($identity->consumerName());
	}//end testAFailingUserSessionAndConsumerLookupLeaveOwnerNameAndConsumerUnknown()

	public function testASignedInUserWithABlankDisplayNameGivesNoOwnerName(): void {
		$token = $this->createMock(IToken::class);
		$token->method('getId')->willReturn(9);
		$token->method('getName')->willReturn('CI deploy');
		$token->method('getUID')->willReturn('svc-ci');
		$tokens = $this->createMock(ITokenProvider::class);
		$tokens->method('getToken')->willReturn($token);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('svc-ci');
		$user->method('getDisplayName')->willReturn(' ');
		$users = $this->createMock(IUserSession::class);
		$users->method('getUser')->willReturn($user);

		$identity = $this->resolver($this->session('pw-1'), $tokens, $users)->resolve();

		self::assertNotNull($identity);
		self::assertNull($identity->ownerName());
	}//end testASignedInUserWithABlankDisplayNameGivesNoOwnerName()
}//end class
