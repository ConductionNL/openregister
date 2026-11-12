<?php

/**
 * Golden outcomes of the party REST surface, allowed and refused.
 *
 * Pins status and full body of every route of PartyController: the object
 * resolution and its 404s, the update right a party write asks (and its
 * refusal), the error mapping, and the party reads.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
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
 * @spec openspec/specs/party-model/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use Exception;
use OCA\OpenRegister\Controller\PartyController;
use OCA\OpenRegister\Db\ContactLink;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Service\Object\PermissionCheckOptions;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\Party\PartyDefinition;
use OCA\OpenRegister\Service\Party\PartyIndicatorGuard;
use OCA\OpenRegister\Service\Party\PartyHolding;
use OCA\OpenRegister\Service\Party\PartyRoleService;
use OCA\OpenRegister\Service\Party\PartySearchService;
use OCA\OpenRegister\Service\Party\PartyService;
use OCA\OpenRegister\Tests\Unit\Controller\Support\PartyControllerFactory;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Allowed and refused outcomes of the party API.
 */
final class PartyControllerA24GoldenTest extends TestCase {

	/**
	 * The request parameters.
	 *
	 * @var array<string, mixed>
	 */
	private array $params = [];

	/**
	 * What the object layer was told, in order.
	 *
	 * @var array<int, string>
	 */
	private array $trail = [];

	/**
	 * Object service.
	 *
	 * @var ObjectService&MockObject
	 */
	private $objects;

	/**
	 * Role service.
	 *
	 * @var PartyRoleService&MockObject
	 */
	private $roles;

	/**
	 * Party service.
	 *
	 * @var PartyService&MockObject
	 */
	private $parties;

	/**
	 * Indicator guard.
	 *
	 * @var PartyIndicatorGuard&MockObject
	 */
	private $indicators;

	/**
	 * Search service.
	 *
	 * @var PartySearchService&MockObject
	 */
	private $search;

	/**
	 * Permission handler.
	 *
	 * @var PermissionHandler&MockObject
	 */
	private $permissions;

	protected function setUp(): void {
		$this->objects = $this->createMock(ObjectService::class);
		foreach (['setRegister', 'setSchema', 'setObject'] as $method) {
			$this->objects->method($method)->willReturnCallback(
				function ($value) use ($method) {
					$this->trail[] = $method . ':' . $value;

					return $this->objects;
				}
			);
		}

		$this->roles = $this->createMock(PartyRoleService::class);
		$this->parties = $this->createMock(PartyService::class);
		$this->indicators = $this->createMock(PartyIndicatorGuard::class);
		$this->search = $this->createMock(PartySearchService::class);
		$this->permissions = $this->createMock(PermissionHandler::class);
	}//end setUp()

	private function controller(): PartyController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(fn (string $key, mixed $default = null): mixed => ($this->params[$key] ?? $default));
		$request->method('getParams')->willReturnCallback(fn (): array => $this->params);

		return PartyControllerFactory::create(
			'openregister',
			$request,
			$this->objects,
			$this->roles,
			$this->parties,
			$this->indicators,
			$this->search,
			$this->permissions
		);
	}//end controller()

	private function caseObject(): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid('case-1');
		$object->setRegister('3');
		$object->setSchema('7');
		$object->setOwner('marleen');
		$object->setObject([]);

		return $object;
	}//end caseObject()

	private function link(): ContactLink {
		$link = new ContactLink();
		$link->setObjectUuid('case-1');
		$link->setPartyUuid('party-a');
		$link->setRole('aanvrager');

		return $link;
	}//end link()

	private function outcome($response): array {
		return [$response->getStatus(), $response->getData()];
	}//end outcome()

	private function objectIs(?ObjectEntity $object): void {
		$this->objects->method('getObject')->willReturnCallback(
			function () use ($object): ?ObjectEntity {
				$this->trail[] = 'getObject';

				return $object;
			}
		);
	}//end objectIs()

	public function testIndexListsThePartiesOfTheResolvedObject(): void {
		$this->objectIs($this->caseObject());
		$this->roles->expects($this->once())->method('listForObject')->with('case-1', '7')->willReturn(['results' => ['x'], 'total' => 1]);
		$this->permissions->expects($this->never())->method('checkPermission');

		$this->assertSame([200, ['results' => ['x'], 'total' => 1]], $this->outcome($this->controller()->index('zaken', 'zaak', 'case-1')));
		$this->assertSame(['setRegister:zaken', 'setSchema:zaak', 'setObject:case-1', 'getObject'], $this->trail);
	}//end testIndexListsThePartiesOfTheResolvedObject()

	public function testAMissingObjectIs404(): void {
		$this->objectIs(null);
		$this->roles->expects($this->never())->method($this->anything());

		$notFound = [404, ['error' => 'Object not found']];
		$this->assertSame($notFound, $this->outcome($this->controller()->index('zaken', 'zaak', 'x')));
		$this->assertSame($notFound, $this->outcome($this->controller()->create('zaken', 'zaak', 'x')));
		$this->assertSame($notFound, $this->outcome($this->controller()->destroy('zaken', 'zaak', 'x', 'party-a')));
	}//end testAMissingObjectIs404()

	public function testAnUnresolvableObjectIs404AndOtherFailuresKeepTheirStatus(): void {
		$this->objects->method('getObject')->willThrowException(new DoesNotExistException('gone'));
		$this->assertSame([404, ['error' => 'Object not found']], $this->outcome($this->controller()->index('zaken', 'zaak', 'x')));
	}//end testAnUnresolvableObjectIs404AndOtherFailuresKeepTheirStatus()

	public function testAnObjectLayerFailureIsMappedToItsStatus(): void {
		$this->objects->method('getObject')->willReturnOnConsecutiveCalls(
			$this->throwException(new Exception('locked', 409)),
			$this->throwException(new Exception('boom', 500)),
			$this->throwException(new Exception('denied', 403))
		);

		$this->assertSame([409, ['error' => 'locked']], $this->outcome($this->controller()->index('zaken', 'zaak', 'x')));
		$this->assertSame([400, ['error' => 'boom']], $this->outcome($this->controller()->index('zaken', 'zaak', 'x')));
		$this->assertSame([403, ['error' => 'denied']], $this->outcome($this->controller()->create('zaken', 'zaak', 'x')));
	}//end testAnObjectLayerFailureIsMappedToItsStatus()

	public function testAWriteWithoutAReadableSchemaIsRefusedWith403(): void {
		$this->objectIs($this->caseObject());
		$this->objects->method('getCurrentSchemaEntity')->willReturn(null);
		$this->roles->expects($this->never())->method('addParty');
		$this->permissions->expects($this->never())->method('checkPermission');

		$this->assertSame(
			[403, ['error' => 'The object\'s schema could not be read, so its update right cannot be asked']],
			$this->outcome($this->controller()->create('zaken', 'zaak', 'case-1'))
		);
	}//end testAWriteWithoutAReadableSchemaIsRefusedWith403()

	public function testAWriteWithoutTheUpdateRightIsRefused(): void {
		$this->objectIs($this->caseObject());
		$this->objects->method('getCurrentSchemaEntity')->willReturn(new Schema());
		$this->permissions->method('checkPermission')->willThrowException(new NotAuthorizedException('No update right'));
		$this->roles->expects($this->never())->method($this->anything());
		$this->params = ['partyUuid' => 'party-b'];

		$refusal = [403, ['error' => 'No update right']];
		$this->assertSame($refusal, $this->outcome($this->controller()->create('zaken', 'zaak', 'case-1')));
		$this->assertSame($refusal, $this->outcome($this->controller()->replacePrimary('zaken', 'zaak', 'case-1')));
		$this->assertSame($refusal, $this->outcome($this->controller()->destroy('zaken', 'zaak', 'case-1', 'party-a')));
	}//end testAWriteWithoutTheUpdateRightIsRefused()

	public function testTheWritesWithTheUpdateRight(): void {
		$object = $this->caseObject();
		$this->objectIs($object);
		$schema = new Schema();
		$this->objects->method('getCurrentSchemaEntity')->willReturn($schema);
		$asked = new PermissionCheckOptions(objectOwner: 'marleen', object: $object);
		$this->permissions->expects($this->exactly(4))->method('checkPermission')->with($schema, 'update', $asked, true);
		$this->params = ['partyUuid' => ' party-b ', 'role' => '  ', 'kind' => 'person'];
		$this->roles->expects($this->once())->method('addParty')->with('case-1', 3, '7', $this->params)->willReturn($this->link());
		$this->roles->expects($this->once())->method('replacePrimaryParty')->with($object, 'party-b', null)->willReturn($this->link());
		$this->roles->expects($this->once())->method('removeParty')->with('case-1', new PartyHolding('party-a', null))->willReturn(2);

		$this->assertSame([201, $this->link()->jsonSerialize()], $this->outcome($this->controller()->create('zaken', 'zaak', 'case-1')));
		$this->assertSame([200, $this->link()->jsonSerialize()], $this->outcome($this->controller()->replacePrimary('zaken', 'zaak', 'case-1')));
		$this->assertSame([200, ['removed' => 2]], $this->outcome($this->controller()->destroy('zaken', 'zaak', 'case-1', 'party-a')));

		$this->params = ['partyUuid' => ''];
		$this->assertSame([400, ['error' => 'partyUuid is required']], $this->outcome($this->controller()->replacePrimary('zaken', 'zaak', 'case-1')));
	}//end testTheWritesWithTheUpdateRight()

	public function testShowAnswersThePartyOrTheRefusal(): void {
		$party = new ObjectEntity();
		$party->setUuid('party-a');
		$definition = new PartyDefinition(kind: 'organisation');
		$this->parties->method('find')->willReturnMap([['party-a', $party], ['nobody', null], ['plain', $party]]);
		$this->parties->method('definitionFor')->willReturnOnConsecutiveCalls($definition, null);
		$this->parties->method('addresses')->willReturn(['a@b']);
		$this->parties->method('indicators')->willReturn(['vip']);
		$this->indicators->method('objectsOfParty')->willReturn(['case-1']);

		$this->assertSame(
			[200, ['uuid' => 'party-a', 'kind' => 'organisation', 'addresses' => ['a@b'], 'indicators' => ['vip'], 'objects' => ['case-1']]],
			$this->outcome($this->controller()->show('party-a'))
		);
		$this->assertSame([404, ['error' => 'Party not found']], $this->outcome($this->controller()->show('nobody')));
		$this->assertSame([400, ['error' => 'Object "plain" is not a party']], $this->outcome($this->controller()->show('plain')));
	}//end testShowAnswersThePartyOrTheRefusal()

	public function testShowAndSearchMapFailures(): void {
		$this->parties->method('find')->willThrowException(new Exception('forbidden', 403));
		$this->search->method('search')->willThrowException(new Exception('over the cap', 0));

		$this->assertSame([403, ['error' => 'forbidden']], $this->outcome($this->controller()->show('party-a')));
		$this->assertSame([400, ['error' => 'over the cap']], $this->outcome($this->controller()->search()));
	}//end testShowAndSearchMapFailures()

	public function testSearchPassesTheQuery(): void {
		$this->params = ['q' => 'jan', 'schema' => ' '];
		$this->search->expects($this->once())->method('search')->with('jan', null)->willReturn(['results' => [], 'total' => 0]);

		$this->assertSame([200, ['results' => [], 'total' => 0]], $this->outcome($this->controller()->search()));
	}//end testSearchPassesTheQuery()

	public function testResolve(): void {
		$party = new ObjectEntity();
		$party->setUuid('party-a');
		$party->setRegister('3');
		$party->setSchema('9');
		$this->parties->method('resolveByAddress')->willReturnMap([['a@b', $party], ['x@y', null]]);
		$this->parties->method('addresses')->willReturn(['a@b']);

		$this->params = [];
		$this->assertSame([400, ['error' => 'address is required']], $this->outcome($this->controller()->resolve()));
		$this->params = ['address' => ' x@y '];
		$this->assertSame([404, ['error' => 'No party holds that address']], $this->outcome($this->controller()->resolve()));
		$this->params = ['address' => 'a@b'];
		$this->assertSame(
			[200, ['uuid' => 'party-a', 'register' => '3', 'schema' => '9', 'addresses' => ['a@b']]],
			$this->outcome($this->controller()->resolve())
		);
	}//end testResolve()
}//end class
