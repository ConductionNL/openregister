<?php

/**
 * Tests that federated writes stay inside the share's scope.
 *
 * A delete hands the pipeline a reach that answers whether each object the delete would
 * change (a cascade child, or a dependent the onDelete rules delete or rewrite) is inside
 * the share. A create on an object share is refused; on a query share it must meet the
 * share's query.
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
 * @spec openspec/specs/federation-scope-enforcement/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\FederationController;
use OCA\OpenRegister\Db\FederatedShare;
use OCA\OpenRegister\Db\FederatedShareMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Dto\DeletionAnalysis;
use OCA\OpenRegister\Exception\ReferentialIntegrityException;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectDeletion;
use OCA\OpenRegister\Service\FederationShareService;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Tests\Unit\Controller\Support\FederationControllerFactory;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IRequest;
use OCP\Security\Bruteforce\IThrottler;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Federated deletes and creates stay inside the share.
 */
class FederationControllerWriteScopeTest extends TestCase {

	private ObjectService&MockObject $objectService;

	private FederatedShare $share;

	/**
	 * The deletion the controller handed to the object service.
	 *
	 * @var ObjectDeletion|null
	 */
	private ?ObjectDeletion $deletion = null;

	/**
	 * Whether the controller handed a payload to saveObject.
	 *
	 * @var bool
	 */
	private bool $saved = false;

	/**
	 * Build the controller over a schema share of org-a whose objects are those named.
	 *
	 * @param array<string, mixed>         $params  The request parameters.
	 * @param array<string, ObjectEntity>  $objects The stored objects the share's table holds, by uuid.
	 *
	 * @return FederationController
	 */
	private function controller(array $params = [], array $objects = []): FederationController {
		$this->share = new FederatedShare();
		$this->share->setDirection('outgoing');
		$this->share->setStatus('accepted');
		$this->share->setScope('collection');
		$this->share->setRegister('zaken');
		$this->share->setSchema('zaak');
		$this->share->setOrganisation('org-a');
		$this->share->setPermissions('read-write');

		$shareMapper = $this->createMock(FederatedShareMapper::class);
		$shareMapper->method('findByToken')->willReturnCallback(fn (): FederatedShare => $this->share);

		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn($params);

		$this->objectService = $this->createMock(ObjectService::class);
		$this->objectService->method('find')->willReturnCallback(
			static function (string|int $id) use ($objects): ObjectEntity {
				if (isset($objects[(string)$id]) === false) {
					throw new DoesNotExistException('not found');
				}

				return $objects[(string)$id];
			}
		);
		$this->objectService->method('saveObject')->willReturnCallback(
			function (): ObjectEntity {
				$this->saved = true;
				return $this->createMock(ObjectEntity::class);
			}
		);

		return FederationControllerFactory::create(
			'openregister',
			$request,
			$shareMapper,
			$this->objectService,
			$this->createMock(FederationShareService::class),
			$this->createMock(IThrottler::class),
			$this->createMock(LoggerInterface::class)
		);
	}//end controller()

	/**
	 * A stored object.
	 *
	 * @param string $uuid         The uuid.
	 * @param string $organisation The organisation.
	 *
	 * @return ObjectEntity
	 */
	private static function stored(string $uuid, string $organisation = 'org-a'): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setOrganisation($organisation);
		$entity->setObject(['title' => 'Stored', 'confidentiality' => 'openbaar']);

		return $entity;
	}//end stored()

	/**
	 * The delete's reach covers objects the share reaches and nothing else.
	 *
	 * @return void
	 */
	public function testADeleteReachesOnlyObjectsInsideTheShare(): void {
		$controller = $this->controller(
			objects: ['root' => self::stored('root'), 'inside' => self::stored('inside'), 'other-org' => self::stored('other-org', 'org-b')]
		);
		$this->objectService->method('deleteObject')->willReturnCallback(
			function (ObjectDeletion $deletion): bool {
				$this->deletion = $deletion;
				return true;
			}
		);

		$this->assertSame(200, $controller->deleteObject(shareToken: 'tok', id: 'root')->getStatus());

		$reach = $this->deletion?->reach;
		$this->assertNotNull($reach, 'the delete ran without a reach');
		$this->assertTrue($reach('inside'));
		$this->assertFalse($reach('other-org'), 'another organisation is inside the reach');
		$this->assertFalse($reach('elsewhere'), 'an object outside the share table is inside the reach');
		$this->assertFalse($reach(''));
	}//end testADeleteReachesOnlyObjectsInsideTheShare()

	/**
	 * A delete the reach blocked answers 409 with a message that says so.
	 *
	 * @return void
	 */
	public function testABlockedDeleteSaysWhy(): void {
		$controller = $this->controller(objects: ['root' => self::stored('root')]);
		$this->objectService->method('deleteObject')->willThrowException(
			new ReferentialIntegrityException(new DeletionAnalysis(deletable: false, blockers: [['reason' => 'out_of_reach', 'property' => 'zaak']]))
		);

		$response = $controller->deleteObject(shareToken: 'tok', id: 'root');

		$this->assertSame(409, $response->getStatus());
		$this->assertStringContainsString('outside this share', $response->getData()['error']);
	}//end testABlockedDeleteSaysWhy()

	/**
	 * An object share creates nothing.
	 *
	 * @return void
	 */
	public function testAnObjectShareCreatesNothing(): void {
		$controller = $this->controller(params: ['title' => 'New']);
		$this->share->setScope('object');
		$this->share->setObjectUri('granted-uuid');

		$this->assertSame(403, $controller->createObject(shareToken: 'tok')->getStatus());
		$this->assertFalse($this->saved);
	}//end testAnObjectShareCreatesNothing()

	/**
	 * The query cases for a create.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: array<string, mixed>, 2: bool}>
	 */
	public static function queryCreates(): array {
		return [
			'meets a value' => [['status' => 'open'], ['status' => 'open'], true],
			'meets one of a list' => [['status' => ['open', 'new']], ['status' => 'new'], true],
			'another value' => [['status' => 'open'], ['status' => 'closed'], false],
			'value missing' => [['status' => 'open'], ['title' => 'x'], false],
			'an operator' => [['age' => ['gte' => 3]], ['age' => 5], false],
			'an id list' => [['_ids' => ['a']], ['title' => 'x'], false],
			'a search' => [['_search' => 'x'], ['title' => 'x'], false],
		];
	}//end queryCreates()

	/**
	 * A query share creates only an object its query selects by plain values.
	 *
	 * @param array<string, mixed> $query   The share's query.
	 * @param array<string, mixed> $payload The object to create.
	 * @param bool                 $created Whether it is created.
	 *
	 * @return void
	 *
	 * @dataProvider queryCreates
	 */
	public function testAQueryShareCreatesOnlyInsideItsQuery(array $query, array $payload, bool $created): void {
		$controller = $this->controller(params: $payload);
		$this->share->setScope('query');
		$this->share->setQueryFilter($query);

		$status = $controller->createObject(shareToken: 'tok')->getStatus();

		$this->assertSame($created ? 201 : 403, $status);
		$this->assertSame($created, $this->saved);
	}//end testAQueryShareCreatesOnlyInsideItsQuery()

	/**
	 * A schema share still creates.
	 *
	 * @return void
	 */
	public function testASchemaShareStillCreates(): void {
		$controller = $this->controller(params: ['title' => 'New']);

		$this->assertSame(201, $controller->createObject(shareToken: 'tok')->getStatus());
		$this->assertTrue($this->saved);
	}//end testASchemaShareStillCreates()
}//end class
