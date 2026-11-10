<?php

/**
 * An object-scope federated share must grant exactly the object it names.
 *
 * The collection endpoint has always honoured that: `buildScopeConfig()` pins
 * `filters['uuid']` to the share's `objectUri`, so `GET /objects` serves one
 * row. The single-object endpoints did not — they took `{id}` from the URL and
 * passed it to `ObjectService` with `_rbac: false, _multitenancy: false`,
 * never comparing it to the grant. The item path was therefore strictly wider
 * than the list path that guards it, and `applyShareVisibility()` does not
 * close the gap because it deliberately skips the confidentiality filter for
 * object scope.
 *
 * These tests fix the asymmetry in both directions: the granted object is still
 * served, and a different one in the same register/schema is not.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace Unit\Controller;

use OCA\OpenRegister\Tests\Unit\Controller\Support\FederationControllerFactory;
use OCA\OpenRegister\Controller\FederationController;
use OCA\OpenRegister\Db\FederatedShare;
use OCA\OpenRegister\Db\FederatedShareMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\FederationShareService;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IRequest;
use OCP\Security\Bruteforce\IThrottler;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Service\Object\ObjectDeletion;

/**
 * Object-scope enforcement on the single-object federation endpoints.
 *
 * @covers \OCA\OpenRegister\Controller\FederationController
 * @uses \OCA\OpenRegister\Controller\Support\FederationControllerServices
 * @uses \OCA\OpenRegister\Db\FederatedShare
 * @uses \OCA\OpenRegister\Db\ObjectEntity
 * @uses \OCA\OpenRegister\Service\WriteCause
 */
class FederationControllerScopeTest extends TestCase {

	/**
	 * The share store, mocked.
	 *
	 * @var FederatedShareMapper&MockObject
	 */
	private FederatedShareMapper&MockObject $shareMapper;

	/**
	 * The object read/write surface, mocked.
	 *
	 * @var ObjectService&MockObject
	 */
	private ObjectService&MockObject $objectService;

	/**
	 * The controller under test.
	 *
	 * @var FederationController
	 */
	private FederationController $controller;

	/**
	 * Build the controller over mocked collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->shareMapper = $this->createMock(FederatedShareMapper::class);
		$this->objectService = $this->createMock(ObjectService::class);

		$this->controller = FederationControllerFactory::create(
			'openregister',
			$this->createMock(IRequest::class),
			$this->shareMapper,
			$this->objectService,
			$this->createMock(FederationShareService::class),
			$this->createMock(IThrottler::class),
			$this->createMock(LoggerInterface::class)
		);

	}//end setUp()

	/**
	 * An accepted, outgoing OBJECT-scope share granting exactly `granted-1`.
	 *
	 * @param string $permissions `read` or `read-write`.
	 *
	 * @return FederatedShare The share.
	 */
	private function objectScopeShare(string $permissions = 'read'): FederatedShare {
		$share = new FederatedShare();
		$share->setDirection('outgoing');
		$share->setStatus('accepted');
		$share->setScope('object');
		$share->setRegister('zaken');
		$share->setSchema('zaak');
		$share->setObjectUri('https://remote.example/api/objects/granted-1');
		$share->setOrganisation('org-a');
		$share->setPermissions($permissions);

		return $share;
	}//end objectScopeShare()

	/**
	 * THE SECURITY PROPERTY. A token for ONE object does not read another.
	 *
	 * The assertion is on the collaborator as well as the status, because a
	 * 404 alone is satisfiable by an unrelated lookup miss — what must be shown
	 * is that the read never happened.
	 *
	 * @return void
	 */
	public function testAnObjectScopeTokenCannotReadADifferentObject(): void {
		$this->shareMapper->method('findByToken')->willReturn($this->objectScopeShare());
		$this->objectService->expects($this->never())->method('find');

		$response = $this->controller->object(shareToken: 'tok', id: 'someone-elses-object');

		$this->assertSame(404, $response->getStatus());

	}//end testAnObjectScopeTokenCannotReadADifferentObject()

	/**
	 * The positive control: the object the share actually names is still served.
	 *
	 * Without this, the refusal above is equally satisfied by a controller that
	 * refuses everything.
	 *
	 * @return void
	 */
	public function testTheGrantedObjectIsStillServed(): void {
		$entity = new ObjectEntity();
		$entity->setUuid('granted-1');
		$entity->setOrganisation('org-a');
		$entity->setObject(['title' => 'The granted case']);

		$this->shareMapper->method('findByToken')->willReturn($this->objectScopeShare());
		$this->objectService->expects($this->once())->method('find')->willReturn($entity);

		$response = $this->controller->object(shareToken: 'tok', id: 'granted-1');

		$this->assertSame(200, $response->getStatus());

	}//end testTheGrantedObjectIsStillServed()

	/**
	 * The same rule on the write side: a read-write object-scope token cannot
	 * overwrite a different object.
	 *
	 * @return void
	 */
	public function testAnObjectScopeTokenCannotUpdateADifferentObject(): void {
		$this->shareMapper->method('findByToken')->willReturn($this->objectScopeShare('read-write'));
		$this->objectService->expects($this->never())->method('saveObject');

		$response = $this->controller->updateObject(shareToken: 'tok', id: 'someone-elses-object');

		$this->assertSame(404, $response->getStatus());

	}//end testAnObjectScopeTokenCannotUpdateADifferentObject()

	/**
	 * And on the delete side — the loudest of the three.
	 *
	 * @return void
	 */
	public function testAnObjectScopeTokenCannotDeleteADifferentObject(): void {
		$this->shareMapper->method('findByToken')->willReturn($this->objectScopeShare('read-write'));
		$this->objectService->expects($this->never())->method('deleteObject');

		$response = $this->controller->deleteObject(shareToken: 'tok', id: 'someone-elses-object');

		$this->assertSame(404, $response->getStatus());

	}//end testAnObjectScopeTokenCannotDeleteADifferentObject()

	/**
	 * A read-only share writes nothing, not even to the object it grants.
	 *
	 * @return void
	 */
	public function testAReadOnlyShareCannotUpdateOrDelete(): void {
		$this->shareMapper->method('findByToken')->willReturn($this->objectScopeShare('read'));
		$this->objectService->expects($this->never())->method('saveObject');
		$this->objectService->expects($this->never())->method('deleteObject');

		$this->assertSame(403, $this->controller->updateObject(shareToken: 'tok', id: 'granted-1')->getStatus());
		$this->assertSame(403, $this->controller->deleteObject(shareToken: 'tok', id: 'granted-1')->getStatus());

	}//end testAReadOnlyShareCannotUpdateOrDelete()

	/**
	 * A read-write schema-scope share over one visible stored object, addressed as `slug-1`.
	 *
	 * @param string|null $storedUuid The stored object's uuid.
	 *
	 * @return void
	 */
	private function writableObjectBySlug(?string $storedUuid): void {
		$share = $this->objectScopeShare(permissions: 'read-write');
		$share->setScope('schema');
		$share->setObjectUri(null);

		$entity = new ObjectEntity();
		$entity->setUuid($storedUuid);
		$entity->setSlug('slug-1');
		$entity->setOrganisation('org-a');
		$entity->setObject(['confidentiality' => 'openbaar']);

		$this->shareMapper->method('findByToken')->willReturn($share);
		$this->objectService->method('find')->willReturn($entity);
	}//end writableObjectBySlug()

	/**
	 * An update by another identifier writes the object the guard checked, by its uuid.
	 *
	 * @return void
	 */
	public function testAnUpdateWritesTheGuardedObjectByItsUuid(): void {
		$this->writableObjectBySlug(storedUuid: 'stored-uuid');
		$written = [];
		$this->objectService->method('saveObject')->willReturnCallback(
			function (...$args) use (&$written): ObjectEntity {
				$written[] = ($args['uuid'] ?? $args[4] ?? null);
				return new ObjectEntity();
			}
		);

		$this->assertSame(200, $this->controller->updateObject(shareToken: 'tok', id: 'slug-1')->getStatus());
		$this->assertSame(['stored-uuid'], $written);
	}//end testAnUpdateWritesTheGuardedObjectByItsUuid()

	/**
	 * A delete by another identifier removes the object the guard checked, by its uuid.
	 *
	 * @return void
	 */
	public function testADeleteRemovesTheGuardedObjectByItsUuid(): void {
		$this->writableObjectBySlug(storedUuid: 'stored-uuid');
		$this->objectService->expects($this->once())->method('deleteObject')->with($this->callback(static fn (ObjectDeletion $deletion): bool => $deletion->uuid == 'stored-uuid'))->willReturn(true);

		$this->assertSame(200, $this->controller->deleteObject(shareToken: 'tok', id: 'slug-1')->getStatus());
	}//end testADeleteRemovesTheGuardedObjectByItsUuid()

	/**
	 * A guarded object without a uuid is not written: there is nothing to name it by.
	 *
	 * @return void
	 */
	public function testAnObjectWithoutUuidIsNotWritten(): void {
		$this->writableObjectBySlug(storedUuid: null);
		$this->objectService->expects($this->never())->method('saveObject');
		$this->objectService->expects($this->never())->method('deleteObject');

		$this->assertSame(404, $this->controller->updateObject(shareToken: 'tok', id: 'slug-1')->getStatus());
		$this->assertSame(404, $this->controller->deleteObject(shareToken: 'tok', id: 'slug-1')->getStatus());
	}//end testAnObjectWithoutUuidIsNotWritten()

	/**
	 * A REGISTER/SCHEMA-scope share is unaffected: its breadth IS the grant,
	 * and `applyShareVisibility()` remains the guard there. Narrowing it here
	 * would have broken federation rather than secured it.
	 *
	 * @return void
	 */
	public function testASchemaScopeShareStillServesAnyObjectItCovers(): void {
		$share = $this->objectScopeShare();
		$share->setScope('schema');
		$share->setObjectUri(null);

		$entity = new ObjectEntity();
		$entity->setUuid('any-object');
		$entity->setOrganisation('org-a');
		$entity->setObject(['confidentiality' => 'openbaar']);

		$this->shareMapper->method('findByToken')->willReturn($share);
		$this->objectService->expects($this->once())->method('find')->willReturn($entity);

		$response = $this->controller->object(shareToken: 'tok', id: 'any-object');

		$this->assertSame(200, $response->getStatus());

	}//end testASchemaScopeShareStillServesAnyObjectItCovers()

	/**
	 * An object-scope share that names no object grants nothing. Failing closed
	 * is the only safe reading of a malformed grant — the alternative is a
	 * token whose scope field says "one object" and whose reach is the whole
	 * schema.
	 *
	 * @return void
	 */
	public function testAnObjectScopeShareWithNoObjectUriGrantsNothing(): void {
		$share = $this->objectScopeShare();
		$share->setObjectUri('');

		$this->shareMapper->method('findByToken')->willReturn($share);
		$this->objectService->expects($this->never())->method('find');

		$response = $this->controller->object(shareToken: 'tok', id: 'anything');

		$this->assertSame(404, $response->getStatus());

	}//end testAnObjectScopeShareWithNoObjectUriGrantsNothing()
}//end class
