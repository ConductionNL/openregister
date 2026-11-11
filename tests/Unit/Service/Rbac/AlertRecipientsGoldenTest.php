<?php

/**
 * Golden test for which alert recipients ViewerReachResolver refuses.
 *
 * Pins the refused list, allowed and refused recipients both, for the owner
 * (who may add anyone the view reaches) and for a write member (who may add
 * only themselves), so the judgement can be split without an alert ever
 * telling somebody the view does not reach about it.
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

use OCA\OpenRegister\Service\Rbac\AlertRecipientChange;
use OCA\OpenRegister\Service\Rbac\CallerContext;
use OCA\OpenRegister\Service\Rbac\ReachedView;
use OCA\OpenRegister\Service\Rbac\ViewerReach;
use OCA\OpenRegister\Service\Rbac\ViewerReachResolver;
use OCP\IGroupManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Allowed and refused alert recipients, pinned.
 */
class AlertRecipientsGoldenTest extends TestCase {

	/**
	 * The recipients refused for this caller on a view owned by anna and shared with `sales`.
	 *
	 * @param ViewerReach        $reach      The caller's reach.
	 * @param array<int, mixed>  $recipients The declared recipients.
	 * @param array<int, string> $previous   The recipients on the stored alert.
	 *
	 * @return array<int, string> The refused recipients.
	 */
	private function refused(ViewerReach $reach, array $recipients, array $previous = []): array {
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturnCallback(static fn (string $uid): bool => $uid === 'root');
		$groups->method('isInGroup')->willReturnCallback(static fn (string $uid, string $gid): bool => $uid === 'piet' && $gid === 'sales');
		$resolver = new ViewerReachResolver(
			caller: new CallerContext($this->createMock(IUserSession::class), $groups),
			logger: $this->createMock(LoggerInterface::class)
		);

		return $resolver->refusedRecipients(
			reached: new ReachedView(
				view: ['owner' => 'anna', 'isPublic' => false, 'sharedWith' => [['group' => 'sales', 'mode' => 'read'], ['group' => 'hr', 'mode' => 'write']]],
				reach: $reach
			),
			change: new AlertRecipientChange(alert: ['recipients' => $recipients], previous: $previous)
		);
	}//end refused()

	public function testTheOwnerMayAddEveryoneTheViewReachesAndNobodyElse(): void {
		$this->assertSame(
			['finance', 'user:kees', 'user:'],
			$this->refused(
				reach: new ViewerReach(userId: 'anna', groups: [], isAdmin: false),
				recipients: ['sales', 'finance', 'user:piet', 'user:kees', 'user:root', 'user:', 7, 'finance', 'user:old', 'user:kees'],
				previous: ['user:old']
			)
		);
	}//end testTheOwnerMayAddEveryoneTheViewReachesAndNobodyElse()

	public function testAWriteMemberMayAddOnlyThemselves(): void {
		$this->assertSame(
			['sales', 'user:piet'],
			$this->refused(
				reach: new ViewerReach(userId: 'marit', groups: ['hr'], isAdmin: false),
				recipients: ['user:marit', 'sales', 'user:piet', 'user:stored'],
				previous: ['user:stored']
			)
		);
		$this->assertSame([], $this->refused(reach: new ViewerReach(userId: 'marit', groups: ['hr'], isAdmin: false), recipients: []));
	}//end testAWriteMemberMayAddOnlyThemselves()

	public function testAnAlertWithoutARecipientListRefusesNothing(): void {
		$this->assertSame([], $this->refused(reach: new ViewerReach(userId: 'x', groups: [], isAdmin: false), recipients: []));
	}//end testAnAlertWithoutARecipientListRefusesNothing()
}//end class
