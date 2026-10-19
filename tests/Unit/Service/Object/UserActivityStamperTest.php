<?php

/**
 * `@self.lastUserActivity` moves only on a person's own write.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use DateTime;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Object\UserActivityStamper;
use OCA\OpenRegister\Service\SystemOperationContext;
use OCA\OpenRegister\Service\WriteCause;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

class UserActivityStamperTest extends TestCase {

	protected function tearDown(): void {
		WriteCause::reset();
	}//end tearDown()

	/**
	 * A stamper for one acting context.
	 *
	 * @param bool   $signedIn Whether a user is signed in.
	 * @param string $script   The request's script name.
	 * @param string $sapi     The PHP SAPI.
	 *
	 * @return UserActivityStamper
	 */
	private function stamper(bool $signedIn = true, string $script = '/index.php', string $sapi = 'fpm-fcgi'): UserActivityStamper {
		$session = $this->createMock(IUserSession::class);
		$user = null;
		if ($signedIn === true) {
			$user = $this->createMock(IUser::class);
		}

		$session->method('getUser')->willReturn($user);

		$request = $this->createMock(IRequest::class);
		$request->method('getScriptName')->willReturn($script);

		return new UserActivityStamper(userSession: $session, request: $request, sapi: $sapi);
	}//end stamper()

	public function testASignedInUsersWebRequestIsAUserWrite(): void {
		$this->assertTrue($this->stamper()->isUserWrite());
	}

	public function testTheCliIsNotAUserWrite(): void {
		$this->assertFalse($this->stamper(sapi: 'cli')->isUserWrite(), 'occ, CLI cron and repair steps run on the CLI');
	}

	public function testWebCronIsNotAUserWrite(): void {
		$this->assertFalse($this->stamper(script: '/cron.php')->isUserWrite(), 'a background job that set a user is still a job');
	}

	public function testARequestWithoutAUserIsNotAUserWrite(): void {
		$this->assertFalse($this->stamper(signedIn: false)->isUserWrite());
	}

	public function testASystemElevatedWriteIsNotAUserWrite(): void {
		$stamper = $this->stamper();
		$this->assertFalse(SystemOperationContext::run(operation: static fn (): bool => $stamper->isUserWrite()));
	}

	public function testAWriteLabelledWithANonPersonCauseIsNotAUserWrite(): void {
		$stamper = $this->stamper();
		foreach ([WriteCause::SCHEDULED, WriteCause::RULE, WriteCause::IMPORT, WriteCause::MIGRATION, WriteCause::CASCADE] as $cause) {
			$this->assertFalse(
				WriteCause::runAs(cause: $cause, run: null, operation: static fn (): bool => $stamper->isUserWrite()),
				$cause
			);
		}

		$this->assertTrue(WriteCause::runAs(cause: WriteCause::PERSON, run: null, operation: static fn (): bool => $stamper->isUserWrite()));
	}

	public function testStampSetsNowOnAUserWrite(): void {
		$object = new ObjectEntity();
		$before = new DateTime('-1 second');

		$this->stamper()->stamp(object: $object);

		$this->assertNotNull($object->getLastUserActivity());
		$this->assertGreaterThanOrEqual($before, $object->getLastUserActivity());
	}

	public function testStampLeavesTheStoredValueOnASystemWrite(): void {
		$object = new ObjectEntity();
		$stored = new DateTime('2026-09-01 10:00:00');
		$object->setLastUserActivity($stored);

		$this->stamper(sapi: 'cli')->stamp(object: $object);

		$this->assertSame($stored, $object->getLastUserActivity());
	}

	public function testTheObjectSerialisesItUnderSelf(): void {
		$object = new ObjectEntity();
		$this->assertArrayHasKey('lastUserActivity', $object->jsonSerialize()['@self']);
		$this->assertNull($object->jsonSerialize()['@self']['lastUserActivity']);

		$object->setLastUserActivity(new DateTime('2026-09-01T10:00:00+00:00'));
		$this->assertStringStartsWith('2026-09-01T10:00:00', $object->jsonSerialize()['@self']['lastUserActivity']);
	}
}//end class
