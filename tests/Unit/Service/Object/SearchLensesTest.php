<?php

/**
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Service\Interaction\ReadHistoryService;
use OCA\OpenRegister\Service\Object\SearchLenses;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * SearchLenses narrows a query to the caller's own lenses, and to nothing for an anonymous caller.
 *
 * @covers \OCA\OpenRegister\Service\Object\SearchLenses
 */
class SearchLensesTest extends TestCase {

	/**
	 * A session for the given user id, or an anonymous one for null.
	 *
	 * @param string|null $uid The user id.
	 *
	 * @return IUserSession
	 */
	private function session(?string $uid): IUserSession {
		$session = $this->createMock(IUserSession::class);
		if ($uid === null) {
			$session->method('getUser')->willReturn(null);
			return $session;
		}

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$session->method('getUser')->willReturn($user);
		return $session;
	}//end session()

	/**
	 * An anonymous caller's owner filter matches nothing, and owner ordering is dropped.
	 *
	 * @return void
	 */
	public function testAnAnonymousOwnerFilterMatchesNothing(): void {
		$query = (new SearchLenses(userSession: $this->session(uid: null), readHistory: null))->apply(
			query: ['@self' => ['owner' => 'noor', 'schema' => 3], '_order' => ['owner' => 'asc', '@self.owner' => 'desc', '_owner' => 'asc', 'title' => 'asc']]
		);

		self::assertSame(['schema' => 3], $query['@self']);
		self::assertSame(['__no-owner-for-anonymous__'], $query['_ids']);
		self::assertSame(['title' => 'asc'], $query['_order']);
	}//end testAnAnonymousOwnerFilterMatchesNothing()

	/**
	 * A signed-in caller keeps their owner filter and owner ordering.
	 *
	 * @return void
	 */
	public function testASignedInCallerKeepsTheOwnerLens(): void {
		$given = ['@self' => ['owner' => 'noor'], '_order' => ['owner' => 'asc']];

		self::assertSame($given, (new SearchLenses(userSession: $this->session(uid: 'noor'), readHistory: null))->apply(query: $given));
	}//end testASignedInCallerKeepsTheOwnerLens()

	/**
	 * Without a session at all the caller is anonymous too; an empty uid as well.
	 *
	 * @return void
	 */
	public function testNoSessionAndAnEmptyUidAreAnonymous(): void {
		$query = ['@self' => ['owner' => 'noor']];

		self::assertSame(['__no-owner-for-anonymous__'], (new SearchLenses(userSession: null, readHistory: null))->apply(query: $query)['_ids']);
		self::assertSame(['__no-owner-for-anonymous__'], (new SearchLenses(userSession: $this->session(uid: ''), readHistory: null))->apply(query: $query)['_ids']);
	}//end testNoSessionAndAnEmptyUidAreAnonymous()

	/**
	 * Without read history the recent lens says it is unavailable and matches nothing.
	 *
	 * @return void
	 */
	public function testWithoutReadHistoryTheRecentLensIsUnavailable(): void {
		$query = (new SearchLenses(userSession: $this->session(uid: 'noor'), readHistory: null))->apply(
			query: ['_recent' => 'true', '_recentFor' => 'someone-else', '_recentViews' => ['x' => 1]]
		);

		self::assertSame(['available' => false, 'reason' => ReadHistoryService::REASON_UNAVAILABLE], $query['_recentLens']);
		self::assertSame(['__no-personal-lens-user__'], $query['_ids']);
		self::assertArrayNotHasKey('_recentFor', $query);
		self::assertArrayNotHasKey('_recentViews', $query);
		self::assertArrayNotHasKey('_recent', $query);
	}//end testWithoutReadHistoryTheRecentLensIsUnavailable()
}//end class
