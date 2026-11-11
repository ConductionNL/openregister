<?php

/**
 * Characterisation tests for the deny-block union and the principal names a
 * deny entry may use to reach a caller.
 *
 * Pins `DenyResolver::mergeDeny()` (unset and unreadable blocks, unreadable
 * actions, baseline-then-override order, list re-indexing, de-duplication) and
 * `DenyResolver::principalsFor()` (anonymous, blank and signed-in callers,
 * unusable group ids skipped, duplicates removed, order kept). A deny can only
 * refuse a caller whose principals it names, so these two decide who a deny
 * reaches.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/rbac-scopes/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Rbac;

use OCA\OpenRegister\Service\Rbac\DenyEntryMatcher;
use OCA\OpenRegister\Service\Rbac\DenyResolver;
use PHPUnit\Framework\TestCase;

/**
 * Golden tests for mergeDeny() and principalsFor().
 */
class DenyResolverMergeAndPrincipalsGoldenTest extends TestCase {

	/**
	 * The union keeps both levels, baseline first, and drops repeats.
	 *
	 * @return void
	 */
	public function testMergeDenyUnionsBothLevels(): void {
		$resolver = new DenyResolver(new DenyEntryMatcher());

		$this->assertSame([], $resolver->mergeDeny(null, null));
		$this->assertSame(['read' => ['a']], $resolver->mergeDeny(['read' => ['a']], null));
		$this->assertSame(['read' => ['b']], $resolver->mergeDeny(null, ['read' => [5 => 'b']]));
		$this->assertSame(
			[
				'delete' => ['user:bob', ['group' => 'x', 'match' => ['k' => 'v']], 'public'],
				'read'   => ['a'],
				'update' => ['c'],
			],
			$resolver->mergeDeny(
				[
					'delete' => ['user:bob', ['group' => 'x', 'match' => ['k' => 'v']]],
					'read'   => 'not-a-list',
					'list'   => null,
				],
				[
					'delete' => ['public', 'user:bob', ['group' => 'x', 'match' => ['k' => 'v']]],
					'read'   => ['a', 'a'],
					'update' => ['k' => 'c'],
				]
			)
		);
	}//end testMergeDenyUnionsBothLevels()

	/**
	 * Every caller shape yields the same principal names in the same order.
	 *
	 * @return void
	 */
	public function testPrincipalsForEveryCaller(): void {
		$resolver = new DenyResolver(new DenyEntryMatcher());

		$this->assertSame(['public'], $resolver->principalsFor(null, []));
		$this->assertSame(['public', 'grp'], $resolver->principalsFor('', ['grp']));
		$this->assertSame(['public', 'authenticated', 'user:ana'], $resolver->principalsFor('ana', []));
		$this->assertSame(
			['public', 'authenticated', 'user:ana', 'behandelaars', 'waarnemers'],
			$resolver->principalsFor('ana', ['behandelaars', '', 7, null, 'waarnemers', 'behandelaars', 'public'])
		);
	}//end testPrincipalsForEveryCaller()
}//end class
