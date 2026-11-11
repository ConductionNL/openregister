<?php

/**
 * Tests for declaring a permission verb through its value object.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace Unit\Event;

use OCA\OpenRegister\Event\PermissionsDeclaringEvent;
use OCA\OpenRegister\Event\PermissionVerb;
use PHPUnit\Framework\TestCase;

/**
 * addVerb() accepts and refuses exactly what declareVerb() does.
 */
class PermissionsDeclaringEventAddVerbTest extends TestCase {

	/**
	 * Accepted, refused-as-taken and refused-by-grammar declarations, through both doors.
	 *
	 * @return void
	 */
	public function testAddVerbMatchesDeclareVerbOutcomes(): void {
		$byArgs = new PermissionsDeclaringEvent();
		$byVerb = new PermissionsDeclaringEvent();

		self::assertTrue($byArgs->declareVerb('sign_off', 'appA', 'Sign off', ['schema', 7, 'object']));
		self::assertTrue($byVerb->addVerb(new PermissionVerb('sign_off', 'appA', 'Sign off', ['schema', 7, 'object'])));
		self::assertTrue($byArgs->declareVerb('Read-2', 'appB', 'Second read'));
		self::assertTrue($byVerb->addVerb(new PermissionVerb('Read-2', 'appB', 'Second read')));
		self::assertFalse($byArgs->declareVerb('sign_off', 'appC', 'Steal'));
		self::assertFalse($byVerb->addVerb(new PermissionVerb('sign_off', 'appC', 'Steal')));
		self::assertFalse($byArgs->declareVerb('bad verb', 'appD', 'Space'));
		self::assertFalse($byVerb->addVerb(new PermissionVerb('bad verb', 'appD', 'Space')));

		self::assertSame($byArgs->getDeclared(), $byVerb->getDeclared());
		self::assertSame($byArgs->getRejected(), $byVerb->getRejected());
		self::assertSame(['schema', 'object'], $byVerb->getDeclared()['sign_off']['levels']);
		self::assertSame(PermissionsDeclaringEvent::DEFAULT_LEVELS, $byVerb->getDeclared()['Read-2']['levels']);
		self::assertSame('appA', $byVerb->getDeclared()['sign_off']['app']);
		self::assertArrayHasKey('bad verb', $byVerb->getRejected());
	}//end testAddVerbMatchesDeclareVerbOutcomes()
}//end class
