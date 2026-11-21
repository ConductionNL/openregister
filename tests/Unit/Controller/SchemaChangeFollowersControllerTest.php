<?php

/**
 * SchemaChangeFollowersController: follow and unfollow a schema's breaking changes.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.
use OCA\OpenRegister\Controller\SchemaChangeFollowersController;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaFollowerMapper;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Controller\SchemaChangeFollowersController
 * @uses   \OCA\OpenRegister\Db\Schema
 */
final class SchemaChangeFollowersControllerTest extends TestCase {

	private SchemaMapperInterface&MockObject $schemas;

	private SchemaFollowerMapper&MockObject $followers;

	private IUserSession&MockObject $session;

	protected function setUp(): void {
		$this->schemas = $this->createMock(SchemaMapperInterface::class);
		$this->followers = $this->createMock(SchemaFollowerMapper::class);
		$this->session = $this->createMock(IUserSession::class);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('datagebruiker');
		$this->session->method('getUser')->willReturn($user);
	}//end setUp()

	private function controller(): SchemaChangeFollowersController {
		return new SchemaChangeFollowersController('openregister', $this->createMock(IRequest::class), $this->schemas, $this->followers, $this->session);
	}//end controller()

	public function testAReaderFollowsAndIsAnswered201(): void {
		$this->schemas->expects($this->once())->method('find')->with(12)->willReturn(new Schema());
		$this->followers->expects($this->once())->method('follow')->with(12, 'datagebruiker')->willReturn(true);

		$response = $this->controller()->follow(12);

		$this->assertSame(201, $response->getStatus());
		$this->assertSame(['schemaId' => 12, 'following' => true], $response->getData());
	}//end testAReaderFollowsAndIsAnswered201()

	public function testFollowingAgainIsIdempotent(): void {
		$this->schemas->method('find')->willReturn(new Schema());
		$this->followers->method('follow')->willReturn(false);

		$response = $this->controller()->follow(12);

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['schemaId' => 12, 'following' => true], $response->getData());
	}//end testFollowingAgainIsIdempotent()

	public function testUnfollowStopsFollowing(): void {
		$this->schemas->method('find')->willReturn(new Schema());
		$this->followers->expects($this->once())->method('unfollow')->with(12, 'datagebruiker')->willReturn(true);

		$response = $this->controller()->unfollow(12);

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['schemaId' => 12, 'following' => false], $response->getData());
	}//end testUnfollowStopsFollowing()

	public function testAUserWhoCannotReadTheSchemaGets404AndNothingIsStored(): void {
		$this->schemas->method('find')->willThrowException(new DoesNotExistException('not visible'));
		$this->followers->expects($this->never())->method('follow');
		$this->followers->expects($this->never())->method('unfollow');

		$this->assertSame(404, $this->controller()->follow(12)->getStatus());
		$this->assertSame(404, $this->controller()->unfollow(12)->getStatus());
	}//end testAUserWhoCannotReadTheSchemaGets404AndNothingIsStored()

	public function testAFailingReadCheckRefuses(): void {
		$this->schemas->method('find')->willThrowException(new RuntimeException('rbac down'));
		$this->followers->expects($this->never())->method('follow');

		$this->assertSame(404, $this->controller()->follow(12)->getStatus());
	}//end testAFailingReadCheckRefuses()

	public function testWithoutASignedInUserNothingIsFollowed(): void {
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn(null);
		$this->session = $session;
		$this->schemas->expects($this->never())->method('find');

		$this->assertSame(404, $this->controller()->follow(12)->getStatus());
	}//end testWithoutASignedInUserNothingIsFollowed()
}//end class
