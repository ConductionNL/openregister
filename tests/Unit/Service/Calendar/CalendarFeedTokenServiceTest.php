<?php

/**
 * Unit tests for CalendarFeedTokenService.
 *
 * A feed token is a credential, so the tests that matter are the refusals:
 * a revoked token and an expired token both resolve to nothing, and a token
 * somebody else owns cannot be revoked by this caller.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Calendar
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Calendar;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.
// phpcs:disable Squiz.Commenting.VariableComment.Missing -- typed mock fixtures; the declaration IS the description.

use DateTime;
use InvalidArgumentException;
use OCA\OpenRegister\Db\CalendarFeedToken;
use OCA\OpenRegister\Db\CalendarFeedTokenMapper;
use OCA\OpenRegister\Service\Calendar\CalendarFeedTokenService;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 */
class CalendarFeedTokenServiceTest extends TestCase {

	private CalendarFeedTokenMapper&MockObject $mapper;
	private ISecureRandom&MockObject $secureRandom;
	private IUserSession&MockObject $userSession;
	private IURLGenerator&MockObject $urlGenerator;
	private CalendarFeedTokenService $service;

	protected function setUp(): void {
		parent::setUp();

		$this->mapper = $this->createMock(CalendarFeedTokenMapper::class);
		$this->secureRandom = $this->createMock(ISecureRandom::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->urlGenerator = $this->createMock(IURLGenerator::class);

		$this->secureRandom->method('generate')->willReturn('opaque-token-value');
		$this->urlGenerator->method('linkToRoute')->willReturn('/index.php/apps/openregister/api/public/calendar-feeds/opaque-token-value.ics');
		$this->urlGenerator->method('getAbsoluteURL')->willReturnCallback(
			static fn (string $path): string => 'https://nc.example.org' . $path
		);

		$this->service = new CalendarFeedTokenService(
			mapper: $this->mapper,
			secureRandom: $this->secureRandom,
			userSession: $this->userSession,
			urlGenerator: $this->urlGenerator
		);
	}

	private function signedIn(string $uid = 'caseworker'): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);
	}

	private function tokenRow(string $owner = 'caseworker'): CalendarFeedToken {
		$row = new CalendarFeedToken();
		$row->setId(5);
		$row->setToken('opaque-token-value');
		$row->setUserId($owner);
		$row->setScopeType(CalendarFeedToken::SCOPE_SCHEMA);
		$row->setScopeId('9');
		$row->setCreatedAt(new DateTime('2026-09-01T09:00:00+02:00'));

		return $row;
	}

	public function testMintingBindsTheTokenToTheCallerAndReturnsItsUrl(): void {
		$this->signedIn();
		$this->mapper->method('insert')->willReturnCallback(
			static fn (CalendarFeedToken $row): CalendarFeedToken => $row
		);

		$minted = $this->service->mint(scopeType: 'schema', scopeId: '9', label: 'Mijn termijnen');

		$this->assertSame('caseworker', $minted['userId']);
		$this->assertSame('schema', $minted['scopeType']);
		$this->assertSame('9', $minted['scopeId']);
		$this->assertSame('Mijn termijnen', $minted['label']);
		$this->assertStringContainsString('/calendar-feeds/opaque-token-value.ics', $minted['url']);
	}

	public function testAnAnonymousCallerCannotMint(): void {
		$this->userSession->method('getUser')->willReturn(null);

		$this->expectException(InvalidArgumentException::class);

		$this->service->mint(scopeType: 'schema', scopeId: '9');
	}

	public function testAnUnknownScopeTypeIsRefused(): void {
		$this->signedIn();

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessageMatches('/is not a feed scope/');

		$this->service->mint(scopeType: 'register', scopeId: '9');
	}

	public function testALiveTokenResolves(): void {
		$this->mapper->method('findByToken')->willReturn($this->tokenRow());

		$this->assertNotNull($this->service->resolve(token: 'opaque-token-value'));
	}

	public function testARevokedTokenResolvesToNothing(): void {
		$row = $this->tokenRow();
		$row->setRevokedAt(new DateTime('2026-09-10T09:00:00+02:00'));
		$this->mapper->method('findByToken')->willReturn($row);

		$this->assertNull($this->service->resolve(token: 'opaque-token-value'));
	}

	public function testAnExpiredTokenResolvesToNothing(): void {
		$row = $this->tokenRow();
		$row->setExpiresAt(new DateTime('2026-09-02T09:00:00+02:00'));
		$this->mapper->method('findByToken')->willReturn($row);

		$this->assertNull($this->service->resolve(token: 'opaque-token-value'));
	}

	public function testAnUnknownTokenResolvesToNothing(): void {
		$this->mapper->method('findByToken')->willReturn(null);

		$this->assertNull($this->service->resolve(token: 'never-minted'));
	}

	public function testAnEmptyTokenIsNotEvenLookedUp(): void {
		$this->mapper->expects($this->never())->method('findByToken');

		$this->assertNull($this->service->resolve(token: '   '));
	}

	public function testRevokingStampsTheRow(): void {
		$this->signedIn();
		$row = $this->tokenRow();
		$this->mapper->method('findById')->willReturn($row);
		$this->mapper->expects($this->once())->method('update')->with($row);

		$this->assertTrue($this->service->revoke(id: 5));
		$this->assertNotNull($row->getRevokedAt());
	}

	public function testATokenSomebodyElseOwnsCannotBeRevoked(): void {
		$this->signedIn('caseworker');
		$this->mapper->method('findById')->willReturn($this->tokenRow(owner: 'someone-else'));
		$this->mapper->expects($this->never())->method('update');

		$this->assertFalse($this->service->revoke(id: 5));
	}

	public function testListingOnlyAnswersForTheSignedInPrincipal(): void {
		$this->signedIn();
		$this->mapper->expects($this->once())
			->method('findByUser')
			->with('caseworker')
			->willReturn([$this->tokenRow()]);

		$rows = $this->service->listForCurrentUser();

		$this->assertCount(1, $rows);
		$this->assertStringContainsString('calendar-feeds', $rows[0]['url']);
	}

	public function testAnAnonymousCallerListsNothing(): void {
		$this->userSession->method('getUser')->willReturn(null);

		$this->assertSame([], $this->service->listForCurrentUser());
	}
}
