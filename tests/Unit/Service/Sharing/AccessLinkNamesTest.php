<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A `link:<uuid>` principal names the label of that link; anything else names none.
 *
 * @spec openspec/changes/notifications-new-notes-and-referrers/specs/notificatie-engine/spec.md#requirement-a-rule-can-fire-when-a-note-is-added
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Sharing;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- PHPUnit arrange/act/assert conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Db\AccessLink;
use OCA\OpenRegister\Db\AccessLinkMapperInterface;
use OCA\OpenRegister\Service\Sharing\AccessLinkNames;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AccessLinkNames::class)]
#[UsesClass(AccessLink::class)]
class AccessLinkNamesTest extends TestCase {

	public function testALinkPrincipalGivesTheLinksLabel(): void {
		$link = new AccessLink();
		$link->setLabel('Planner');
		$links = $this->createMock(AccessLinkMapperInterface::class);
		$links->expects($this->once())->method('findByUuid')->with('abc-123')->willReturn($link);

		$this->assertSame('Planner', (new AccessLinkNames($links))->linkName(AccessLink::PRINCIPAL_PREFIX . 'abc-123'));
	}

	public function testAnUnknownLinkGivesNull(): void {
		$links = $this->createMock(AccessLinkMapperInterface::class);
		$links->method('findByUuid')->willReturn(null);

		$this->assertNull((new AccessLinkNames($links))->linkName(AccessLink::PRINCIPAL_PREFIX . 'gone'));
	}

	public function testAnIdThatIsNoLinkPrincipalIsNotLookedUp(): void {
		$links = $this->createMock(AccessLinkMapperInterface::class);
		$links->expects($this->never())->method('findByUuid');

		$this->assertNull((new AccessLinkNames($links))->linkName('planner'));
	}
}
