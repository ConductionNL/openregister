<?php

/**
 * Unit tests for {@see \OCA\OpenRegister\Service\Gdpr\PrivacyOfficerRecipientResolver}.
 *
 * The declared expression-recipient for deadlineBreach / dpiaFlagged: the
 * officer GROUP resolves from the case's active policy pack; missing packs,
 * placeholder values, and unknown groups fail-safe to zero recipients.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Gdpr
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/dsar-escalation-and-dpia/specs/dsar-deadline-escalation/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\Gdpr;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Gdpr\Policy\DsarPolicyPackResolver;
use OCA\OpenRegister\Service\Gdpr\PrivacyOfficerRecipientResolver;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * PrivacyOfficerRecipientResolverTest.
 */
class PrivacyOfficerRecipientResolverTest extends TestCase {

	private DsarPolicyPackResolver&MockObject $packResolver;

	private IGroupManager&MockObject $groupManager;

	private PrivacyOfficerRecipientResolver $resolver;

	protected function setUp(): void {
		$this->packResolver = $this->createMock(DsarPolicyPackResolver::class);
		$this->groupManager = $this->createMock(IGroupManager::class);

		$this->resolver = new PrivacyOfficerRecipientResolver(
			packResolver: $this->packResolver,
			groupManager: $this->groupManager,
			logger: $this->createMock(LoggerInterface::class),
		);

	}//end setUp()

	/**
	 * The pack's officer group expands to its members' uids.
	 *
	 * @return void
	 */
	public function testResolvesOfficerGroupMembers(): void {
		$this->packResolver->method('activePackForCase')->willReturn(
			['privacyOfficerGroup' => 'privacy-officers']
		);

		$alice = $this->createMock(IUser::class);
		$alice->method('getUID')->willReturn('alice');
		$bob = $this->createMock(IUser::class);
		$bob->method('getUID')->willReturn('bob');

		$group = $this->createMock(IGroup::class);
		$group->method('getUsers')->willReturn([$alice, $bob, $alice]);
		$this->groupManager->method('get')->with('privacy-officers')->willReturn($group);

		$uids = $this->resolver->resolve($this->case(), []);

		$this->assertEqualsCanonicalizing(['alice', 'bob'], $uids);

	}//end testResolvesOfficerGroupMembers()

	/**
	 * Fail-safe matrix: no pack / unset field / placeholder / unknown group
	 * all resolve to zero officer recipients.
	 *
	 * @return void
	 */
	public function testFailSafeMatrix(): void {
		// No pack resolves.
		$this->packResolver->method('activePackForCase')->willReturn(null);
		$this->assertSame([], $this->resolver->resolve($this->case(), []));

		// Unset field.
		$this->setUp();
		$this->packResolver->method('activePackForCase')->willReturn(['jurisdiction' => 'default']);
		$this->assertSame([], $this->resolver->resolve($this->case(), []));

		// Placeholder value (seed convention).
		$this->setUp();
		$this->packResolver->method('activePackForCase')->willReturn(
			['privacyOfficerGroup' => '<privacy-officer-group>']
		);
		$this->groupManager->expects($this->never())->method('get');
		$this->assertSame([], $this->resolver->resolve($this->case(), []));

		// Unknown group.
		$this->setUp();
		$this->packResolver->method('activePackForCase')->willReturn(
			['privacyOfficerGroup' => 'ghost-group']
		);
		$this->groupManager->method('get')->willReturn(null);
		$this->assertSame([], $this->resolver->resolve($this->case(), []));

	}//end testFailSafeMatrix()

	/**
	 * A DSAR case entity.
	 *
	 * @return ObjectEntity
	 */
	private function case(): ObjectEntity {
		$case = new ObjectEntity();
		$case->setUuid('case-1');
		$case->setObject(
			[
				'jurisdiction' => 'default',
				'subjectId' => 'subject@example.org',
			]
		);

		return $case;
	}//end case()
}//end class
