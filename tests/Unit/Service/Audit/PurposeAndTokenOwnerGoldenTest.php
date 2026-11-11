<?php

/**
 * Golden characterisation test for how a purpose annotation is read and how a
 * token's owner and consumer are named.
 *
 * Pinned on the code before PurposeGuard's annotation reading and TokenResolver's
 * owner and consumer lookups were split into named steps: which annotation values
 * require a purpose (allowed reads without one vs refused), and when an owner name
 * or a consumer is attributed and when it is not.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Audit
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/verwerkingsregister-api/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\Audit;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\Consumer;
use OCA\OpenRegister\Db\ConsumerMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Audit\PurposeContext;
use OCA\OpenRegister\Service\Audit\PurposeGuard;
use OCA\OpenRegister\Service\Audit\PurposeRegistry;
use OCA\OpenRegister\Service\Audit\TokenResolver;
use OCP\Authentication\Token\IProvider as ITokenProvider;
use OCP\Authentication\Token\IToken;
use OCP\IRequest;
use OCP\ISession;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

final class PurposeAndTokenOwnerGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/fixtures/purpose-and-token-owner-golden.json';

	private function guard(): PurposeGuard {
		$registry = $this->createMock(PurposeRegistry::class);
		$registry->method('hasAdministeredPurposes')->willReturn(true);
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturn('');
		$request->method('getParam')->willReturn(null);

		return new PurposeGuard($registry, new PurposeContext($request), $this->createMock(AuditTrailMapper::class), new NullLogger());
	}

	private function annotationOutcomes(): array {
		$values   = [
			'true' => true, 'false' => false, '"true"' => 'true', '" YES "' => ' YES ', '"1"' => '1', '"no"' => 'no',
			'""' => '', '1' => 1, '0' => 0, '2' => 2, '1.0' => 1.0, 'list' => ['x'], 'null' => null,
		];
		$guard    = $this->guard();
		$outcomes = [];
		foreach ($values as $name => $value) {
			$schema = new Schema();
			$schema->setId(1);
			$schema->setConfiguration([PurposeGuard::ANNOTATION => $value]);
			$register = new Register();
			$register->setId(2);
			$register->setConfiguration([PurposeGuard::ANNOTATION => $value]);
			$plain = new Register();
			$plain->setId(3);
			$plain->setConfiguration([]);
			$outcomes['schema ' . $name] = $guard->isRequired($plain, $schema);
			$outcomes['register ' . $name] = $guard->isRequired($register, null);
		}

		return $outcomes;
	}

	private function resolver(?callable $sessionUser, array|RuntimeException $consumers): TokenResolver {
		$session = $this->createMock(ISession::class);
		$session->method('get')->willReturn('app-password-value');
		$token = $this->createMock(IToken::class);
		$token->method('getId')->willReturn(4711);
		$token->method('getName')->willReturn('Koppeling');
		$token->method('getUID')->willReturn('svc');
		$provider = $this->createMock(ITokenProvider::class);
		$provider->method('getToken')->willReturn($token);
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturnCallback($sessionUser ?? static fn () => null);
		$mapper = $this->createMock(ConsumerMapper::class);
		if ($consumers instanceof RuntimeException) {
			$mapper->method('findAll')->willThrowException($consumers);
		} else {
			$mapper->method('findAll')->willReturn($consumers);
		}

		return new TokenResolver($session, $provider, $userSession, $mapper);
	}

	private function user(string $uid, string $displayName): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$user->method('getDisplayName')->willReturn($displayName);

		return $user;
	}

	private function consumer(string $uuid): Consumer {
		$consumer = new Consumer();
		$consumer->setUuid($uuid);
		$consumer->setName('Leverancier ' . $uuid);

		return $consumer;
	}

	private function ownerOutcomes(): array {
		$owner  = $this->user('svc', 'Koppeling leverancier');
		$cases  = [
			'owner in session' => [fn () => $owner, [$this->consumer('c1')]],
			'another user in session' => [fn () => $this->user('alice', 'Alice'), [$this->consumer('c1')]],
			'blank display name' => [fn () => $this->user('svc', '   '), []],
			'no session user' => [null, [$this->consumer('c1'), $this->consumer('c2')]],
			'session throws' => [static fn () => throw new RuntimeException('no session'), new RuntimeException('db down')],
			'a non-consumer row' => [fn () => $owner, [new \stdClass()]],
		];
		$outcomes = [];
		foreach ($cases as $name => [$sessionUser, $consumers]) {
			$identity         = $this->resolver($sessionUser, $consumers)->resolve();
			$outcomes[$name] = $identity?->toArray();
		}

		return $outcomes;
	}

	public function testOutcomesMatchTheGolden(): void {
		$actual = ['annotation' => $this->annotationOutcomes(), 'owner' => $this->ownerOutcomes()];
		if (getenv('UPDATE_GOLDEN') === '1') {
			file_put_contents(self::GOLDEN, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}

		$this->assertSame(json_decode((string)file_get_contents(self::GOLDEN), true), json_decode((string)json_encode($actual), true));
	}
}//end class
