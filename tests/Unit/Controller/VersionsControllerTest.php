<?php

/**
 * Unit tests for VersionsController (records-draft-versions task 2.2).
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/records-draft-versions
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

use DateTime;
use InvalidArgumentException;
use OCA\OpenRegister\Controller\VersionsController;
use OCA\OpenRegister\Db\DraftVersion;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Service\Object\DraftService;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The routes sign in, read the object under RBAC and hand every draft
 * decision to DraftService; a hidden object or draft answers 404.
 *
 * @covers \OCA\OpenRegister\Controller\VersionsController
 */
class VersionsControllerTest extends TestCase {

	private DraftService&MockObject $drafts;

	private ObjectEntity $object;

	/**
	 * A controller for a caller (or nobody), over an object the caller can read (or not).
	 *
	 * @param array<string, mixed> $params   Request parameters.
	 * @param string|null          $caller   The signed-in user.
	 * @param bool                 $readable Whether the object is readable.
	 *
	 * @return VersionsController
	 */
	private function controller(array $params = [], ?string $caller = 'medewerker-1', bool $readable = true): VersionsController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(static fn (string $key, mixed $default = null): mixed => ($params[$key] ?? $default));
		$registers = $this->createMock(RegisterMapperInterface::class);
		$registers->method('find')->willReturn(new Register());
		$schemas = $this->createMock(SchemaMapperInterface::class);
		$schemas->method('find')->willReturn(new Schema());
		$this->object = new ObjectEntity();
		$this->object->setUuid('melding-1');
		$this->object->setObject(['titel' => 'Kapotte lamp', 'status' => 'open']);
		$objects = $this->createMock(ObjectOperationsInterface::class);
		$objects->method('find')->willReturn($readable === true ? $this->object : null);
		$session = $this->createMock(IUserSession::class);
		$user = null;
		if ($caller !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($caller);
		}

		$session->method('getUser')->willReturn($user);
		$this->drafts = $this->createMock(DraftService::class);

		return new VersionsController($request, $registers, $schemas, $objects, $this->drafts, $session);
	}//end controller()

	/**
	 * A stored draft.
	 *
	 * @param string $key The key.
	 *
	 * @return DraftVersion
	 */
	private static function draft(string $key): DraftVersion {
		$draft = new DraftVersion();
		$draft->setDraftKey($key);
		$draft->setName('Update 1');
		$draft->setCreatedBy('medewerker-1');
		$draft->setBaseVersion('3');
		$draft->setCreated(new DateTime('2026-11-13T10:00:00+00:00'));
		$draft->setUpdated(new DateTime('2026-11-13T11:00:00+00:00'));
		return $draft;
	}//end draft()

	/**
	 * Nobody signed in is 401; an object the caller cannot read is 404, before any draft is touched.
	 *
	 * @return void
	 */
	public function testSignInAndReadAccessComeFirst(): void {
		$anonymous = $this->controller(caller: null);
		$this->drafts->expects($this->never())->method('visibleDrafts');
		$this->assertSame(401, $anonymous->index('r', 's', 'melding-1')->getStatus());

		$unreadable = $this->controller(readable: false);
		$this->drafts->expects($this->never())->method('find');
		$this->drafts->expects($this->never())->method('create');
		$this->assertSame(404, $unreadable->index('r', 's', 'melding-1')->getStatus());
		$this->assertSame(404, $unreadable->create('r', 's', 'melding-1')->getStatus());
		$this->assertSame(404, $unreadable->show('r', 's', 'melding-1', 'update-1')->getStatus());
	}//end testSignInAndReadAccessComeFirst()

	/**
	 * The list holds the drafts DraftService says the caller may see, without their deltas.
	 *
	 * @return void
	 */
	public function testIndexListsTheVisibleDrafts(): void {
		$controller = $this->controller();
		$this->drafts->expects($this->once())->method('visibleDrafts')
			->with($this->object, $this->isInstanceOf(Schema::class), 'medewerker-1')
			->willReturn([self::draft('update-1')]);

		$response = $controller->index('r', 's', 'melding-1');

		$this->assertSame(
			['results' => [['key' => 'update-1', 'name' => 'Update 1', 'createdBy' => 'medewerker-1', 'baseVersion' => '3', 'created' => '2026-11-13T10:00:00+00:00', 'updated' => '2026-11-13T11:00:00+00:00']]],
			$response->getData()
		);
	}//end testIndexListsTheVisibleDrafts()

	/**
	 * Creating needs the right to change the object: 403 without it.
	 *
	 * @return void
	 */
	public function testCreateWithoutWriteRightIsForbidden(): void {
		$controller = $this->controller(['key' => 'update-1']);
		$this->drafts->method('mayCreate')->willReturn(false);
		$this->drafts->expects($this->never())->method('create');

		$response = $controller->create('r', 's', 'melding-1');

		$this->assertSame(403, $response->getStatus());
	}//end testCreateWithoutWriteRightIsForbidden()

	/**
	 * A refused key (such as `main`) is 422 with DraftService's message; a good one is 201.
	 *
	 * @return void
	 */
	public function testCreateAnswers422ForARefusedKeyAnd201Otherwise(): void {
		$controller = $this->controller(['key' => 'main', 'name' => '', 'object' => ['status' => 'gesloten']]);
		$this->drafts->method('mayCreate')->willReturn(true);
		$this->drafts->expects($this->exactly(2))->method('create')
			->with($this->object, 'main', '', ['status' => 'gesloten'], 'medewerker-1')
			->willReturnOnConsecutiveCalls(
				$this->throwException(new InvalidArgumentException('The key "main" is reserved for the published version.')),
				self::draft('main')
			);

		$refused = $controller->create('r', 's', 'melding-1');
		$this->assertSame([422, ['error' => 'The key "main" is reserved for the published version.']], [$refused->getStatus(), $refused->getData()]);

		$this->assertSame(201, $controller->create('r', 's', 'melding-1')->getStatus());
	}//end testCreateAnswers422ForARefusedKeyAnd201Otherwise()

	/**
	 * A draft the caller may not see is 404 on read, update and discard.
	 *
	 * @return void
	 */
	public function testAHiddenDraftIs404(): void {
		$controller = $this->controller();
		$this->drafts->method('find')->willReturn(null);
		$this->drafts->expects($this->never())->method('update');
		$this->drafts->expects($this->never())->method('discard');

		$this->assertSame(404, $controller->show('r', 's', 'melding-1', 'update-1')->getStatus());
		$this->assertSame(404, $controller->update('r', 's', 'melding-1', 'update-1')->getStatus());
		$this->assertSame(404, $controller->destroy('r', 's', 'melding-1', 'update-1')->getStatus());
	}//end testAHiddenDraftIs404()

	/**
	 * Reading a draft answers the merged object marked with the key; update and discard go through DraftService.
	 *
	 * @return void
	 */
	public function testReadUpdateAndDiscardAVisibleDraft(): void {
		$controller = $this->controller(['object' => ['status' => 'gesloten']]);
		$draft = self::draft('update-1');
		$this->drafts->method('find')->with($this->object, $this->isInstanceOf(Schema::class), 'update-1', 'medewerker-1')->willReturn($draft);
		$this->drafts->method('merged')->with($this->object, $draft)->willReturn(['titel' => 'Kapotte lamp', 'status' => 'gesloten']);
		$this->drafts->expects($this->once())->method('update')
			->with($draft, $this->object, $this->isInstanceOf(Schema::class), ['status' => 'gesloten'], 'medewerker-1')
			->willReturn($draft);
		$this->drafts->expects($this->once())->method('discard')->with($draft, $this->object, $this->isInstanceOf(Schema::class), 'medewerker-1');

		$this->assertSame(
			['titel' => 'Kapotte lamp', 'status' => 'gesloten', '@self' => ['id' => 'melding-1', '_version' => 'update-1']],
			$controller->show('r', 's', 'melding-1', 'update-1')->getData()
		);
		$this->assertSame('update-1', $controller->update('r', 's', 'melding-1', 'update-1')->getData()['key']);
		$this->assertSame(204, $controller->destroy('r', 's', 'melding-1', 'update-1')->getStatus());
	}//end testReadUpdateAndDiscardAVisibleDraft()
}//end class
