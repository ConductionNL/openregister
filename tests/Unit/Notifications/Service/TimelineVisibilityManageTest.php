<?php

/**
 * TimelineVisibilityService::mayManageObject asks for `manage`, and fails closed.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Notifications\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Notifications\Service;

use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Notifications\Service\TimelineVisibilityService;
use OCA\OpenRegister\Notifications\Service\TimelineVisibilityPlatform;
use OCA\OpenRegister\Service\Object\PermissionCheckInterface;
use OCA\OpenRegister\Tests\Unit\Service\Object\PermissionAskArgs;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The `manage` verdict that decides whether a note's text may be rewritten.
 */
class TimelineVisibilityManageTest extends TestCase {

	private SchemaMapperInterface&MockObject $schemaMapper;
	private PermissionCheckInterface&MockObject $permissions;
	private IUserSession&MockObject $session;
	private TimelineVisibilityService $service;

	protected function setUp(): void {
		$this->schemaMapper = $this->createMock(SchemaMapperInterface::class);
		$this->permissions = $this->createMock(PermissionCheckInterface::class);
		$this->session = $this->createMock(IUserSession::class);
		$this->service = new TimelineVisibilityService(
			new TimelineVisibilityPlatform($this->schemaMapper, $this->session, $this->createMock(LoggerInterface::class)),
			$this->permissions,
			$this->createMock(AuditTrailMapperInterface::class)
		);
	}

	private function object(): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid('obj-1');
		$object->setSchema('3');
		$object->setOwner('author');

		return $object;
	}

	/**
	 * @return array<string, array{0: bool}>
	 */
	public static function verdictProvider(): array {
		return ['manager' => [true], 'colleague with update only' => [false]];
	}

	/**
	 * @dataProvider verdictProvider
	 */
	public function testTheVerdictIsTheManagePermissionOfTheSignedInUser(bool $holds): void {
		$schema = new Schema();
		$object = $this->object();
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('handler');
		$this->session->method('getUser')->willReturn($user);
		$this->schemaMapper->method('find')->with('3', [], false, false)->willReturn($schema);
		$this->permissions->expects($this->once())->method('hasPermission')
			->with(...PermissionAskArgs::with($schema, 'manage', 'handler', 'author', true, $object))
			->willReturn($holds);

		$this->assertSame($holds, $this->service->mayManageObject($object));
	}

	public function testNoObjectIsRefusedWithoutAskingRbac(): void {
		$this->permissions->expects($this->never())->method('hasPermission');

		$this->assertFalse($this->service->mayManageObject(null));
	}
}
