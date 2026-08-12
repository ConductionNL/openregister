<?php

declare(strict_types=1);

/**
 * CaseAccessControl Unit Tests
 *
 * Verifies the case-level access-control check: handler-scopes-own permits the
 * assigned handler, an officer overrides across cases, and the check fails
 * closed (anonymous / non-handler-non-officer / unresolvable officer role all
 * deny).
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Gdpr\Case
 * @author   Conduction Development Team <dev@conduction.nl>
 * @license  EUPL-1.2
 */

namespace OCA\OpenRegister\Tests\Unit\Service\Gdpr\Case;

use OCA\OpenRegister\Service\Gdpr\Case\CaseAccessControl;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Test class for CaseAccessControl.
 */
class CaseAccessControlTest extends TestCase {

	/**
	 * Build the SUT for a caller uid (or null for anonymous), officer-group
	 * config value, and group membership.
	 *
	 * @param string|null $uid Caller uid (null = anonymous).
	 * @param string $officerGroup Configured officer group ('' = none).
	 * @param bool $inGroup Whether the caller is in the officer group.
	 *
	 * @return CaseAccessControl
	 */
	private function build(?string $uid, string $officerGroup, bool $inGroup): CaseAccessControl {
		$userSession = $this->createMock(IUserSession::class);
		if ($uid === null) {
			$userSession->method('getUser')->willReturn(null);
		} else {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
			$userSession->method('getUser')->willReturn($user);
		}

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isInGroup')->willReturn($inGroup);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn($officerGroup);

		return new CaseAccessControl(
			$userSession,
			$groupManager,
			$appConfig,
			$this->createMock(LoggerInterface::class)
		);

	}//end build()

	/**
	 * The assigned handler may act on their own case.
	 *
	 * @return void
	 */
	public function testHandlerActsOnOwnCase(): void {
		$sut = $this->build('handler1', 'dsar-officers', false);
		$this->assertTrue($sut->mayAct(['handler' => 'handler1']));

	}//end testHandlerActsOnOwnCase()

	/**
	 * A handler is refused on another handler's case without the officer role.
	 *
	 * @return void
	 */
	public function testHandlerRefusedOnOthersCase(): void {
		$sut = $this->build('handler1', 'dsar-officers', false);
		$this->assertFalse($sut->mayAct(['handler' => 'handler2']));

	}//end testHandlerRefusedOnOthersCase()

	/**
	 * An officer overrides across cases (not the assigned handler).
	 *
	 * @return void
	 */
	public function testOfficerOverridesAcrossCases(): void {
		$sut = $this->build('officer1', 'dsar-officers', true);
		$this->assertTrue($sut->mayAct(['handler' => 'handler2']));

	}//end testOfficerOverridesAcrossCases()

	/**
	 * Anonymous callers are denied (fail closed).
	 *
	 * @return void
	 */
	public function testAnonymousDenied(): void {
		$sut = $this->build(null, 'dsar-officers', true);
		$this->assertFalse($sut->mayAct(['handler' => 'handler1']));

	}//end testAnonymousDenied()

	/**
	 * When the officer role cannot be resolved (no officer group configured),
	 * a non-handler is denied rather than allowed (fail closed).
	 *
	 * @return void
	 */
	public function testFailsClosedWhenOfficerRoleUnresolved(): void {
		// Officer group unconfigured ('') → the override is unresolvable → deny.
		$sut = $this->build('someone', '', true);
		$this->assertFalse($sut->mayAct(['handler' => 'handler2']));

	}//end testFailsClosedWhenOfficerRoleUnresolved()
}//end class
