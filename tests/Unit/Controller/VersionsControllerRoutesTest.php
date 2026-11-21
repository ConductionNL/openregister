<?php

/**
 * Route-level tests for VersionsController, the draft versions of one object.
 *
 * The controller resolves the route's register, schema and object before any
 * draft is read or written, so the contract under test is which status a
 * caller gets: 401 without a session, 404 for an object or draft that cannot
 * be found (also when the lookup throws), 403 when the caller may not create
 * a draft, 422 for a refused draft, and the draft summary otherwise.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.
// phpcs:disable PEAR.Commenting.FunctionComment.MissingReturn -- PHPUnit fixtures and tests; the signature IS the contract.
// phpcs:disable Squiz.Commenting.VariableComment.Missing -- typed mock fixtures; the declaration IS the description.

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
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCA\OpenRegister\Service\Object\DraftService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Controller\VersionsController
 * @uses   \OCA\OpenRegister\Db\DraftVersion
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 * @uses   \OCA\OpenRegister\Db\Register
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions
 * @uses   \OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget
 * @uses   \OCA\OpenRegister\Kernel\Contracts\Value\BulkSaveOptions
 * @uses   \OCA\OpenRegister\Kernel\Contracts\Value\BulkBatchDefaults
 * @uses   \OCA\OpenRegister\Kernel\Contracts\Value\SavePolicy
 * @uses   \OCA\OpenRegister\Kernel\Contracts\Value\SaveActor
 * @uses   \OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 */
class VersionsControllerRoutesTest extends TestCase {

	private IRequest&MockObject $request;
	private RegisterMapperInterface&MockObject $registers;
	private SchemaMapperInterface&MockObject $schemas;
	private ObjectOperationsInterface&MockObject $objects;
	private DraftService&MockObject $drafts;
	private IUserSession&MockObject $userSession;
	private Register $register;
	private Schema $schema;
	private ObjectEntity $object;

	/** @var array<string, mixed> */
	private array $params = [];

	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->request->method('getParam')->willReturnCallback(
			fn (string $key, mixed $default = null): mixed => ($this->params[$key] ?? $default)
		);
		$this->registers   = $this->createMock(RegisterMapperInterface::class);
		$this->schemas     = $this->createMock(SchemaMapperInterface::class);
		$this->objects     = $this->createMock(ObjectOperationsInterface::class);
		$this->drafts      = $this->createMock(DraftService::class);
		$this->userSession = $this->createMock(IUserSession::class);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('anna');
		$this->userSession->method('getUser')->willReturn($user);

		$this->register = new Register();
		$this->schema   = new Schema();
		$this->object   = new ObjectEntity();
		$this->object->setUuid('obj-1');
	}

	private function controller(): VersionsController {
		return new VersionsController(
			request: $this->request,
			registers: $this->registers,
			schemas: $this->schemas,
			objects: $this->objects,
			drafts: $this->drafts,
			userSession: $this->userSession
		);
	}

	/**
	 * Resolve the route to the fixture register, schema and object.
	 */
	private function objectFound(): void {
		$this->registers->method('find')->with('reg', false, false)->willReturn($this->register);
		$this->schemas->method('find')->with('sch', [], false, false)->willReturn($this->schema);
		$this->objects->method('find')->willReturnCallback(
			function (int|string $id, ?ObjectFindOptions $options = null): ?ObjectEntity {
				$this->assertSame('obj-1', $id);
				$this->assertSame($this->register, $options?->register);
				$this->assertSame($this->schema, $options?->schema);
				$this->assertFalse($options->render);
				$this->assertFalse($options->audit);

				return $this->object;
			}
		);
	}

	private function draft(string $key = 'v2'): DraftVersion {
		$draft = new DraftVersion();
		$draft->setDraftKey($key);
		$draft->setName('Second try');
		$draft->setCreatedBy('anna');
		$draft->setBaseVersion('1.0.0');
		$draft->setCreated(new DateTime('2026-11-01T10:00:00+00:00'));

		return $draft;
	}

	/**
	 * The summary the controller answers for a fixture draft.
	 *
	 * @param string $key The draft key.
	 *
	 * @return array<string, string|null>
	 */
	private function summary(string $key): array {
		return [
			'key' => $key,
			'name' => 'Second try',
			'createdBy' => 'anna',
			'baseVersion' => '1.0.0',
			'created' => '2026-11-01T10:00:00+00:00',
			'updated' => null,
		];
	}

	public function testWithoutASessionEveryActionAnswers401(): void {
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn(null);
		$this->userSession = $session;
		$this->registers->expects($this->never())->method('find');
		$this->drafts->expects($this->never())->method($this->anything());

		$controller = $this->controller();
		foreach ([
			$controller->index('reg', 'sch', 'obj-1'),
			$controller->create('reg', 'sch', 'obj-1'),
			$controller->show('reg', 'sch', 'obj-1', 'v2'),
			$controller->update('reg', 'sch', 'obj-1', 'v2'),
			$controller->destroy('reg', 'sch', 'obj-1', 'v2'),
		] as $response) {
			$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
			$this->assertSame(['error' => 'Please sign in.'], $response->getData());
		}
	}

	public function testAnUnknownObjectAnswers404(): void {
		$this->registers->method('find')->willReturn($this->register);
		$this->schemas->method('find')->willReturn($this->schema);
		$this->objects->method('find')->willReturn(null);
		$this->drafts->expects($this->never())->method($this->anything());

		$response = $this->controller()->index('reg', 'sch', 'obj-1');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame(['error' => 'This object does not exist.'], $response->getData());
	}

	public function testALookupThatThrowsAnswers404(): void {
		$this->registers->method('find')->willThrowException(new RuntimeException('no such register'));
		$this->objects->expects($this->never())->method('find');

		$response = $this->controller()->show('reg', 'sch', 'obj-1', 'v2');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame(['error' => 'This object does not exist.'], $response->getData());
	}

	public function testIndexListsTheDraftsTheCallerMaySee(): void {
		$this->objectFound();
		$this->drafts->expects($this->once())->method('visibleDrafts')
			->with($this->object, $this->schema, 'anna')
			->willReturn([$this->draft('v2'), $this->draft('v3')]);

		$response = $this->controller()->index('reg', 'sch', 'obj-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(
			[
				'results' => [
					$this->summary(key: 'v2'),
					$this->summary(key: 'v3'),
				],
			],
			$response->getData()
		);
	}

	public function testCreateIsRefusedWith403WhenTheCallerMayNotChangeTheObject(): void {
		$this->objectFound();
		$this->drafts->method('mayCreate')->with($this->object, $this->schema, 'anna')->willReturn(false);
		$this->drafts->expects($this->never())->method('create');

		$response = $this->controller()->create('reg', 'sch', 'obj-1');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame(['error' => 'Creating a draft needs the right to change this object.'], $response->getData());
	}

	public function testCreatePassesKeyNameAndChangesAndAnswers201(): void {
		$this->objectFound();
		$this->params = ['key' => 'v2', 'name' => 'Second try', 'object' => ['title' => 'New']];
		$this->drafts->method('mayCreate')->willReturn(true);
		$this->drafts->expects($this->once())->method('create')
			->with($this->object, 'v2', 'Second try', ['title' => 'New'], 'anna')
			->willReturn($this->draft());

		$response = $this->controller()->create('reg', 'sch', 'obj-1');

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
		$this->assertSame('v2', $response->getData()['key']);
	}

	public function testCreateIgnoresChangesThatAreNotAnObjectAndDefaultsTheNames(): void {
		$this->objectFound();
		$this->params = ['object' => 'not-an-array'];
		$this->drafts->method('mayCreate')->willReturn(true);
		$this->drafts->expects($this->once())->method('create')
			->with($this->object, '', '', [], 'anna')
			->willReturn($this->draft());

		$this->assertSame(Http::STATUS_CREATED, $this->controller()->create('reg', 'sch', 'obj-1')->getStatus());
	}

	public function testARefusedDraftAnswers422WithTheReason(): void {
		$this->objectFound();
		$this->drafts->method('mayCreate')->willReturn(true);
		$this->drafts->method('create')->willThrowException(new InvalidArgumentException('A draft with this key exists.'));

		$response = $this->controller()->create('reg', 'sch', 'obj-1');

		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		$this->assertSame(['error' => 'A draft with this key exists.'], $response->getData());
	}

	public function testAnUnknownDraftAnswers404ForShowUpdateAndDestroy(): void {
		$this->objectFound();
		$this->drafts->method('find')->with($this->object, $this->schema, 'v9', 'anna')->willReturn(null);
		$this->drafts->expects($this->never())->method('update');
		$this->drafts->expects($this->never())->method('discard');
		$this->drafts->expects($this->never())->method('merged');

		$controller = $this->controller();
		foreach ([
			$controller->show('reg', 'sch', 'obj-1', 'v9'),
			$controller->update('reg', 'sch', 'obj-1', 'v9'),
			$controller->destroy('reg', 'sch', 'obj-1', 'v9'),
		] as $response) {
			$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
			$this->assertSame(['error' => 'This draft does not exist.'], $response->getData());
		}
	}

	public function testShowAnswersTheMergedObjectWithItsVersion(): void {
		$this->objectFound();
		$draft = $this->draft();
		$this->drafts->method('find')->willReturn($draft);
		$this->drafts->expects($this->once())->method('merged')->with($this->object, $draft)
			->willReturn(['title' => 'New', '@self' => ['id' => 'ignored']]);

		$response = $this->controller()->show('reg', 'sch', 'obj-1', 'v2');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		// The merged object's own @self wins over the one the controller adds.
		$this->assertSame(['title' => 'New', '@self' => ['id' => 'ignored']], $response->getData());
	}

	public function testShowAddsTheVersionWhenTheMergedObjectHasNoSelf(): void {
		$this->objectFound();
		$draft = $this->draft();
		$this->drafts->method('find')->willReturn($draft);
		$this->drafts->method('merged')->willReturn(['title' => 'New']);

		$response = $this->controller()->show('reg', 'sch', 'obj-1', 'v2');

		$this->assertSame(['title' => 'New', '@self' => ['id' => 'obj-1', '_version' => 'v2']], $response->getData());
	}

	public function testUpdateSavesTheChangesAsTheCaller(): void {
		$this->objectFound();
		$draft = $this->draft();
		$saved = $this->draft();
		$saved->setUpdated(new DateTime('2026-11-02T08:30:00+00:00'));
		$this->params = ['object' => ['title' => 'Newer']];
		$this->drafts->method('find')->willReturn($draft);
		$this->drafts->expects($this->once())->method('update')
			->with($draft, $this->object, $this->schema, ['title' => 'Newer'], 'anna')
			->willReturn($saved);

		$response = $this->controller()->update('reg', 'sch', 'obj-1', 'v2');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('2026-11-02T08:30:00+00:00', $response->getData()['updated']);
	}

	public function testDestroyDiscardsTheDraftAndAnswers204(): void {
		$this->objectFound();
		$draft = $this->draft();
		$this->drafts->method('find')->willReturn($draft);
		$this->drafts->expects($this->once())->method('discard')->with($draft, $this->object, $this->schema, 'anna');

		$response = $this->controller()->destroy('reg', 'sch', 'obj-1', 'v2');

		$this->assertSame(Http::STATUS_NO_CONTENT, $response->getStatus());
		$this->assertNull($response->getData());
	}
}//end class
