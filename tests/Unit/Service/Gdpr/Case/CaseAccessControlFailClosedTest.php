<?php

/**
 * Who may act on a DSAR case when the officer role cannot be read.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Gdpr\Case
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Gdpr\Case;

use OCA\OpenRegister\Service\Gdpr\Case\CaseAccessControl;
use OCA\OpenRegister\Service\Gdpr\GdprCallerMembership;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * An unreadable officer-group setting denies the override and says so; the handler still acts.
 *
 * @covers \OCA\OpenRegister\Service\Gdpr\Case\CaseAccessControl
 * @uses \OCA\OpenRegister\Service\Gdpr\GdprCallerMembership
 */
final class CaseAccessControlFailClosedTest extends TestCase {

	private IGroupManager&MockObject $groups;

	private LoggerInterface&MockObject $logger;

	private CaseAccessControl $control;

	/**
	 * Signed in as "carol"; the officer-group setting throws.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('carol');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willThrowException(new RuntimeException('config table locked'));
		$this->groups = $this->createMock(IGroupManager::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->control = new CaseAccessControl(membership: new GdprCallerMembership(userSession: $session, groupManager: $this->groups, logger: $this->logger), appConfig: $config);
	}//end setUp()

	/**
	 * Someone else's case is denied without asking group membership, and the denial is logged.
	 *
	 * @return void
	 */
	public function testAnUnreadableOfficerGroupDeniesTheOverride(): void {
		$this->groups->expects($this->never())->method('isInGroup');
		$this->logger->expects($this->once())->method('warning')
			->with('[CaseAccessControl] officer-group config unreadable — denying (fail closed): config table locked');

		$this->assertFalse($this->control->mayAct(case: ['handler' => 'dave']));
	}//end testAnUnreadableOfficerGroupDeniesTheOverride()

	/**
	 * The assigned handler acts on the case without the setting being read.
	 *
	 * @return void
	 */
	public function testTheHandlerStillActsOnTheirOwnCase(): void {
		$this->logger->expects($this->never())->method('warning');

		$this->assertTrue($this->control->mayAct(case: ['handler' => 'carol']));
	}//end testTheHandlerStillActsOnTheirOwnCase()
}//end class
