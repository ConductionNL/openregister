<?php

/**
 * Unit tests for AccessLinkSubjectLinks.
 *
 * Whoever may publish a subject sees and revokes every link on it; anybody else
 * gets nothing, and a link somebody else minted is listed without its anchor.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Sharing
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/access-links-managed-per-subject/specs/public-access-links/spec.md#requirement-whoever-may-publish-a-subject-sees-and-revokes-every-link-on-it-req-abl-005
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Sharing;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.
// phpcs:disable Squiz.Commenting.VariableComment.Missing -- typed mock fixtures; the declaration IS the description.

use DateTime;
use OCA\OpenRegister\Db\AccessLink;
use OCA\OpenRegister\Db\AccessLinkMapperInterface;
use OCA\OpenRegister\Service\Sharing\AccessLinkMintGuardInterface;
use OCA\OpenRegister\Service\Sharing\AccessLinkServiceInterface;
use OCA\OpenRegister\Service\Sharing\AccessLinkSubjectLinks;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the per-subject listing and revocation of access links.
 */
class AccessLinkSubjectLinksTest extends TestCase {

	private AccessLinkMapperInterface&MockObject $mapper;
	private AccessLinkMintGuardInterface&MockObject $mintGuard;
	private AccessLinkSubjectLinks $subjectLinks;

	protected function setUp(): void {
		parent::setUp();

		$this->mapper = $this->createMock(AccessLinkMapperInterface::class);
		$this->mintGuard = $this->createMock(AccessLinkMintGuardInterface::class);
		$links = $this->createMock(AccessLinkServiceInterface::class);
		$links->method('linkUrl')->willReturnCallback(static fn (string $anchor): string => 'https://nc.example.org/api/public/links/' . $anchor);
		$links->method('pageUrl')->willReturnCallback(static fn (string $anchor): string => 'https://nc.example.org/links/' . $anchor);
		$this->mapper->method('update')->willReturnArgument(0);

		$this->subjectLinks = new AccessLinkSubjectLinks(mapper: $this->mapper, mintGuard: $this->mintGuard, links: $links);
	}

	private function link(string $createdBy, string $subjectId = 'view-uuid'): AccessLink {
		$link = new AccessLink();
		$link->setAnchor('AnchorOf' . $createdBy);
		$link->setSubjectType(AccessLink::SUBJECT_VIEW);
		$link->setSubjectId($subjectId);
		$link->setCapabilities('read');
		$link->setCreatedBy($createdBy);
		$link->setExpiresAt(new DateTime('+1 day'));
		$link->setDisabled(false);
		$link->setUseCount(3);

		return $link;
	}

	public function testAPublisherSeesEveryLinkAndOnlyTheAnchorsOfTheirOwn(): void {
		$this->mintGuard->method('mayMint')->with('view', 'view-uuid')->willReturn(true);
		$this->mapper->expects($this->once())
			->method('findBySubject')
			->with('view', 'view-uuid')
			->willReturn([$this->link(createdBy: 'member'), $this->link(createdBy: 'manager')]);

		$rows = $this->subjectLinks->listOn(subjectType: 'view', subjectId: 'view-uuid', userId: ' manager ');

		$this->assertCount(2, $rows);
		$this->assertSame('member', $rows[0]['createdBy']);
		$this->assertSame(3, $rows[0]['useCount']);
		$this->assertArrayNotHasKey('anchor', $rows[0]);
		$this->assertArrayNotHasKey('url', $rows[0]);
		$this->assertArrayNotHasKey('pageUrl', $rows[0]);
		$this->assertSame('AnchorOfmanager', $rows[1]['anchor']);
		$this->assertSame('https://nc.example.org/links/AnchorOfmanager', $rows[1]['pageUrl']);
		$this->assertSame('https://nc.example.org/api/public/links/AnchorOfmanager', $rows[1]['url']);
	}

	public function testACallerWhoMayNotPublishTheSubjectGetsNothing(): void {
		$this->mintGuard->method('mayMint')->willReturn(false);
		$this->mapper->expects($this->never())->method('findBySubject');

		$this->assertNull($this->subjectLinks->listOn(subjectType: 'view', subjectId: 'view-uuid', userId: 'outsider'));
	}

	public function testAnEmptyPrincipalSeesNoAnchorAtAll(): void {
		$this->mintGuard->method('mayMint')->willReturn(true);
		$this->mapper->method('findBySubject')->willReturn([$this->link(createdBy: '')]);

		$rows = $this->subjectLinks->listOn(subjectType: 'view', subjectId: 'view-uuid', userId: '');

		$this->assertArrayNotHasKey('anchor', $rows[0]);
	}

	public function testAPublisherRevokesALinkSomebodyElseMinted(): void {
		$link = $this->link(createdBy: 'member');
		$this->mintGuard->method('mayMint')->willReturn(true);
		$this->mapper->method('findById')->with(4)->willReturn($link);
		$this->mapper->expects($this->once())->method('update');

		$this->assertTrue($this->subjectLinks->revokeOn(id: 4, subjectType: 'view', subjectId: 'view-uuid'));
		$this->assertNotNull($link->getRevokedAt());
	}

	public function testRevokingTwiceKeepsTheFirstStamp(): void {
		$link = $this->link(createdBy: 'member');
		$stamp = new DateTime('-1 hour');
		$link->setRevokedAt($stamp);
		$this->mintGuard->method('mayMint')->willReturn(true);
		$this->mapper->method('findById')->willReturn($link);
		$this->mapper->expects($this->never())->method('update');

		$this->assertTrue($this->subjectLinks->revokeOn(id: 4, subjectType: 'view', subjectId: 'view-uuid'));
		$this->assertSame($stamp, $link->getRevokedAt());
	}

	public function testALinkOnAnotherSubjectStaysLive(): void {
		$link = $this->link(createdBy: 'member', subjectId: 'view-b');
		$this->mintGuard->method('mayMint')->willReturn(true);
		$this->mapper->method('findById')->willReturn($link);
		$this->mapper->expects($this->never())->method('update');

		$this->assertFalse($this->subjectLinks->revokeOn(id: 4, subjectType: 'view', subjectId: 'view-a'));
		$this->assertFalse($this->subjectLinks->revokeOn(id: 4, subjectType: 'object', subjectId: 'view-b'));
		$this->assertNull($link->getRevokedAt());
	}

	public function testACallerWhoMayNotPublishTheSubjectRevokesNothing(): void {
		$this->mintGuard->method('mayMint')->willReturn(false);
		$this->mapper->expects($this->never())->method('findById');

		$this->assertFalse($this->subjectLinks->revokeOn(id: 4, subjectType: 'view', subjectId: 'view-uuid'));
	}

	public function testRevokingWithoutASubjectDoesNotAskTheGuard(): void {
		$this->mintGuard->expects($this->never())->method('mayMint');

		$this->assertFalse($this->subjectLinks->revokeOn(id: 4, subjectType: '', subjectId: ''));
	}

	public function testAnUnknownLinkIsNotRevoked(): void {
		$this->mintGuard->method('mayMint')->willReturn(true);
		$this->mapper->method('findById')->willReturn(null);

		$this->assertFalse($this->subjectLinks->revokeOn(id: 9, subjectType: 'view', subjectId: 'view-uuid'));
	}
}//end class
