<?php

/**
 * A bare stored name is tried under every registered principal type, and the types that resolve it are reported.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow\Principal;

use OCA\OpenRegister\Service\Flow\Principal\PrincipalKinds;
use OCA\OpenRegister\Service\Flow\Principal\PrincipalReference;
use OCA\OpenRegister\Service\Flow\Principal\PrincipalResolverRegistryInterface;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Flow\Principal\PrincipalKinds
 * @uses   \OCA\OpenRegister\Service\Flow\Principal\PrincipalReference
 */
final class PrincipalKindsTest extends TestCase {

	/**
	 * A registry of three types where `user` and `role` know `jansen`, and `group` knows nobody.
	 *
	 * @param array<int, PrincipalReference> $asked Collects every reference resolved.
	 *
	 * @return PrincipalResolverRegistryInterface The registry.
	 */
	private function registry(array &$asked): PrincipalResolverRegistryInterface {
		$registry = $this->createMock(PrincipalResolverRegistryInterface::class);
		$registry->method('types')->willReturn(['group', 'role', 'user']);
		$registry->method('resolve')->willReturnCallback(
			static function (PrincipalReference $reference) use (&$asked): array {
				$asked[] = $reference;
				$known = ['user' => ['jansen' => ['jansen']], 'role' => ['jansen' => ['jansen', 'pietersen']]];
				return $known[$reference->type][$reference->id] ?? [];
			}
		);

		return $registry;
	}//end registry()

	/**
	 * Only the types that resolve the name are reported, in the registry's order.
	 *
	 * @return void
	 */
	public function testReportsTheTypesThatResolveTheName(): void {
		$asked = [];
		$kinds = new PrincipalKinds(principals: $this->registry($asked));

		self::assertSame(['role', 'user'], $kinds->kindsAnsweringTo(name: 'jansen'));
		self::assertSame(
			[['group', 'jansen'], ['role', 'jansen'], ['user', 'jansen']],
			array_map(static fn (PrincipalReference $r): array => [$r->type, $r->id], $asked)
		);
	}//end testReportsTheTypesThatResolveTheName()

	/**
	 * A name nobody resolves yields no types.
	 *
	 * @return void
	 */
	public function testANameNoTypeResolvesYieldsNothing(): void {
		$asked = [];
		$kinds = new PrincipalKinds(principals: $this->registry($asked));

		self::assertSame([], $kinds->kindsAnsweringTo(name: 'onbekend'));
		self::assertCount(3, $asked);
	}//end testANameNoTypeResolvesYieldsNothing()

	/**
	 * A blank name makes no reference, so nothing is resolved and nothing is reported.
	 *
	 * @return void
	 */
	public function testABlankNameIsNeverResolved(): void {
		$asked = [];
		$kinds = new PrincipalKinds(principals: $this->registry($asked));

		self::assertSame([], $kinds->kindsAnsweringTo(name: '   '));
		self::assertSame([], $asked);
	}//end testABlankNameIsNeverResolved()
}//end class
