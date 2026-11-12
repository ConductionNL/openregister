<?php

/**
 * Tests for RoleActionMap.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Rbac;

use OCA\OpenRegister\Service\Rbac\RoleActionMap;
use PHPUnit\Framework\TestCase;

/**
 * Role name => actions, from either definition shape.
 *
 * @covers \OCA\OpenRegister\Service\Rbac\RoleActionMap
 */
class RoleActionMapTest extends TestCase {

	/**
	 * Non-array definitions map to nothing.
	 *
	 * @return void
	 */
	public function testNonArrayDefinitionsMapToNothing(): void {
		$this->assertSame([], (new RoleActionMap())->of(roleDefinitions: 'viewer'));
		$this->assertSame([], (new RoleActionMap())->of(roleDefinitions: null));
	}//end testNonArrayDefinitionsMapToNothing()

	/**
	 * Both the list-of-objects and the keyed-map shapes are read; non-string actions,
	 * nameless list entries and scalar definitions are dropped.
	 *
	 * @return void
	 */
	public function testBothShapesAreRead(): void {
		$this->assertSame(
			[
				'viewer' => ['read'],
				'editor' => ['read', 'update'],
				'remover' => ['delete'],
			],
			(new RoleActionMap())->of(
				roleDefinitions: [
					['name' => 'viewer', 'actions' => ['read', 5, null]],
					['name' => 'ghost', 'actions' => 'read'],
					['actions' => ['create']],
					'scalar',
					'editor' => ['read', 'update', ['nested']],
					'remover' => ['name' => 'remover', 'actions' => ['delete']],
				]
			)
		);
	}//end testBothShapesAreRead()
}//end class
