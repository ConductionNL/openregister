<?php

/**
 * Tests for DenyEntryMatcher.
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

use OCA\OpenRegister\Service\Rbac\DenyEntryMatcher;
use OCA\OpenRegister\Service\Rbac\DenyQuestion;
use PHPUnit\Framework\TestCase;

/**
 * Which deny entries name the caller, and how the match is reported.
 *
 * @covers \OCA\OpenRegister\Service\Rbac\DenyEntryMatcher
 */
class DenyEntryMatcherTest extends TestCase {

	private const PRINCIPALS = ['staff', 'mcp', 'user:alice'];

	/**
	 * String entries name a principal only on an exact match, and the `mcp` scope never names one.
	 *
	 * @return void
	 */
	public function testStringEntries(): void {
		$matcher = new DenyEntryMatcher();

		$this->assertSame('staff', $matcher->entryNames(entry: 'staff', principals: self::PRINCIPALS));
		$this->assertNull($matcher->entryNames(entry: 'Staff', principals: self::PRINCIPALS));
		$this->assertNull($matcher->entryNames(entry: 'mcp', principals: self::PRINCIPALS));
		$this->assertNull($matcher->entryNames(entry: 42, principals: self::PRINCIPALS));
	}//end testStringEntries()

	/**
	 * Array entries name a group or a prefixed user; empty users, `mcp` groups and
	 * unknown principals name nobody.
	 *
	 * @return void
	 */
	public function testArrayEntries(): void {
		$matcher = new DenyEntryMatcher();

		$this->assertSame('staff', $matcher->entryNames(entry: ['group' => 'staff', 'user' => 'alice'], principals: self::PRINCIPALS));
		$this->assertSame('user:alice', $matcher->entryNames(entry: ['group' => 'hr', 'user' => 'alice'], principals: self::PRINCIPALS));
		$this->assertNull($matcher->entryNames(entry: ['group' => 'mcp'], principals: self::PRINCIPALS));
		$this->assertNull($matcher->entryNames(entry: ['user' => ''], principals: ['user:']));
		$this->assertNull($matcher->entryNames(entry: ['user' => 'bob', 'group' => 7], principals: self::PRINCIPALS));
		$this->assertNull($matcher->entryNames(entry: [], principals: self::PRINCIPALS));
	}//end testArrayEntries()

	/**
	 * Only entries naming the caller are reported, conditional when they carry a non-empty match.
	 *
	 * @return void
	 */
	public function testMatchingDenials(): void {
		$conditional = ['group' => 'staff', 'match' => ['status' => 'draft']];
		$emptyMatch  = ['user' => 'alice', 'match' => []];
		$scalarMatch = ['user' => 'alice', 'match' => 'x', 'group' => 'none'];

		$this->assertSame(
			[
				['rule' => 'staff', 'principal' => 'staff', 'action' => 'delete', 'conditional' => false],
				['rule' => $conditional, 'principal' => 'staff', 'action' => 'delete', 'conditional' => true],
				['rule' => $emptyMatch, 'principal' => 'user:alice', 'action' => 'delete', 'conditional' => false],
				['rule' => $scalarMatch, 'principal' => 'user:alice', 'action' => 'delete', 'conditional' => false],
			],
			(new DenyEntryMatcher())->matchingDenials(
				entries: ['staff', 'hr', $conditional, ['group' => 'hr'], $emptyMatch, $scalarMatch],
				question: new DenyQuestion(action: 'delete', principals: self::PRINCIPALS)
			)
		);
	}//end testMatchingDenials()

	/**
	 * No entries, no denials.
	 *
	 * @return void
	 */
	public function testNoEntriesNoDenials(): void {
		$this->assertSame([], (new DenyEntryMatcher())->matchingDenials(entries: [], question: new DenyQuestion(action: 'read', principals: self::PRINCIPALS)));
	}//end testNoEntriesNoDenials()
}//end class
