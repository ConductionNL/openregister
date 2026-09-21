<?php

/**
 * Who may see a saved view, and what they may do to it (ledger row 9.4).
 *
 * A view was private or it was everyone's. A department view is the thing in
 * between, and every test here is a way that middle could be wrong while the
 * grid still shows a department with a tick beside it:
 *
 *  - 🔴 `write` read as OWNER. A member who may change what a view SHOWS must
 *    not be able to change who else sees it, who owns it, or whether it
 *    exists. A guard written as `if (canWrite) { save($everything); }` lets a
 *    member hand themselves the view by rewriting `owner`, or lock the owner
 *    out by rewriting `sharedWith`, and the audit shows a legitimate update;
 *  - an unreadable share granting READ rather than nothing, which admits
 *    somebody on a typo;
 *  - a share on a group that does not exist being stored, which makes the view
 *    narrower than its own screen says;
 *  - two shares with one group, where which wins depends on storage order;
 *  - the public flag or the owner being lost in the precedence.
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
 * @spec openspec/changes/view-group-share/specs/saved-search-views/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\Rbac;

use OCA\OpenRegister\Service\Rbac\ViewShareResolver;
use PHPUnit\Framework\TestCase;

/**
 * Pins the access levels, the field restriction and the share validation.
 */
class ViewShareResolverTest extends TestCase {

	private ViewShareResolver $resolver;

	/**
	 * Set up the resolver.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->resolver = new ViewShareResolver();
	}//end setUp()

	/**
	 * One view.
	 *
	 * @param array<string, mixed> $overrides Fields to set.
	 *
	 * @return array<string, mixed> The view.
	 */
	private function view(array $overrides = []): array {
		return array_merge(
			['owner' => 'alice', 'isPublic' => false, 'sharedWith' => []],
			$overrides
		);
	}//end view()

	/**
	 * The owner holds owner access.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/view-group-share/specs/saved-search-views/spec.md
	 */
	public function testTheOwnerHoldsOwnerAccess(): void {
		$this->assertSame(
			ViewShareResolver::ACCESS_OWNER,
			$this->resolver->accessFor($this->view(), 'alice', [])
		);
	}//end testTheOwnerHoldsOwnerAccess()

	/**
	 * A member of a shared group holds the share's mode.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/view-group-share/specs/saved-search-views/spec.md
	 */
	public function testAMemberHoldsTheSharesMode(): void {
		$view = $this->view(['sharedWith' => [['group' => 'vth', 'mode' => 'read']]]);
		$this->assertSame(
			ViewShareResolver::ACCESS_READ,
			$this->resolver->accessFor($view, 'bob', ['vth'])
		);

		$view = $this->view(['sharedWith' => [['group' => 'vth', 'mode' => 'write']]]);
		$this->assertSame(
			ViewShareResolver::ACCESS_WRITE,
			$this->resolver->accessFor($view, 'bob', ['vth'])
		);
	}//end testAMemberHoldsTheSharesMode()

	/**
	 * Two shares are ways in, not ceilings on each other.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/view-group-share/specs/saved-search-views/spec.md
	 */
	public function testTheWidestShareWins(): void {
		$view = $this->view(
			[
				'sharedWith' => [
					['group' => 'readers', 'mode' => 'read'],
					['group' => 'writers', 'mode' => 'write'],
				],
			]
		);

		$this->assertSame(
			ViewShareResolver::ACCESS_WRITE,
			$this->resolver->accessFor($view, 'bob', ['readers', 'writers'])
		);
	}//end testTheWidestShareWins()

	/**
	 * 🔴 The least privileged principal: in no shared group, on a private view.
	 *
	 * The control. Without it a resolver that answered `read` for everybody
	 * would satisfy every other test in this file.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/view-group-share/specs/saved-search-views/spec.md
	 */
	public function testAStrangerHoldsNothing(): void {
		$view = $this->view(['sharedWith' => [['group' => 'vth', 'mode' => 'write']]]);

		$this->assertNull($this->resolver->accessFor($view, 'mallory', ['another-group']));
		$this->assertNull($this->resolver->accessFor($view, 'mallory', []));
	}//end testAStrangerHoldsNothing()

	/**
	 * A public view is readable by anybody, and no more than readable.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/view-group-share/specs/saved-search-views/spec.md
	 */
	public function testAPublicViewIsReadableAndNoMore(): void {
		$view = $this->view(['isPublic' => true]);

		$this->assertSame(
			ViewShareResolver::ACCESS_READ,
			$this->resolver->accessFor($view, 'mallory', [])
		);
	}//end testAPublicViewIsReadableAndNoMore()

	/**
	 * An unreadable share grants nothing rather than read.
	 *
	 * A mode this resolver does not know is not "probably read": that is the
	 * direction that admits somebody on a typo.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/view-group-share/specs/saved-search-views/spec.md
	 */
	public function testAnUnreadableShareGrantsNothing(): void {
		$view = $this->view(
			[
				'sharedWith' => [
					['group' => 'vth', 'mode' => 'readonly'],
					['group' => 'other', 'mode' => ''],
					['group' => '', 'mode' => 'write'],
					'not-an-object',
				],
			]
		);

		$this->assertNull($this->resolver->accessFor($view, 'bob', ['vth', 'other']));
	}//end testAnUnreadableShareGrantsNothing()

	/**
	 * A share list stored as raw JSON is still read.
	 *
	 * The column is TEXT and some read paths hand back the raw string. A
	 * resolver that did not decode would report "shared with nobody".
	 *
	 * @return void
	 *
	 * @spec openspec/changes/view-group-share/specs/saved-search-views/spec.md
	 */
	public function testAJsonEncodedShareListIsRead(): void {
		$view = $this->view(['sharedWith' => '[{"group":"vth","mode":"write"}]']);

		$this->assertSame(
			ViewShareResolver::ACCESS_WRITE,
			$this->resolver->accessFor($view, 'bob', ['vth'])
		);
	}//end testAJsonEncodedShareListIsRead()

	/**
	 * 🔴 A write member may change what the view shows, and nothing else.
	 *
	 * The assertion this change turns on.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/view-group-share/specs/saved-search-views/spec.md
	 */
	public function testAWriteMemberMayNotReshareRehomeOrDelete(): void {
		$allowed = $this->resolver->refusedFields(
			['query' => [], 'presentation' => [], 'alert' => []],
			ViewShareResolver::ACCESS_WRITE,
			false
		);
		$this->assertSame([], $allowed, 'the three fields a member owns');

		$refused = $this->resolver->refusedFields(
			['query' => [], 'sharedWith' => [], 'owner' => 'bob', 'isPublic' => true],
			ViewShareResolver::ACCESS_WRITE,
			false
		);
		sort($refused);
		$this->assertSame(['isPublic', 'owner', 'sharedWith'], $refused);
	}//end testAWriteMemberMayNotReshareRehomeOrDelete()

	/**
	 * A read member may change nothing at all, and is told which fields.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/view-group-share/specs/saved-search-views/spec.md
	 */
	public function testAReadMemberMayChangeNothing(): void {
		$refused = $this->resolver->refusedFields(
			['query' => []],
			ViewShareResolver::ACCESS_READ,
			false
		);

		$this->assertSame(['query'], $refused);
	}//end testAReadMemberMayChangeNothing()

	/**
	 * The owner and an administrator may change everything.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/view-group-share/specs/saved-search-views/spec.md
	 */
	public function testTheOwnerAndAnAdministratorMayChangeEverything(): void {
		$this->assertSame(
			[],
			$this->resolver->refusedFields(
				['owner' => 'bob', 'sharedWith' => []],
				ViewShareResolver::ACCESS_OWNER,
				true
			)
		);

		$this->assertTrue($this->resolver->mayAdminister($this->view(), 'alice', false));
		$this->assertTrue($this->resolver->mayAdminister($this->view(), 'mallory', true));
		$this->assertFalse($this->resolver->mayAdminister($this->view(), 'mallory', false));
		$this->assertFalse(
			$this->resolver->mayAdminister($this->view(), '', false),
			'an unauthenticated caller never administers a view'
		);
	}//end testTheOwnerAndAnAdministratorMayChangeEverything()

	/**
	 * A share on a group that does not exist is refused.
	 *
	 * Stored, it would look in the grid exactly like a share somebody has, and
	 * the view would be narrower than its own screen says.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/view-group-share/specs/saved-search-views/spec.md
	 */
	public function testAShareOnAnUnknownGroupIsRefused(): void {
		$exists = static fn (string $gid): bool => ($gid === 'vth');

		$findings = $this->resolver->validateShares(
			[['group' => 'belastingen', 'mode' => 'read']],
			$exists
		);

		$this->assertCount(1, $findings);
		$this->assertSame('share.unknown-group', $findings[0]['code']);
		$this->assertStringContainsString('belastingen', $findings[0]['message']);
	}//end testAShareOnAnUnknownGroupIsRefused()

	/**
	 * A valid share list has no findings, and so does an absent one.
	 *
	 * The control for the validator: one that answered a finding for
	 * everything would satisfy the refusal tests and refuse every share.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/view-group-share/specs/saved-search-views/spec.md
	 */
	public function testAValidShareListPasses(): void {
		$exists = static fn (string $gid): bool => true;

		$this->assertSame([], $this->resolver->validateShares(null, $exists));
		$this->assertSame([], $this->resolver->validateShares([], $exists));
		$this->assertSame(
			[],
			$this->resolver->validateShares(
				[
					['group' => 'vth', 'mode' => 'read'],
					['group' => 'belastingen', 'mode' => 'write'],
				],
				$exists
			)
		);
	}//end testAValidShareListPasses()

	/**
	 * A bad mode, a missing group, a duplicate and a non-list are each refused.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/view-group-share/specs/saved-search-views/spec.md
	 */
	public function testTheShapeOfAShareListIsChecked(): void {
		$exists = static fn (string $gid): bool => true;
		$codes = static fn (array $findings): array => array_column($findings, 'code');

		$this->assertContains(
			'share.bad-mode',
			$codes($this->resolver->validateShares([['group' => 'vth', 'mode' => 'admin']], $exists))
		);
		$this->assertContains(
			'share.no-group',
			$codes($this->resolver->validateShares([['mode' => 'read']], $exists))
		);
		$this->assertContains(
			'share.duplicate-group',
			$codes(
				$this->resolver->validateShares(
					[
						['group' => 'vth', 'mode' => 'read'],
						['group' => 'vth', 'mode' => 'write'],
					],
					$exists
				)
			)
		);
		$this->assertContains(
			'share.not-a-list',
			$codes($this->resolver->validateShares('vth', $exists))
		);
		$this->assertContains(
			'share.not-an-object',
			$codes($this->resolver->validateShares(['vth'], $exists))
		);
	}//end testTheShapeOfAShareListIsChecked()
}//end class
