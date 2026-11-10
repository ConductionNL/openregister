<?php

/**
 * FederationController::createObject() identity tests.
 *
 * A federated share grants its holder the right to ADD objects to the shared
 * register/schema. This pins that it grants nothing more.
 *
 * The path is `#[PublicPage]`, `#[NoCSRFRequired]` and calls `saveObject()`
 * with `_rbac: false` and `_multitenancy: false`, so nothing downstream will
 * refuse a write it lets through. `saveObject()` resolves its target FROM the
 * payload — `extractUuidAndNormalizeObject()` reads `@self.id` first, then
 * `id` — and the write is PUT-semantic, so every field the payload omits is
 * NULLED rather than left alone.
 *
 * The organisation pin already guarded the sibling half of this ("a federated
 * writer can never plant an object into another organisation"), but it merged
 * with `+`, which preserves the LEFT operand's keys, so a caller-supplied
 * `@self: {id: …}` survived it untouched.
 *
 * An update or delete only reaches an object the share could read: the
 * organisation and confidentiality filter of the read path runs on the stored
 * object first, and an update pins the share's organisation as create does.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Tests\Unit\Controller\Support\FederationControllerFactory;
use OCA\OpenRegister\Controller\FederationController;
use OCA\OpenRegister\Db\FederatedShare;
use OCA\OpenRegister\Db\FederatedShareMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\FederationShareService;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\Security\Bruteforce\IThrottler;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Service\Object\ObjectDeletion;

/**
 * A federated create may not address an existing object.
 */
class FederationControllerCreateIdentityTest extends TestCase {
	/**
	 * The share mapper.
	 *
	 * @var FederatedShareMapper&MockObject
	 */
	private $shareMapper;

	/**
	 * The object service.
	 *
	 * @var ObjectService&MockObject
	 */
	private $objectService;

	/**
	 * The payload the controller handed to saveObject.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $written = null;

	/**
	 * The uuid the controller handed to deleteObject.
	 *
	 * @var string|null
	 */
	private ?string $deleted = null;

	/**
	 * The share the token resolves to; a case may narrow it after building the controller.
	 *
	 * @var FederatedShare
	 */
	private FederatedShare $share;

	/**
	 * Build the controller over a request carrying the given body.
	 *
	 * @param array<string, mixed> $params The request parameters.
	 * @param ObjectEntity|null    $stored The stored object an update or delete addresses; null is not found.
	 *
	 * @return FederationController The controller.
	 */
	private function controller(array $params, ?ObjectEntity $stored = null): FederationController {
		$share = new FederatedShare();
		$share->setDirection('outgoing');
		$share->setStatus('accepted');
		$share->setScope('collection');
		$share->setRegister('zaken');
		$share->setSchema('zaak');
		$share->setOrganisation('org-a');
		$share->setPermissions('read-write');
		$this->share = $share;

		$this->shareMapper = $this->createMock(FederatedShareMapper::class);
		$this->shareMapper->method('findByToken')->willReturn($share);

		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn($params);

		$this->objectService = $this->createMock(ObjectService::class);
		$this->objectService->method('saveObject')->willReturnCallback(
			function (...$args) {
				// saveObject is called with named arguments; the object is the
				// first, however PHPUnit hands it back.
				$this->written = (array)($args[0] ?? []);
				return $this->createMock(ObjectEntity::class);
			}
		);
		$this->objectService->method('deleteObject')->willReturnCallback(
			function (ObjectDeletion $deletion, bool $_rbac = true, bool $_multitenancy = true, bool $_retentionSweep = false): bool {
				$args = [$deletion->uuid, $deletion->target->register, $deletion->target->schema, $_rbac, $_multitenancy, $_retentionSweep, $deletion->currentUser, $deletion->permanent];
				$this->deleted = (string)($args[0] ?? '');
				return true;
			}
		);
		if ($stored === null) {
			$this->objectService->method('find')->willThrowException(new DoesNotExistException('not found'));
		} else {
			$this->objectService->method('find')->willReturn($stored);
		}

		return FederationControllerFactory::create(
			'openregister',
			$request,
			$this->shareMapper,
			$this->objectService,
			$this->createMock(FederationShareService::class),
			$this->createMock(IThrottler::class),
			$this->createMock(LoggerInterface::class)
		);
	}//end controller()

	/**
	 * 🔴 A caller-supplied `@self.id` must not reach the write.
	 *
	 * It is the key `saveObject()` reads FIRST, and this path disables RBAC, so
	 * nothing downstream would have refused the resulting update.
	 *
	 * @return void
	 */
	public function testASuppliedSelfIdDoesNotReachTheWrite(): void {
		$controller = $this->controller(
			[
				'title' => 'Federated addition',
				'@self' => ['id' => 'someone-elses-object'],
			]
		);

		$response = $controller->createObject(shareToken: 'tok');

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertIsArray($this->written, 'the write must have happened');
		$this->assertArrayNotHasKey('id', $this->written['@self'] ?? []);
		$this->assertArrayNotHasKey('uuid', $this->written['@self'] ?? []);
	}//end testASuppliedSelfIdDoesNotReachTheWrite()

	/**
	 * A top-level `id` or `uuid` must not reach the write either.
	 *
	 * `@self.id` is read first, but `id` is the fallback, so stripping only the
	 * nested one would leave the same hole one key over.
	 *
	 * @return void
	 */
	public function testASuppliedTopLevelIdDoesNotReachTheWrite(): void {
		$controller = $this->controller(
			[
				'title' => 'Federated addition',
				'id' => 'someone-elses-object',
				'uuid' => 'someone-elses-object',
			]
		);

		$controller->createObject(shareToken: 'tok');

		$this->assertIsArray($this->written);
		$this->assertArrayNotHasKey('id', $this->written);
		$this->assertArrayNotHasKey('uuid', $this->written);
	}//end testASuppliedTopLevelIdDoesNotReachTheWrite()

	/**
	 * The organisation pin still holds, and the payload still arrives.
	 *
	 * The guard must not have traded one defect for another: stripping too much
	 * would break federated writes outright, and dropping the pin would let a
	 * writer plant an object into another organisation.
	 *
	 * @return void
	 */
	public function testTheOrganisationPinAndThePayloadSurvive(): void {
		$controller = $this->controller(
			[
				'title' => 'Federated addition',
				'@self' => ['id' => 'someone-elses-object', 'organisation' => 'org-attacker'],
			]
		);

		$controller->createObject(shareToken: 'tok');

		$this->assertIsArray($this->written);
		$this->assertSame('org-a', $this->written['@self']['organisation'] ?? null);
		$this->assertSame('Federated addition', $this->written['title'] ?? null);
	}//end testTheOrganisationPinAndThePayloadSurvive()

	/**
	 * A stored object of the given organisation and confidentiality.
	 *
	 * @param string $organisation    The record's organisation.
	 * @param string $confidentiality Its confidentiality.
	 *
	 * @return ObjectEntity The object.
	 */
	private function stored(string $organisation, string $confidentiality = 'openbaar'): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid('obj-1');
		$entity->setOrganisation($organisation);
		$entity->setObject(['title' => 'Stored', 'confidentiality' => $confidentiality]);

		return $entity;
	}//end stored()

	/**
	 * An update keeps the share's organisation, whatever the body names.
	 *
	 * @return void
	 */
	public function testAnUpdatePinsTheSharesOrganisation(): void {
		$controller = $this->controller(['title' => 'Changed', '@self' => ['organisation' => 'org-attacker']], $this->stored(organisation: 'org-a'));

		$response = $controller->updateObject(shareToken: 'tok', id: 'obj-1');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame('org-a', $this->written['@self']['organisation'] ?? null);
		$this->assertSame('Changed', $this->written['title'] ?? null);
	}//end testAnUpdatePinsTheSharesOrganisation()

	/**
	 * Data the share may not read cannot be overwritten or deleted through it either.
	 *
	 * @return array<string, array{0: ObjectEntity|null}>
	 */
	public static function unreachableTargets(): array {
		$other = new ObjectEntity();
		$other->setUuid('obj-1');
		$other->setOrganisation('org-b');
		$other->setObject(['title' => 'Theirs', 'confidentiality' => 'openbaar']);
		$secret = new ObjectEntity();
		$secret->setUuid('obj-1');
		$secret->setOrganisation('org-a');
		$secret->setObject(['title' => 'Secret', 'confidentiality' => 'geheim']);

		return [
			'another organisation' => [$other],
			'a confidential object' => [$secret],
			'no such object' => [null],
		];
	}//end unreachableTargets()

	/**
	 * An update of an object the share cannot reach is a 404 and writes nothing.
	 *
	 * @param ObjectEntity|null $stored The stored object.
	 *
	 * @return void
	 *
	 * @dataProvider unreachableTargets
	 */
	public function testAnUnreachableObjectIsNotUpdated(?ObjectEntity $stored): void {
		$controller = $this->controller(['title' => 'Changed'], $stored);

		$response = $controller->updateObject(shareToken: 'tok', id: 'obj-1');

		$this->assertSame(404, $response->getStatus());
		$this->assertNull($this->written, 'the update reached the object store');
	}//end testAnUnreachableObjectIsNotUpdated()

	/**
	 * A delete of an object the share cannot reach is a 404 and deletes nothing.
	 *
	 * @param ObjectEntity|null $stored The stored object.
	 *
	 * @return void
	 *
	 * @dataProvider unreachableTargets
	 */
	public function testAnUnreachableObjectIsNotDeleted(?ObjectEntity $stored): void {
		$controller = $this->controller([], $stored);

		$response = $controller->deleteObject(shareToken: 'tok', id: 'obj-1');

		$this->assertSame(404, $response->getStatus());
		$this->assertNull($this->deleted, 'the delete reached the object store');
	}//end testAnUnreachableObjectIsNotDeleted()

	/**
	 * An object the share reaches is still deleted.
	 *
	 * @return void
	 */
	public function testAReachableObjectIsDeleted(): void {
		$controller = $this->controller([], $this->stored(organisation: 'org-a'));

		$response = $controller->deleteObject(shareToken: 'tok', id: 'obj-1');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame('obj-1', $this->deleted);
	}//end testAReachableObjectIsDeleted()

	/**
	 * An update stores the sharing organisation over the one the body names (ORX3 probe F1).
	 *
	 * @return void
	 */
	public function testProbeUpdateKeepsTheSharingOrganisation(): void {
		$controller = $this->controller(['title' => 'x', '@self' => ['organisation' => 'org-b']], $this->recordOf(organisation: 'org-a'));

		$controller->updateObject(shareToken: 'tok', id: 'obj-1');

		$this->assertSame('org-a', $this->written['@self']['organisation'] ?? null);
	}//end testProbeUpdateKeepsTheSharingOrganisation()

	/**
	 * A record of another organisation, by its own organisation field, is neither updated nor deleted (ORX3 probe F2, F3).
	 *
	 * @return void
	 */
	public function testProbeAnotherOrganisationsRecordIsNotWritten(): void {
		$controller = $this->controller(['title' => 'x'], $this->recordOf(organisation: 'org-b'));

		$this->assertSame(404, $controller->updateObject(shareToken: 'tok', id: 'obj-1')->getStatus());
		$this->assertSame(404, $controller->deleteObject(shareToken: 'tok', id: 'obj-1')->getStatus());
		$this->assertNull($this->written);
		$this->assertNull($this->deleted);
	}//end testProbeAnotherOrganisationsRecordIsNotWritten()

	/**
	 * A query-scope share reaches by id only what its query selects, on read and on write.
	 *
	 * @return void
	 */
	public function testAQueryShareReachesOnlyWhatItsQuerySelects(): void {
		$controller = $this->controller(['title' => 'x'], $this->recordOf(organisation: 'org-a'));
		$this->share->setScope('query');
		$this->share->setQueryFilter(['status' => 'open']);
		$asked = [];
		$this->objectService->method('findAll')->willReturnCallback(
			static function (...$args) use (&$asked): array {
				$asked[] = ($args['config'] ?? $args[0]);
				return [];
			}
		);

		$this->assertSame(404, $controller->object(shareToken: 'tok', id: 'obj-1')->getStatus());
		$this->assertSame(404, $controller->updateObject(shareToken: 'tok', id: 'obj-1')->getStatus());
		$this->assertSame(404, $controller->deleteObject(shareToken: 'tok', id: 'obj-1')->getStatus());
		$this->assertNull($this->written);
		$this->assertNull($this->deleted);
		$this->assertSame(['status' => 'open'], $asked[0]['filters']);
		$this->assertSame(['obj-1'], $asked[0]['ids']);
	}//end testAQueryShareReachesOnlyWhatItsQuerySelects()

	/**
	 * An object the query selects is still updated through a query share.
	 *
	 * @return void
	 */
	public function testAQueryShareStillWritesWhatItsQuerySelects(): void {
		$controller = $this->controller(['title' => 'x'], $this->recordOf(organisation: 'org-a'));
		$this->share->setScope('query');
		$this->share->setQueryFilter(['status' => 'open']);
		$this->objectService->method('findAll')->willReturn([$this->recordOf(organisation: 'org-a')]);

		$this->assertSame(200, $controller->updateObject(shareToken: 'tok', id: 'obj-1')->getStatus());
		$this->assertSame('x', $this->written['title'] ?? null);
	}//end testAQueryShareStillWritesWhatItsQuerySelects()

	/**
	 * A share without an organisation writes nothing and reads no organisation's object.
	 *
	 * @return void
	 */
	public function testAShareWithoutAnOrganisationFailsClosed(): void {
		foreach ([null, ''] as $organisation) {
			$this->written = null;
			$this->deleted = null;
			$controller = $this->controller(['title' => 'x'], $this->recordOf(organisation: 'org-a'));
			$this->share->setOrganisation($organisation);

			$this->assertSame(403, $controller->createObject(shareToken: 'tok')->getStatus());
			$this->assertSame(403, $controller->updateObject(shareToken: 'tok', id: 'obj-1')->getStatus());
			$this->assertSame(403, $controller->deleteObject(shareToken: 'tok', id: 'obj-1')->getStatus());
			$this->assertSame(404, $controller->object(shareToken: 'tok', id: 'obj-1')->getStatus());
			$this->assertNull($this->written);
			$this->assertNull($this->deleted);
		}

		$controller = $this->controller([], $this->recordOf(organisation: null));
		$this->share->setOrganisation('');
		$this->assertSame(200, $controller->object(shareToken: 'tok', id: 'obj-1')->getStatus(), 'an object without an organisation');
	}//end testAShareWithoutAnOrganisationFailsClosed()

	/**
	 * A record whose stored data claims an organisation is judged by its own organisation field.
	 *
	 * `jsonSerialize()` lets a data `@self.organisation` override the record's
	 * organisation, so a guard reading the rendered object would serve an org-b
	 * record to an org-a share. Single-object paths and the collection alike.
	 *
	 * @return void
	 */
	public function testAStoredSelfOrganisationDoesNotAnswerTheGuard(): void {
		$forged = $this->recordOf(organisation: 'org-b');
		$forged->setObject(['title' => 'Theirs', '@self' => ['organisation' => 'org-a']]);
		$controller = $this->controller(['title' => 'x'], $forged);
		$this->objectService->method('findAll')->willReturn([$forged, $this->recordOf(organisation: 'org-a')]);

		$this->assertSame(404, $controller->object(shareToken: 'tok', id: 'obj-1')->getStatus());
		$this->assertSame(404, $controller->updateObject(shareToken: 'tok', id: 'obj-1')->getStatus());
		$this->assertSame(404, $controller->deleteObject(shareToken: 'tok', id: 'obj-1')->getStatus());
		$this->assertNull($this->written);
		$this->assertNull($this->deleted);

		$served = $controller->objects(shareToken: 'tok')->getData();
		$this->assertSame(1, $served['total']);
		$this->assertSame('Stored', $served['results'][0]['title'] ?? null);
	}//end testAStoredSelfOrganisationDoesNotAnswerTheGuard()

	/**
	 * A record without an organisation is not given one by a plain data `organisation` property.
	 *
	 * @return void
	 */
	public function testAnOrganisationLessRecordIsNotAnsweredByItsData(): void {
		$record = $this->recordOf(organisation: null);
		$record->setObject(['title' => 'Stored', 'organisation' => 'org-a']);
		$controller = $this->controller(['title' => 'x'], $record);
		$this->objectService->method('findAll')->willReturn([$record]);

		$this->assertSame(404, $controller->object(shareToken: 'tok', id: 'obj-1')->getStatus());
		$this->assertSame(404, $controller->updateObject(shareToken: 'tok', id: 'obj-1')->getStatus());
		$this->assertSame(404, $controller->deleteObject(shareToken: 'tok', id: 'obj-1')->getStatus());
		$this->assertNull($this->written);
		$this->assertNull($this->deleted);
		$this->assertSame(0, $controller->objects(shareToken: 'tok')->getData()['total']);
	}//end testAnOrganisationLessRecordIsNotAnsweredByItsData()

	/**
	 * A query share's own `uuid` filter is kept and the addressed record is added to it, not swapped in for it.
	 *
	 * @return void
	 */
	public function testAQueryShareKeepsItsOwnUuidFilter(): void {
		$controller = $this->controller(['title' => 'x'], $this->recordOf(organisation: 'org-a'));
		$this->share->setScope('query');
		$this->share->setQueryFilter(['uuid' => 'obj-2']);
		$asked = [];
		$this->objectService->method('findAll')->willReturnCallback(
			static function (...$args) use (&$asked): array {
				$asked[] = ($args['config'] ?? $args[0]);
				return [];
			}
		);

		$this->assertSame(404, $controller->updateObject(shareToken: 'tok', id: 'obj-1')->getStatus());
		$this->assertSame(404, $controller->deleteObject(shareToken: 'tok', id: 'obj-1')->getStatus());
		$this->assertSame(404, $controller->object(shareToken: 'tok', id: 'obj-1')->getStatus());
		$this->assertNull($this->written);
		$this->assertNull($this->deleted);
		$this->assertSame('obj-2', $asked[0]['filters']['uuid'] ?? null);
		$this->assertSame(['obj-1'], $asked[0]['ids'] ?? null);
	}//end testAQueryShareKeepsItsOwnUuidFilter()

	/**
	 * A query hit on another row (a slug equal to the addressed uuid) does not cover the addressed record.
	 *
	 * @return void
	 */
	public function testAQueryHitOnAnotherRecordDoesNotCoverTheAddressedOne(): void {
		$controller = $this->controller(['title' => 'x'], $this->recordOf(organisation: 'org-a'));
		$this->share->setScope('query');
		$this->share->setQueryFilter(['status' => 'open']);
		$other = $this->recordOf(organisation: 'org-a');
		$other->setUuid('obj-9');
		$other->setSlug('obj-1');
		$this->objectService->method('findAll')->willReturn([$other]);

		$this->assertSame(404, $controller->updateObject(shareToken: 'tok', id: 'obj-1')->getStatus());
		$this->assertNull($this->written);
	}//end testAQueryHitOnAnotherRecordDoesNotCoverTheAddressedOne()

	/**
	 * A record without a uuid is never covered by a query share, even by a hit without one.
	 *
	 * @return void
	 */
	public function testARecordWithoutAUuidIsNotCoveredByAQueryShare(): void {
		$record = $this->recordOf(organisation: 'org-a');
		$record->setUuid('');
		$controller = $this->controller(['title' => 'x'], $record);
		$this->share->setScope('query');
		$this->share->setQueryFilter(['status' => 'open']);
		$this->objectService->method('findAll')->willReturn([$record]);

		$this->assertSame(404, $controller->updateObject(shareToken: 'tok', id: 'obj-1')->getStatus());
		$this->assertNull($this->written);
	}//end testARecordWithoutAUuidIsNotCoveredByAQueryShare()

	/**
	 * A row that is not a record carries no organisation field of its own, so the collection does not serve it.
	 *
	 * @return void
	 */
	public function testACollectionRowThatIsNotARecordIsNotServed(): void {
		$controller = $this->controller([]);
		$this->objectService->method('findAll')->willReturn([['title' => 'Raw', '@self' => ['organisation' => 'org-a']]]);

		$this->assertSame(0, $controller->objects(shareToken: 'tok')->getData()['total']);
	}//end testACollectionRowThatIsNotARecordIsNotServed()

	/**
	 * A stored record whose own organisation field is set, with an empty data body.
	 *
	 * @param string|null $organisation The record's organisation.
	 *
	 * @return ObjectEntity The record.
	 */
	private function recordOf(?string $organisation): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid('obj-1');
		$entity->setOrganisation($organisation);
		$entity->setObject(['title' => 'Stored']);

		return $entity;
	}//end recordOf()
}//end class
