<?php

/**
 * Unit test for ExampleSetGrantIdentity: what makes a share the one a grant made.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\ExampleSet
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/changes/example-set-access-links-and-grants/specs/example-sets/spec.md#requirement-a-fragment-may-declare-grants-on-its-objects
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ExampleSet;

use OCA\OpenRegister\Service\ExampleSet\ExampleSetGrantIdentity;
use OCP\Share\IShare;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetGrantIdentity
 */
class ExampleSetGrantIdentityTest extends TestCase {
	/**
	 * The entry the record keeps reads back as the identity of the share it names; another share does not match.
	 *
	 * @return void
	 */
	public function testTheRecordedIdentityMatchesOnlyTheShareItNames(): void {
		$identity = new ExampleSetGrantIdentity();
		$entry    = $identity->entry(uuid: 'obj-1', grant: ['id' => 12, 'nodeId' => '40', 'sharedBy' => 'alice', 'sharedWith' => 'bob', 'shareType' => 0]);
		$this->assertSame(['id' => '12', 'object' => 'obj-1', 'node' => 40, 'sharedBy' => 'alice', 'sharedWith' => 'bob', 'shareType' => 0], $entry);

		$share = $this->createMock(IShare::class);
		$share->method('getNodeId')->willReturn(40);
		$share->method('getSharedBy')->willReturn('alice');
		$share->method('getSharedWith')->willReturn('bob');
		$share->method('getShareType')->willReturn(0);
		$this->assertSame($identity->recorded(entry: $entry), $identity->ofShare(share: $share));
		$this->assertNotSame($identity->recorded(entry: ['sharedWith' => 'eve'] + $entry), $identity->ofShare(share: $share));
		$this->assertSame(['node' => 40, 'sharedBy' => '', 'sharedWith' => '', 'shareType' => -1], $identity->recorded(entry: ['node' => '40']));
	}//end testTheRecordedIdentityMatchesOnlyTheShareItNames()
}//end class
