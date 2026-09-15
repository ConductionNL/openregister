<?php

/**
 * OpenRegister - the wire contract of the four state verbs.
 *
 * Each refusal keeps its own status. A caller cannot act on "something went
 * wrong", and the three refusals here are fixed in three different places: the
 * schema, the caller's roles, and the url.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/object-archive-state/specs/object-lifecycle/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\ObjectStateController;
use OCA\OpenRegister\Exception\ArchiveNotOfferedException;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Service\Object\ArchiveHandler;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Controller\ObjectStateController
 */
final class ObjectStateControllerTest extends TestCase {

	private const REGISTER = '1';

	private const SCHEMA = '2';

	private const OBJ = 'obj-11111111-2222-3333-4444-555555555555';

	private ArchiveHandler&MockObject $handler;

	private IRequest&MockObject $request;

	/**
	 * Wire the collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->handler = $this->createMock(originalClassName: ArchiveHandler::class);
		$this->request = $this->createMock(originalClassName: IRequest::class);
	}//end setUp()

	/**
	 * The controller, signed in or not.
	 *
	 * @param bool $signedIn Whether a user is on the session.
	 *
	 * @return ObjectStateController The controller.
	 */
	private function controller(bool $signedIn = true): ObjectStateController {
		$session = $this->createMock(originalClassName: IUserSession::class);

		$user = null;
		if ($signedIn === true) {
			$user = $this->createMock(originalClassName: IUser::class);
			$user->method('getUID')->willReturn('anna');
		}

		$session->method('getUser')->willReturn($user);

		return new ObjectStateController('openregister', $this->request, $this->handler, $session);
	}//end controller()

	/**
	 * Archiving answers 200 with the stored marker.
	 *
	 * @return void
	 */
	public function testArchiveAnswersTheMarker(): void {
		$this->request->method('getParam')->willReturn('afgehandeld');
		$this->handler->method('archive')->willReturn(
			['uuid' => self::OBJ, 'archived' => ['by' => 'anna', 'at' => 'now', 'reason' => 'afgehandeld']]
		);

		$response = $this->controller()->archive(
			register: self::REGISTER,
			schema: self::SCHEMA,
			id: self::OBJ
		);

		$this->assertSame(200, $response->getStatus());
		$this->assertSame('anna', $response->getData()['archived']['by']);
	}//end testArchiveAnswersTheMarker()

	/**
	 * Restoring answers 200 with the marker cleared.
	 *
	 * @return void
	 */
	public function testUnarchiveAnswersTheClearedMarker(): void {
		$this->request->method('getParam')->willReturn(null);
		$this->handler->method('unarchive')->willReturn(['uuid' => self::OBJ, 'archived' => null]);

		$response = $this->controller()->unarchive(
			register: self::REGISTER,
			schema: self::SCHEMA,
			id: self::OBJ
		);

		$this->assertSame(200, $response->getStatus());
		$this->assertNull($response->getData()['archived']);
	}//end testUnarchiveAnswersTheClearedMarker()

	/**
	 * Freezing and unfreezing answer their own marker.
	 *
	 * @return void
	 */
	public function testFreezeAndUnfreezeAnswerTheFreezeMarker(): void {
		$this->request->method('getParam')->willReturn('bezwaar');
		$this->handler->method('freeze')->willReturn(
			['uuid' => self::OBJ, 'frozen' => ['by' => 'anna', 'at' => 'now', 'state' => null]]
		);
		$this->handler->method('unfreeze')->willReturn(['uuid' => self::OBJ, 'frozen' => null]);

		$frozen = $this->controller()->freeze(
			register: self::REGISTER,
			schema: self::SCHEMA,
			id: self::OBJ
		);
		$this->assertSame(200, $frozen->getStatus());
		$this->assertSame('anna', $frozen->getData()['frozen']['by']);

		$thawed = $this->controller()->unfreeze(
			register: self::REGISTER,
			schema: self::SCHEMA,
			id: self::OBJ
		);
		$this->assertSame(200, $thawed->getStatus());
		$this->assertNull($thawed->getData()['frozen']);
	}//end testFreezeAndUnfreezeAnswerTheFreezeMarker()

	/**
	 * An anonymous caller is refused before anything else.
	 *
	 * `#[NoAdminRequired]` means "not only admins", never "no account", and
	 * reading it the other way is how a write endpoint ends up reachable by
	 * nobody in particular.
	 *
	 * @return void
	 */
	public function testAnAnonymousCallerIsRefused(): void {
		$this->handler->expects($this->never())->method('archive');

		$response = $this->controller(signedIn: false)->archive(
			register: self::REGISTER,
			schema: self::SCHEMA,
			id: self::OBJ
		);

		$this->assertSame(401, $response->getStatus());
	}//end testAnAnonymousCallerIsRefused()

	/**
	 * A schema that does not offer archiving answers 422, not 404 and not 500.
	 *
	 * 422 tells the caller the annotation is missing, which they can fix. A 404
	 * would send them looking for a record that is right there.
	 *
	 * @return void
	 */
	public function testASchemaWithoutTheAnnotationAnswersFourTwentyTwo(): void {
		$this->request->method('getParam')->willReturn(null);
		$this->handler->method('archive')->willThrowException(
			new ArchiveNotOfferedException(message: 'no archive block')
		);

		$response = $this->controller()->archive(
			register: self::REGISTER,
			schema: self::SCHEMA,
			id: self::OBJ
		);

		$this->assertSame(422, $response->getStatus());
		$this->assertSame('no archive block', $response->getData()['error']);
	}//end testASchemaWithoutTheAnnotationAnswersFourTwentyTwo()

	/**
	 * A caller without `update` answers 403.
	 *
	 * @return void
	 */
	public function testACallerWithoutUpdateAnswersForbidden(): void {
		$this->request->method('getParam')->willReturn(null);
		$this->handler->method('archive')->willThrowException(
			new NotAuthorizedException(message: 'nope')
		);

		$response = $this->controller()->archive(
			register: self::REGISTER,
			schema: self::SCHEMA,
			id: self::OBJ
		);

		$this->assertSame(403, $response->getStatus());
	}//end testACallerWithoutUpdateAnswersForbidden()

	/**
	 * An unknown object answers 404.
	 *
	 * @return void
	 */
	public function testAnUnknownObjectAnswersNotFound(): void {
		$this->request->method('getParam')->willReturn(null);
		$this->handler->method('archive')->willThrowException(new DoesNotExistException('gone'));

		$response = $this->controller()->archive(
			register: self::REGISTER,
			schema: self::SCHEMA,
			id: self::OBJ
		);

		$this->assertSame(404, $response->getStatus());
	}//end testAnUnknownObjectAnswersNotFound()

	/**
	 * Anything else answers 500 and says nothing about itself.
	 *
	 * SEC-CTRL-7. The control for the four tests above: a mapper that answered
	 * 500 for everything would fail them, and one that leaked the message would
	 * pass them while publishing an internal path.
	 *
	 * @return void
	 */
	public function testAnUnexpectedFailureLeaksNothing(): void {
		$this->request->method('getParam')->willReturn(null);
		$this->handler->method('archive')->willThrowException(
			new \RuntimeException('SQLSTATE[42S22]: /var/www/html/custom_apps/openregister/lib/...')
		);

		$response = $this->controller()->archive(
			register: self::REGISTER,
			schema: self::SCHEMA,
			id: self::OBJ
		);

		$this->assertSame(500, $response->getStatus());
		$this->assertSame('Internal server error', $response->getData()['error']);
	}//end testAnUnexpectedFailureLeaksNothing()

	/**
	 * A blank reason is not a reason.
	 *
	 * A form that posts an empty field should not record the empty string as
	 * why a record was archived.
	 *
	 * @return void
	 */
	public function testABlankReasonIsNotPassedOn(): void {
		$this->request->method('getParam')->willReturn('   ');

		$this->handler->expects($this->once())
			->method('archive')
			->with(self::OBJ, null, self::REGISTER, self::SCHEMA)
			->willReturn(['uuid' => self::OBJ, 'archived' => []]);

		$this->controller()->archive(register: self::REGISTER, schema: self::SCHEMA, id: self::OBJ);
	}//end testABlankReasonIsNotPassedOn()
}//end class
