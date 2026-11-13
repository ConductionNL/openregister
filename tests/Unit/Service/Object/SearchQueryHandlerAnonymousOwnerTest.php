<?php

/**
 * An anonymous caller cannot filter or sort on the owner.
 *
 * The owner is withheld from an anonymous answer, so a filter on it would
 * still tell which records a user owns through the total, and a sort through
 * the order. The filter answers nothing; the sort is dropped.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/row-field-level-security/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\Object;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Object\SearchQueryHandler;
use OCA\OpenRegister\Service\Object\ViewScopeApplier;
use OCA\OpenRegister\Service\SearchTrailService;
use OCA\OpenRegister\Service\SettingsService;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenRegister\Service\Object\SearchQueryHandler
 * @uses \OCA\OpenRegister\Service\Object\DottedParamTree
 * @uses \OCA\OpenRegister\Service\Object\SearchQueryParts
 * @uses \OCA\OpenRegister\Service\Object\SearchReferenceText
 * @uses \OCA\OpenRegister\Service\Object\SearchScopeFilters
 * @uses \OCA\OpenRegister\Service\SearchPeriod
 */
class SearchQueryHandlerAnonymousOwnerTest extends TestCase {

	/**
	 * A handler acting as the given user.
	 *
	 * @param string|null $uid The calling uid, or null for anonymous.
	 *
	 * @return SearchQueryHandler
	 */
	private function makeHandler(?string $uid): SearchQueryHandler {
		$schema = $this->createMock(Schema::class);
		$schema->method('getProperties')->willReturn([]);
		$schema->method('getObjectSource')->willReturn(null);
		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('find')->willReturn($schema);

		$session = $this->createMock(IUserSession::class);
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session->method('getUser')->willReturn($user);

		return new SearchQueryHandler(
			$this->createMock(ViewScopeApplier::class),
			$schemaMapper,
			$this->createMock(SettingsService::class),
			$this->createMock(LoggerInterface::class),
			$this->createMock(SearchTrailService::class),
			$session
		);
	}//end makeHandler()

	public function testAnAnonymousOwnerFilterAnswersNothing(): void {
		$query = $this->makeHandler(uid: null)->buildSearchQuery(['owner' => 'alice'], 1, 2);

		$this->assertArrayNotHasKey('owner', $query['@self']);
		$this->assertSame(['__no-owner-for-anonymous__'], $query['_ids']);
	}//end testAnAnonymousOwnerFilterAnswersNothing()

	public function testAnAnonymousOwnerFilterUnderSelfAnswersNothing(): void {
		$query = $this->makeHandler(uid: null)->buildSearchQuery(['@self' => ['owner' => 'alice']], 1, 2);

		$this->assertArrayNotHasKey('owner', $query['@self']);
		$this->assertSame(['__no-owner-for-anonymous__'], $query['_ids']);
	}//end testAnAnonymousOwnerFilterUnderSelfAnswersNothing()

	public function testAnAnonymousOwnerSortIsDropped(): void {
		$query = $this->makeHandler(uid: null)->buildSearchQuery(
			['_order' => ['@self.owner' => 'ASC', 'owner' => 'DESC', 'title' => 'ASC']],
			1,
			2
		);

		$this->assertSame(['title' => 'ASC'], $query['_order']);
		$this->assertArrayNotHasKey('_ids', $query);
	}//end testAnAnonymousOwnerSortIsDropped()

	public function testASignedInCallerStillFiltersAndSortsOnTheOwner(): void {
		$query = $this->makeHandler(uid: 'bob')->buildSearchQuery(
			['owner' => 'alice', '_order' => ['@self.owner' => 'ASC']],
			1,
			2
		);

		$this->assertSame('alice', $query['@self']['owner']);
		$this->assertSame(['@self.owner' => 'ASC'], $query['_order']);
		$this->assertArrayNotHasKey('_ids', $query);
	}//end testASignedInCallerStillFiltersAndSortsOnTheOwner()
}//end class
