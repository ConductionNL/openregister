<?php

/**
 * The personal token store: a grant kept per token id in its owner's user
 * values, unreadable kept apart from absent, and a changed end date recorded
 * as a renewal with its actor.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Rbac
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

namespace OCA\OpenRegister\Tests\Unit\Service\Rbac;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use DateTimeImmutable;
use OCA\OpenRegister\Service\Rbac\PersonalTokenGrantStore;
use OCA\OpenRegister\Service\Rbac\TokenGrant;
use OCA\OpenRegister\Service\Rbac\TokenGrantReach;
use OCA\OpenRegister\Service\Rbac\TokenGrantTerms;
use OCP\IConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PersonalTokenGrantStore::class)]
#[UsesClass(TokenGrant::class)]
#[UsesClass(TokenGrantReach::class)]
#[UsesClass(TokenGrantTerms::class)]
final class PersonalTokenGrantStoreTest extends TestCase {
	/**
	 * The user values the fake config holds, keyed by "uid/app/key".
	 *
	 * @var array<string, string>
	 */
	private array $values = [];

	/**
	 * The app values the fake config holds, keyed by key.
	 *
	 * @var array<string, string>
	 */
	private array $app = [];

	private function store(): PersonalTokenGrantStore {
		$config = $this->createMock(IConfig::class);
		$config->method('getUserValue')->willReturnCallback(
			fn (string $uid, string $app, string $key, $default = ''): string => ($this->values["$uid/$app/$key"] ?? $default)
		);
		$config->method('setUserValue')->willReturnCallback(
			function (string $uid, string $app, string $key, $value): void {
				$this->values["$uid/$app/$key"] = (string) $value;
			}
		);
		$config->method('deleteUserValue')->willReturnCallback(
			function (string $uid, string $app, string $key): void {
				unset($this->values["$uid/$app/$key"]);
			}
		);

		$config->method('getAppValue')->willReturnCallback(
			fn (string $app, string $key, $default = ''): string => ($this->app[$key] ?? $default)
		);
		$config->method('setAppValue')->willReturnCallback(
			function (string $app, string $key, $value): void {
				$this->app[$key] = (string) $value;
			}
		);

		return new PersonalTokenGrantStore($config);
	}//end store()

	private static function grant(string $expiresAt = '2027-01-01T00:00:00+00:00'): array {
		return ['verbs' => ['read'], 'schemas' => ['case'], 'expiresAt' => $expiresAt, 'rateLimit' => 30];
	}//end grant()

	public function testATokenWithoutAStoredGrantHasNone(): void {
		$store = $this->store();

		$this->assertNull($store->read('alice', '42'));
		$this->assertNull($store->grantFor('alice', '42'));
	}//end testATokenWithoutAStoredGrantHasNone()

	public function testAWrittenGrantIsKeptUnderItsOwnerAndTokenId(): void {
		$store = $this->store();
		$store->write('alice', '42', self::grant(), 'alice', new DateTimeImmutable('2026-11-16T10:00:00+00:00'));

		$this->assertArrayHasKey('alice/openregister/token_grant_42', $this->values);
		$this->assertNull($store->read('bob', '42'));
		$this->assertNull($store->read('alice', '43'));

		$grant = $store->grantFor('alice', '42');
		$this->assertInstanceOf(TokenGrant::class, $grant);
		$this->assertSame(['read'], $grant->verbs);
		$this->assertSame('app-password:42', $grant->tokenId);
		$this->assertSame(30, $grant->rateLimit);
		$this->assertTrue($grant->permits('read'));
		$this->assertFalse($grant->permits('update'));
	}//end testAWrittenGrantIsKeptUnderItsOwnerAndTokenId()

	public function testTheFirstWriteRecordsTheIssuerAndNoRenewal(): void {
		$record = $this->store()->write('alice', '42', self::grant(), 'alice', new DateTimeImmutable('2026-11-16T10:00:00+00:00'));

		$this->assertSame('alice', $record['issuedBy']);
		$this->assertSame('2026-11-16T10:00:00+00:00', $record['issuedAt']);
		$this->assertSame([], $record['renewals']);
	}//end testTheFirstWriteRecordsTheIssuerAndNoRenewal()

	public function testAChangedEndDateIsRecordedAsARenewalWithItsActor(): void {
		$store = $this->store();
		$store->write('alice', '42', self::grant('2026-12-01T00:00:00+00:00'), 'alice', new DateTimeImmutable('2026-11-16T10:00:00+00:00'));
		$record = $store->write('alice', '42', self::grant('2027-03-01T00:00:00+00:00'), 'alice', new DateTimeImmutable('2026-11-20T09:30:00+00:00'));

		$this->assertSame('2026-11-16T10:00:00+00:00', $record['issuedAt']);
		$this->assertSame(
			[['by' => 'alice', 'at' => '2026-11-20T09:30:00+00:00', 'from' => '2026-12-01T00:00:00+00:00', 'expiresAt' => '2027-03-01T00:00:00+00:00']],
			$record['renewals']
		);
		$this->assertSame($record, $store->read('alice', '42'));
	}//end testAChangedEndDateIsRecordedAsARenewalWithItsActor()

	public function testAnUnchangedEndDateIsNoRenewal(): void {
		$store = $this->store();
		$store->write('alice', '42', self::grant(), 'alice', new DateTimeImmutable('2026-11-16T10:00:00+00:00'));
		$record = $store->write('alice', '42', ['verbs' => ['read', 'update']] + self::grant(), 'alice', new DateTimeImmutable('2026-11-17T10:00:00+00:00'));

		$this->assertSame([], $record['renewals']);
		$this->assertSame(['read', 'update'], $record['grant']['verbs']);
	}//end testAnUnchangedEndDateIsNoRenewal()

	public function testRenewalsKeepOnlyTheMostRecent(): void {
		$store = $this->store();
		$store->write('alice', '42', self::grant('2027-01-00'), 'alice', new DateTimeImmutable('2026-11-16T10:00:00+00:00'));
		for ($i = 1; $i <= (PersonalTokenGrantStore::MAX_RENEWALS + 5); $i++) {
			$record = $store->write('alice', '42', self::grant("2027-01-$i"), 'alice', new DateTimeImmutable('2026-11-16T10:00:00+00:00'));
		}

		$this->assertCount(PersonalTokenGrantStore::MAX_RENEWALS, $record['renewals']);
		$this->assertSame('2027-01-'.(PersonalTokenGrantStore::MAX_RENEWALS + 5), $record['renewals'][(PersonalTokenGrantStore::MAX_RENEWALS - 1)]['expiresAt']);
		$this->assertSame('2027-01-6', $record['renewals'][0]['expiresAt']);
	}//end testRenewalsKeepOnlyTheMostRecent()

	public function testAnUnreadableValueBindsAGrantThatPermitsNothing(): void {
		$this->values['alice/openregister/token_grant_42'] = '{not json';
		$store = $this->store();

		$this->assertSame(['grant' => false], $store->read('alice', '42'));
		$grant = $store->grantFor('alice', '42');
		$this->assertInstanceOf(TokenGrant::class, $grant);
		$this->assertTrue($grant->isEmpty());
		$this->assertFalse($grant->permits('read'));
	}//end testAnUnreadableValueBindsAGrantThatPermitsNothing()

	public function testRemovingTheGrantLeavesTheTokenWithNone(): void {
		$store = $this->store();
		$store->write('alice', '42', self::grant(), 'alice', new DateTimeImmutable('2026-11-16T10:00:00+00:00'));
		$store->remove('alice', '42');

		$this->assertNull($store->grantFor('alice', '42'));
		$this->assertSame([], $this->values);
	}//end testRemovingTheGrantLeavesTheTokenWithNone()

	public function testTheIndexListsEveryGrantedTokenOnceUntilItIsRemoved(): void {
		$store = $this->store();
		$now   = new DateTimeImmutable('2026-11-16T10:00:00+00:00');
		$store->write('alice', '42', self::grant(), 'alice', $now);
		$store->write('alice', '42', self::grant('2027-02-01T00:00:00+00:00'), 'alice', $now);
		$store->write('bob', '42', self::grant(), 'bob', $now);

		$this->assertSame([['uid' => 'alice', 'tokenId' => '42'], ['uid' => 'bob', 'tokenId' => '42']], $store->all());

		$store->remove('alice', '42');

		$this->assertSame([['uid' => 'bob', 'tokenId' => '42']], $store->all());
	}//end testTheIndexListsEveryGrantedTokenOnceUntilItIsRemoved()

	public function testAnUnreadableIndexListsNothing(): void {
		$this->app[PersonalTokenGrantStore::INDEX_KEY] = '{broken';

		$this->assertSame([], $this->store()->all());
	}//end testAnUnreadableIndexListsNothing()
}//end class
