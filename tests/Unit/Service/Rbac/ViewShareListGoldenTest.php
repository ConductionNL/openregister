<?php

/**
 * Golden test for how ViewShareResolver reads a view's share list.
 *
 * Pins which entries of a share list grant anything (allowed) and which are
 * dropped (refused), in every stored shape, so the reading can be split
 * without changing who a view is shared with.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
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
 * @spec openspec/specs/saved-search-views/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Rbac;

use OCA\OpenRegister\Service\Rbac\ViewerReach;
use OCA\OpenRegister\Service\Rbac\ViewShareResolver;
use PHPUnit\Framework\TestCase;

/**
 * Allowed and refused share entries, pinned.
 */
class ViewShareListGoldenTest extends TestCase {

	/**
	 * A view owned by alice with this share list.
	 *
	 * @param mixed $sharedWith The stored share list.
	 *
	 * @return array<string, mixed> The view.
	 */
	private static function view(mixed $sharedWith): array {
		return ['owner' => 'alice', 'isPublic' => false, 'sharedWith' => $sharedWith];
	}//end view()

	public function testOnlyWellFormedEntriesShareTheView(): void {
		$resolver = new ViewShareResolver();
		$view = self::view(
			[
				['group' => ' vth ', 'mode' => ' write '],
				['user' => ' bob ', 'mode' => 'read'],
				['group' => 'both', 'user' => 'carol', 'mode' => 'read'],
				['mode' => 'read'],
				['group' => 'typo', 'mode' => 'admin'],
				'not-an-object',
				['group' => 'vth', 'mode' => 'readonly'],
				['group' => 'vth', 'mode' => 'read'],
				['group' => 'milieu', 'mode' => 'read'],
			]
		);

		$this->assertSame(['vth', 'milieu'], $resolver->shareGroups($view));
		$this->assertSame(ViewShareResolver::ACCESS_WRITE, $resolver->accessOf($view, new ViewerReach('dave', ['vth'], false)));
		$this->assertSame(ViewShareResolver::ACCESS_READ, $resolver->accessOf($view, new ViewerReach('bob', [], false)));
		$this->assertNull($resolver->accessOf($view, new ViewerReach('carol', ['both', 'typo'], false)));
	}//end testOnlyWellFormedEntriesShareTheView()

	public function testEveryStoredShapeOfTheList(): void {
		$resolver = new ViewShareResolver();

		$this->assertSame(['vth'], $resolver->shareGroups(self::view('[{"group":"vth","mode":"read"}]')));
		$this->assertSame([], $resolver->shareGroups(self::view('not json')));
		$this->assertSame([], $resolver->shareGroups(self::view('"a string"')));
		$this->assertSame([], $resolver->shareGroups(self::view(null)));
		$this->assertSame([], $resolver->shareGroups(self::view(7)));
		$this->assertSame([], $resolver->shareGroups(['owner' => 'alice']));
	}//end testEveryStoredShapeOfTheList()
}//end class
