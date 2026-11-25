<?php

/**
 * A mixed-schema bulk save checks and writes the schema the batch register carries under a row's slug.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Controller
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://www.OpenRegister.app
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\BulkController;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Tests\Unit\Controller\Support\BulkControllerFactory;
use OCP\AppFramework\Http;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Controller\BulkController
 * @uses   \OCA\OpenRegister\Service\Schemas\RowSchemaRefsInRegister
 * @uses   \OCA\OpenRegister\Service\Schemas\SchemaRefInRegister
 * @uses   \OCA\OpenRegister\Service\Rbac\SchemaManageGate
 */
final class BulkControllerMixedSchemaSlugScopeTest extends TestCase {

	/** @var IRequest&MockObject */
	private IRequest $request;

	/** @var ObjectService&MockObject */
	private ObjectService $objectService;

	/** @var RegisterMapper&MockObject */
	private RegisterMapper $registers;

	/** @var SchemaMapper&MockObject */
	private SchemaMapper $schemas;

	/** @var IUserSession&MockObject */
	private IUserSession $userSession;

	/** @var IGroupManager&MockObject */
	private IGroupManager $groupManager;

	/** @var BulkController */
	private BulkController $controller;

	protected function setUp(): void {
		parent::setUp();
		$this->request = $this->createMock(IRequest::class);
		$this->objectService = $this->createMock(ObjectService::class);
		$this->registers = $this->createMock(RegisterMapper::class);
		$this->schemas = $this->createMock(SchemaMapper::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->groupManager = $this->createMock(IGroupManager::class);

		$this->objectService->method('setRegister')->willReturnSelf();
		$this->objectService->method('setSchema')->willReturnSelf();
		$this->objectService->method('getRegister')->willReturn(7);

		$register = new Register();
		$register->setSchemas([36]);
		$this->registers->method('find')->willReturn($register);

		$this->controller = BulkControllerFactory::create(
			'openregister',
			$this->request,
			$this->objectService,
			$this->registers,
			$this->schemas,
			$this->userSession,
			$this->groupManager,
			$this->createMock(IDBConnection::class)
		);
	}

	private static function schema(int $id, ?array $authorization = null): Schema {
		$schema = new Schema();
		$schema->setId($id);
		$schema->setSlug('agent');
		$schema->setAuthorization($authorization);

		return $schema;
	}

	private function caller(bool $admin, array $groups = []): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('caller');
		$this->userSession->method('getUser')->willReturn($user);
		$this->groupManager->method('isAdmin')->willReturn($admin);
		$this->groupManager->method('getUserGroupIds')->willReturn($groups);
	}

	public function testTheRightsCheckReadsTheRegistersOwnSchemaForASharedSlug(): void {
		// The register's schema 36 lets the caller's group manage it; the global
		// lookup of the slug would answer another app's schema that does not.
		$this->objectService->method('getSchema')->willReturn(0);
		$this->caller(admin: false, groups: ['agents']);
		$this->schemas->method('findBySlugInIds')->with('agent', [36])->willReturn(self::schema(36, ['manage' => ['agents']]));
		$this->schemas->method('find')->willReturnCallback(
			static fn (mixed $id): Schema => match ((string)$id) {
				'36' => self::schema(36, ['manage' => ['agents']]),
				default => self::schema(22),
			}
		);
		$this->objectService->expects($this->once())->method('saveObjects')
			->with($this->equalTo([['@self' => ['schema' => 36], 'name' => 'n']]))
			->willReturn(['statistics' => ['saved' => 1, 'updated' => 0]]);
		$this->request->method('getParams')->willReturn(['objects' => [['@self' => ['schema' => 'agent'], 'name' => 'n']]]);

		$this->assertSame(Http::STATUS_OK, $this->controller->save('7', '0')->getStatus());
	}

	public function testARefusedRegisterSchemaIsNotAdmittedThroughAManageableNamesake(): void {
		// The reverse: the register's own schema refuses the caller, another app's
		// schema under the same slug admits them. The check follows the save.
		$this->objectService->method('getSchema')->willReturn(0);
		$this->caller(admin: false, groups: ['agents']);
		$this->schemas->method('findBySlugInIds')->with('agent', [36])->willReturn(self::schema(36));
		$this->schemas->method('find')->willReturnCallback(
			static fn (mixed $id): Schema => match ((string)$id) {
				'36' => self::schema(36),
				default => self::schema(22, ['manage' => ['agents']]),
			}
		);
		$this->objectService->expects($this->never())->method('saveObjects');
		$this->request->method('getParams')->willReturn(['objects' => [['@self' => ['schema' => 'agent']]]]);

		$response = $this->controller->save('7', '0');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertStringContainsString('36', $response->getData()['error']);
	}

	public function testASingleSchemaBatchIsNotRewritten(): void {
		$this->caller(admin: true);
		$this->objectService->method('getSchema')->willReturn(36);
		$this->schemas->expects($this->never())->method('findBySlugInIds');
		$this->schemas->method('find')->willReturn(self::schema(36));
		$rows = [['@self' => ['schema' => 'agent'], 'name' => 'n']];
		$this->objectService->expects($this->once())->method('saveObjects')
			->with($this->equalTo($rows))
			->willReturn(['statistics' => ['saved' => 1, 'updated' => 0]]);
		$this->request->method('getParams')->willReturn(['objects' => $rows]);

		$this->controller->save('7', '36');
	}
}
