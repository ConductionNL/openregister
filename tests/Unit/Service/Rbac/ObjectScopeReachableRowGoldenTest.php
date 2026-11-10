<?php

/**
 * Golden: the "reachable at all" predicate, granted and not granted.
 *
 * Pins the exact SQL the private-scope disjunct produces, so a change to how
 * its parts are passed cannot widen or narrow which rows a caller reaches.
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
 * @link https://conduction.nl
 *
 * @spec openspec/specs/rbac-scopes/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Rbac;

use OCA\OpenRegister\Service\Rbac\AuthorizationColumn;
use OCA\OpenRegister\Service\Rbac\GrantedRows;
use OCA\OpenRegister\Service\Rbac\ObjectScopeResolver;
use PHPUnit\Framework\TestCase;

/**
 * Golden SQL for the reachable-row predicate.
 *
 * @covers \OCA\OpenRegister\Service\Rbac\ObjectScopeResolver
 */
class ObjectScopeReachableRowGoldenTest extends TestCase {

	/**
	 * The resolver under test.
	 *
	 * @var ObjectScopeResolver
	 */
	private ObjectScopeResolver $resolver;

	/**
	 * Build the resolver.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->resolver = new ObjectScopeResolver();
	}//end setUp()

	/**
	 * A private-by-default schema with grants: the exact MySQL predicate.
	 *
	 * @return void
	 */
	public function testAGrantedPrivateRowPredicateIsPinned(): void {
		$this->assertSame(
			"((t._authorization IS NOT NULL AND COALESCE((JSON_UNQUOTE(JSON_EXTRACT(t._authorization, '$.scope'))) = 'organisation', FALSE)) OR t._uuid IN ('u-1', 'u-2'))",
			$this->reachable(defaultPrivate: true, isPostgres: false, quotedUuids: ["'u-1'", "'u-2'"])
		);
	}//end testAGrantedPrivateRowPredicateIsPinned()

	/**
	 * Without grants the predicate is exactly the not-private term, on both platforms.
	 *
	 * @return void
	 */
	public function testWithoutGrantsTheNotPrivateTermStandsAlone(): void {
		foreach ([true, false] as $isPostgres) {
			foreach ([true, false] as $defaultPrivate) {
				$this->assertSame(
					$this->resolver->notPrivateSql(new AuthorizationColumn('t._authorization', $isPostgres), $defaultPrivate),
					$this->reachable(defaultPrivate: $defaultPrivate, isPostgres: $isPostgres, quotedUuids: [])
				);
			}
		}
	}//end testWithoutGrantsTheNotPrivateTermStandsAlone()

	/**
	 * With grants the uuid list is OR-ed beside the not-private term, on both platforms.
	 *
	 * Mutation check: dropping the grants flips the predicate back to the bare
	 * term, so the disjunct is what the uuids add and nothing else.
	 *
	 * @return void
	 */
	public function testGrantsAreOrEdBesideTheNotPrivateTerm(): void {
		foreach ([true, false] as $isPostgres) {
			$notPrivate = $this->resolver->notPrivateSql(new AuthorizationColumn('t._authorization', $isPostgres), true);

			$this->assertSame(
				'(' . $notPrivate . " OR t._uuid IN ('u-1'))",
				$this->reachable(defaultPrivate: true, isPostgres: $isPostgres, quotedUuids: ["'u-1'"])
			);
			$this->assertNotSame(
				$this->reachable(defaultPrivate: true, isPostgres: $isPostgres, quotedUuids: ["'u-1'"]),
				$this->reachable(defaultPrivate: true, isPostgres: $isPostgres, quotedUuids: [])
			);
		}
	}//end testGrantsAreOrEdBesideTheNotPrivateTerm()

	/**
	 * The reachable-row predicate for `t._authorization` / `t._uuid`.
	 *
	 * @param bool     $defaultPrivate Whether the schema is private by default.
	 * @param bool     $isPostgres     Whether the platform is PostgreSQL.
	 * @param string[] $quotedUuids    Granted uuids, quoted.
	 *
	 * @return string The predicate.
	 */
	private function reachable(bool $defaultPrivate, bool $isPostgres, array $quotedUuids): string {
		return $this->resolver->notPrivateOrGrantedSql(
			notPrivateSql: $this->resolver->notPrivateSql(column: new AuthorizationColumn(name: 't._authorization', isPostgres: $isPostgres), defaultPrivate: $defaultPrivate),
			granted: new GrantedRows(uuidColumn: 't._uuid', quotedUuids: $quotedUuids)
		);
	}//end reachable()
}//end class
