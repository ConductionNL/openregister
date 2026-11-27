<?php

/**
 * PersonalTokenGrantRevokedListener: a revoked personal token takes its grant
 * with it; a token without a grant, and any other event, change nothing.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/scoped-api-tokens/specs/auth-system/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Listener;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Listener\PersonalTokenGrantRevokedListener;
use OCA\OpenRegister\Service\Rbac\PersonalTokenGrantStore;
use OCP\Authentication\Events\TokenInvalidatedEvent;
use OCP\Authentication\Token\IToken;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

#[CoversClass(PersonalTokenGrantRevokedListener::class)]
final class PersonalTokenGrantRevokedListenerTest extends TestCase {

	private function revoked(int $id, string $uid): TokenInvalidatedEvent {
		$token = $this->createMock(IToken::class);
		$token->method('getId')->willReturn($id);
		$token->method('getUID')->willReturn($uid);

		return new TokenInvalidatedEvent($token);
	}//end revoked()

	public function testARevokedTokensGrantIsRemoved(): void {
		$store = $this->createMock(PersonalTokenGrantStore::class);
		$store->method('read')->with('alice', '42')->willReturn(['grant' => ['verbs' => ['read']]]);
		$store->expects($this->once())->method('remove')->with('alice', '42');

		(new PersonalTokenGrantRevokedListener($store, $this->createMock(LoggerInterface::class)))->handle($this->revoked(42, 'alice'));
	}//end testARevokedTokensGrantIsRemoved()

	public function testATokenWithoutAGrantWritesNothing(): void {
		$store = $this->createMock(PersonalTokenGrantStore::class);
		$store->method('read')->willReturn(null);
		$store->expects($this->never())->method('remove');

		(new PersonalTokenGrantRevokedListener($store, $this->createMock(LoggerInterface::class)))->handle($this->revoked(7, 'alice'));
	}//end testATokenWithoutAGrantWritesNothing()

	public function testAnotherEventIsIgnored(): void {
		$store = $this->createMock(PersonalTokenGrantStore::class);
		$store->expects($this->never())->method('read');
		$store->expects($this->never())->method('remove');

		(new PersonalTokenGrantRevokedListener($store, $this->createMock(LoggerInterface::class)))->handle(new Event());
	}//end testAnotherEventIsIgnored()

	public function testAFailureIsLoggedAndNeverStopsTheRevocation(): void {
		$store = $this->createMock(PersonalTokenGrantStore::class);
		$store->method('read')->willThrowException(new RuntimeException('preferences unavailable'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')
			->with($this->anything(), ['error' => 'preferences unavailable']);

		(new PersonalTokenGrantRevokedListener($store, $logger))->handle($this->revoked(42, 'alice'));
	}//end testAFailureIsLoggedAndNeverStopsTheRevocation()
}//end class
