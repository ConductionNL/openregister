<?php

/**
 * OpenRegister - the wire contract of a correction.
 *
 * Each refusal keeps its own status, and the three are fixed in three
 * different places: the caller's session, the caller's rights, and the body
 * they sent.
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
 * @spec openspec/specs/enhanced-audit-trail/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\CorrectionsController;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Exception\CorrectionRefusedException;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Service\Object\CorrectionService;
use OCA\OpenRegister\Service\Object\CorrectionTarget;
use OCA\OpenRegister\Service\Object\CorrectionTargets;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Controller\CorrectionsController
 * @uses \OCA\OpenRegister\Db\ObjectEntity
 * @uses \OCA\OpenRegister\Exception\NotAuthorizedException
 * @uses \OCA\OpenRegister\Exception\CorrectionRefusedException
 */
final class CorrectionsControllerTest extends TestCase {

	private const REGISTER = 'zaken';

	private const SCHEMA = 'zaak';

	private const OBJ = 'obj-11111111-2222-3333-4444-555555555555';

	private CorrectionService&MockObject $corrections;

	private IRequest&MockObject $request;

	/**
	 * Wire the collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->corrections = $this->createMock(originalClassName: CorrectionService::class);
		$this->request = $this->createMock(originalClassName: IRequest::class);
	}//end setUp()

	/**
	 * The controller, signed in or not.
	 *
	 * @param bool $signedIn Whether a user is on the session.
	 *
	 * @return CorrectionsController The controller.
	 */
	private function controller(bool $signedIn = true): CorrectionsController {
		$session = $this->createMock(originalClassName: IUserSession::class);

		$user = null;
		if ($signedIn === true) {
			$user = $this->createMock(originalClassName: IUser::class);
			$user->method('getUID')->willReturn('anna');
		}

		$session->method('getUser')->willReturn($user);

		return new CorrectionsController(
			request: $this->request,
			services: new \OCA\OpenRegister\Controller\Support\CorrectionsControllerServices(
				corrections: $this->corrections,
				targets: new CorrectionTargets(),
				userSession: $session
			)
		);
	}//end controller()

	/**
	 * Answer the two body parameters the endpoint reads.
	 *
	 * @param mixed $values The `values` parameter.
	 * @param mixed $reason The `reason` parameter.
	 *
	 * @return void
	 */
	private function body(mixed $values, mixed $reason): void {
		$this->request->method('getParam')->willReturnMap(
			[
				['values', null, $values],
				['reason', null, $reason],
			]
		);
	}//end body()

	/**
	 * A mis-registered case is corrected, and the entry comes back with it.
	 *
	 * @return void
	 */
	public function testACorrectionReturnsTheRecordAndItsEntry(): void {
		$this->body(values: ['bsn' => '123456782'], reason: 'Overgetypt van het verkeerde formulier');

		$entity = new ObjectEntity();
		$entity->setUuid(self::OBJ);

		$this->corrections->expects($this->once())
			->method('correct')
			->willReturn(['object' => $entity, 'audit' => null]);

		$response = $this->controller()->correct(
			register: self::REGISTER,
			schema: self::SCHEMA,
			id: self::OBJ
		);

		$this->assertSame(200, $response->getStatus());
		$this->assertArrayHasKey('object', $response->getData());
	}//end testACorrectionReturnsTheRecordAndItsEntry()

	/**
	 * No reason, no correction.
	 *
	 * @return void
	 */
	public function testNoReasonIsRefusedWithFourHundred(): void {
		$this->body(values: ['bsn' => '123456782'], reason: null);

		$this->corrections->method('correct')
			->willThrowException(new CorrectionRefusedException('A correction needs a reason.'));

		$response = $this->controller()->correct(
			register: self::REGISTER,
			schema: self::SCHEMA,
			id: self::OBJ
		);

		$this->assertSame(400, $response->getStatus());
		$this->assertStringContainsString('reason', $response->getData()['error']);
	}//end testNoReasonIsRefusedWithFourHundred()

	/**
	 * A principal without the right is refused, and the refusal names it.
	 *
	 * @return void
	 */
	public function testMissingTheRightIsRefusedWithFourHundredAndThree(): void {
		$this->body(values: ['bsn' => '123456782'], reason: 'Verkeerd overgenomen');

		$this->corrections->method('correct')->willThrowException(
			new NotAuthorizedException("Correcting a value needs the 'object.correct' right.")
		);

		$response = $this->controller()->correct(
			register: self::REGISTER,
			schema: self::SCHEMA,
			id: self::OBJ
		);

		$this->assertSame(403, $response->getStatus());
		$this->assertStringContainsString('object.correct', $response->getData()['error']);
	}//end testMissingTheRightIsRefusedWithFourHundredAndThree()

	/**
	 * An anonymous caller is turned away before the service is reached.
	 *
	 * `#[NoAdminRequired]` means "not only admins", never "no account".
	 *
	 * @return void
	 */
	public function testAnAnonymousCallerIsRefusedWithFourHundredAndOne(): void {
		$this->corrections->expects($this->never())->method('correct');

		$response = $this->controller(signedIn: false)->correct(
			register: self::REGISTER,
			schema: self::SCHEMA,
			id: self::OBJ
		);

		$this->assertSame(401, $response->getStatus());
	}//end testAnAnonymousCallerIsRefusedWithFourHundredAndOne()

	/**
	 * A body with no values to correct never reaches the service.
	 *
	 * @return void
	 */
	public function testAMissingValuesBodyIsRefusedWithFourHundred(): void {
		$this->body(values: null, reason: 'Verkeerd overgenomen');
		$this->corrections->expects($this->never())->method('correct');

		$response = $this->controller()->correct(
			register: self::REGISTER,
			schema: self::SCHEMA,
			id: self::OBJ
		);

		$this->assertSame(400, $response->getStatus());
	}//end testAMissingValuesBodyIsRefusedWithFourHundred()

	/**
	 * The route's register, schema and id reach the service as one target, with the body's values and reason.
	 *
	 * @return void
	 */
	public function testTheRouteReachesTheServiceAsItsTarget(): void {
		$this->body(values: ['bsn' => '123456782'], reason: 'Overgetypt');

		$entity = new ObjectEntity();
		$entity->setUuid(self::OBJ);

		$this->corrections->expects($this->once())
			->method('correct')
			->with(
				$this->callback(
					fn (CorrectionTarget $target): bool => [$target->register, $target->schema, $target->id] === [self::REGISTER, self::SCHEMA, self::OBJ]
				),
				['bsn' => '123456782'],
				'Overgetypt'
			)
			->willReturn(['object' => $entity, 'audit' => null]);

		$response = $this->controller()->correct(register: self::REGISTER, schema: self::SCHEMA, id: self::OBJ);

		$this->assertSame(200, $response->getStatus());
	}//end testTheRouteReachesTheServiceAsItsTarget()
	/**
	 * A record that does not exist answers 404 with a fixed message.
	 *
	 * @return void
	 */
	public function testAMissingRecordIsFourHundredAndFour(): void {
		$this->body(values: ['bsn' => '123456782'], reason: 'Overgetypt');
		$this->corrections->method('correct')->willThrowException(new DoesNotExistException('row 7 is gone'));

		$response = $this->controller()->correct(register: self::REGISTER, schema: self::SCHEMA, id: self::OBJ);

		$this->assertSame([404, ['error' => 'Object not found']], [$response->getStatus(), $response->getData()]);
	}//end testAMissingRecordIsFourHundredAndFour()

	/**
	 * An unexpected failure answers 500 and never echoes its detail.
	 *
	 * @return void
	 */
	public function testAnUnexpectedFailureIsAnOpaqueFiveHundred(): void {
		$this->body(values: ['bsn' => '123456782'], reason: 'Overgetypt');
		$this->corrections->method('correct')->willThrowException(new \TypeError('internal detail /var/www'));

		$response = $this->controller()->correct(register: self::REGISTER, schema: self::SCHEMA, id: self::OBJ);

		$this->assertSame([500, ['error' => 'Internal server error']], [$response->getStatus(), $response->getData()]);
	}//end testAnUnexpectedFailureIsAnOpaqueFiveHundred()

	/**
	 * A refused correction answers 400 with the refusal's own message.
	 *
	 * @return void
	 */
	public function testARefusedCorrectionCarriesItsMessage(): void {
		$this->body(values: ['bsn' => '123456782'], reason: 'Overgetypt');
		$this->corrections->method('correct')->willThrowException(new CorrectionRefusedException('bsn is not correctable'));

		$response = $this->controller()->correct(register: self::REGISTER, schema: self::SCHEMA, id: self::OBJ);

		$this->assertSame([400, ['error' => 'bsn is not correctable']], [$response->getStatus(), $response->getData()]);
	}//end testARefusedCorrectionCarriesItsMessage()
}//end class
