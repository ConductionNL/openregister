<?php

/**
 * Golden outcomes of the relation-row, derive, graph and export routes.
 *
 * Pins, before a restructure, what each route answers to a caller who may
 * read the object and to one who may not: status and body, and that a refused
 * caller never reaches the write.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Service\Relation\GraphExportOptions;
use OCA\OpenRegister\Controller\ObjectRelationsController;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\ObjectRelation;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Audit\PurposeGuard;
use OCA\OpenRegister\Service\Audit\PurposeRefusedException;
use OCA\OpenRegister\Service\Export\ExportGate;
use OCA\OpenRegister\Service\Object\ObjectReadAccess;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\Relation\Derivation;
use OCA\OpenRegister\Service\Relation\ObjectRelationService;
use OCA\OpenRegister\Service\Relation\ExternalLink;
use OCA\OpenRegister\Service\Relation\ObjectTreeQuery;
use OCA\OpenRegister\Service\Relation\ProvenanceLink;
use OCA\OpenRegister\Service\Relation\ProseMention;
use OCA\OpenRegister\Service\Relation\RelationEndpoint;
use OCA\OpenRegister\Service\Relation\ObjectTreeService;
use OCA\OpenRegister\Service\Relation\RelationGraphService;
use OCA\OpenRegister\Tests\Unit\Controller\Support\ObjectRelationsControllerFactory;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use OCA\OpenRegister\Service\Object\ObjectSaveOptions;

/**
 * @covers \OCA\OpenRegister\Controller\ObjectRelationsController
 */
class ObjectRelationsControllerA24GoldenTest extends TestCase {

	/**
	 * The row store double.
	 *
	 * @var ObjectRelationService&MockObject
	 */
	private ObjectRelationService&MockObject $relations;

	/**
	 * The graph walk double.
	 *
	 * @var RelationGraphService&MockObject
	 */
	private RelationGraphService&MockObject $graphs;

	/**
	 * The object write double.
	 *
	 * @var ObjectService&MockObject
	 */
	private ObjectService&MockObject $objects;

	/**
	 * The export gate double.
	 *
	 * @var ExportGate&MockObject
	 */
	private ExportGate&MockObject $exportGate;

	/**
	 * The tree walk double.
	 *
	 * @var ObjectTreeService&MockObject
	 */
	private ObjectTreeService&MockObject $trees;

	/**
	 * The doelbinding guard double.
	 *
	 * @var PurposeGuard&MockObject
	 */
	private PurposeGuard&MockObject $purposeGuard;

	/**
	 * Build the controller over a request and the ids the caller may read.
	 *
	 * @param array<string, mixed> $params   The request parameters.
	 * @param array<int, string>   $readable The ids the caller may read.
	 *
	 * @return ObjectRelationsController The controller.
	 */
	private function controller(array $params, array $readable): ObjectRelationsController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => ($params[$key] ?? $default)
		);

		$access = $this->createMock(ObjectReadAccess::class);
		$access->method('readable')->willReturnCallback(
			function (string $register, string $schema, string $id) use ($readable): ?ObjectEntity {
				if (in_array($id, $readable, true) === false) {
					return null;
				}

				return $this->object(uuid: $id);
			}
		);
		$access->method('notReadable')->willReturn(new JSONResponse(data: ['error' => 'Object not found'], statusCode: 404));
		$access->method('schemaOf')->willReturn(new Schema());
		$access->method('registerIdOf')->willReturn(1);
		$access->method('schemaById')->willReturn(new Schema());

		$registerMapper = $this->createMock(RegisterMapper::class);
		$registerMapper->method('find')->willReturn(new Register());

		$this->relations = $this->createMock(ObjectRelationService::class);
		$this->relations->method('render')->willReturnCallback(
			static fn (ObjectRelation $row, string $direction, string $language): array => [
				'uuid' => $row->getUuid(),
				'direction' => $direction,
				'language' => $language,
			]
		);
		$this->graphs = $this->createMock(RelationGraphService::class);
		$this->objects = $this->createMock(ObjectService::class);
		$this->exportGate = $this->createMock(ExportGate::class);
		$this->trees = $this->createMock(ObjectTreeService::class);
		$this->purposeGuard = $this->createMock(PurposeGuard::class);

		return ObjectRelationsControllerFactory::create(
			appName: 'openregister',
			request: $request,
			relations: $this->relations,
			graphs: $this->graphs,
			objectService: $this->objects,
			exportGate: $this->exportGate,
			access: $access,
			trees: $this->trees,
			purposeGuard: $this->purposeGuard,
			registerMapper: $registerMapper
		);
	}//end controller()

	/**
	 * An object as the read answers it.
	 *
	 * @param string $uuid The uuid.
	 *
	 * @return ObjectEntity The object.
	 */
	private function object(string $uuid): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid($uuid);
		$object->setRegister('3');
		$object->setSchema('4');

		return $object;
	}//end object()

	/**
	 * A stored relation row.
	 *
	 * @param string $uuid The row's uuid.
	 *
	 * @return ObjectRelation The row.
	 */
	private function row(string $uuid): ObjectRelation {
		$row = new ObjectRelation();
		$row->setUuid($uuid);

		return $row;
	}//end row()

	/**
	 * A response's status and body, for one assertion.
	 *
	 * @param JSONResponse $response The response.
	 *
	 * @return array<int, mixed> The status and the body.
	 */
	private function outcome(JSONResponse $response): array {
		return [$response->getStatus(), $response->getData()];
	}//end outcome()

	/**
	 * Every route answers 404 for an object the caller may not read, and writes nothing.
	 *
	 * @return void
	 */
	public function testEveryRouteRefusesAnUnreadableObject(): void {
		$controller = $this->controller(params: ['url' => 'https://x', 'object' => ['a' => 1]], readable: []);
		$this->relations->expects($this->never())->method('addExternalLink');
		$this->relations->expects($this->never())->method('remove');
		$this->relations->expects($this->never())->method('withdrawProseReferences');
		$this->objects->expects($this->never())->method('saveObject');
		$this->graphs->expects($this->never())->method('graph');
		$this->trees->expects($this->never())->method('tree');

		$notFound = [404, ['error' => 'Object not found']];
		$this->assertSame($notFound, $this->outcome($controller->index('r', 's', 'x')));
		$this->assertSame($notFound, $this->outcome($controller->addLink('r', 's', 'x')));
		$this->assertSame($notFound, $this->outcome($controller->removeReferences('r', 's', 'x', 'a')));
		$this->assertSame($notFound, $this->outcome($controller->removeLink('r', 's', 'x', 'row')));
		$this->assertSame($notFound, $this->outcome($controller->derive('r', 's', 'x')));
		$this->assertSame($notFound, $this->outcome($controller->graph('r', 's', 'x')));
		$this->assertSame($notFound, $this->outcome($controller->affected('r', 's', 'x')));
		$this->assertSame($notFound, $this->outcome($controller->tree('r', 's', 'x')));
		$this->assertSame($notFound, $this->outcome($controller->exportGraph('r', 's', 'x')));
	}//end testEveryRouteRefusesAnUnreadableObject()

	/**
	 * The rows of a readable object, in the asked language.
	 *
	 * @return void
	 */
	public function testIndexAnswersTheRows(): void {
		$controller = $this->controller(params: ['language' => ' en '], readable: ['a']);
		$this->relations->expects($this->once())->method('relationsFor')->with('a', 'en')->willReturn([['uuid' => 'r1']]);

		$this->assertSame([200, ['results' => [['uuid' => 'r1']], 'total' => 1]], $this->outcome($controller->index('r', 's', 'a')));
	}//end testIndexAnswersTheRows()

	/**
	 * An external link is written with the object's own scope and answered 201.
	 *
	 * @return void
	 */
	public function testAnExternalLinkIsCreated(): void {
		$controller = $this->controller(params: ['url' => 'https://x', 'title' => ' T ', 'type' => 'see', 'label' => ''], readable: ['a']);
		$this->relations->expects($this->once())->method('addExternalLink')
			->with(new RelationEndpoint('a', 3, 4), new ExternalLink('https://x', 'T', 'see', null))
			->willReturn($this->row(uuid: 'link'));

		$this->assertSame(
			[201, ['uuid' => 'link', 'direction' => 'outgoing', 'language' => 'nl']],
			$this->outcome($controller->addLink('r', 's', 'a'))
		);
	}//end testAnExternalLinkIsCreated()

	/**
	 * A link the store refuses answers 422 with its message.
	 *
	 * @return void
	 */
	public function testARefusedExternalLinkIs422(): void {
		$controller = $this->controller(params: [], readable: ['a']);
		$this->relations->method('addExternalLink')->willThrowException(new \InvalidArgumentException('bad url'));

		$this->assertSame([422, ['error' => 'bad url']], $this->outcome($controller->addLink('r', 's', 'a')));
	}//end testARefusedExternalLinkIs422()

	/**
	 * A prose reference needs an anchor and a readable target; a repeat answers 200.
	 *
	 * @return void
	 */
	public function testAProseReferenceIsRefusedOrRecorded(): void {
		$noAnchor = $this->controller(params: ['target' => 'b'], readable: ['a', 'b']);
		$this->assertSame(
			[422, ['error' => 'A reference recorded from text needs an "anchor", or nothing can withdraw it']],
			$this->outcome($noAnchor->addLink('r', 's', 'a'))
		);

		$hidden = $this->controller(params: ['target' => 'b', 'anchor' => 'p1'], readable: ['a']);
		$this->relations->expects($this->never())->method('recordProseReference');
		$this->assertSame([404, ['error' => 'Object not found']], $this->outcome($hidden->addLink('r', 's', 'a')));

		$seen = $this->controller(params: ['target' => 'b', 'anchor' => 'p1', 'type' => 'mentions'], readable: ['a', 'b']);
		$this->relations->expects($this->once())->method('recordProseReference')
			->with(new ProseMention(new RelationEndpoint('a', 3, 4), new RelationEndpoint('b', 3, 4), 'p1', 'mentions'))
			->willReturn([$this->row(uuid: 'out'), $this->row(uuid: 'in')]);
		$this->assertSame(
			[
				201,
				[
					'results' => [
						['uuid' => 'out', 'direction' => 'outgoing', 'language' => 'nl'],
						['uuid' => 'in', 'direction' => 'outgoing', 'language' => 'nl'],
					],
					'total' => 2,
				],
			],
			$this->outcome($seen->addLink('r', 's', 'a'))
		);

		$again = $this->controller(params: ['target' => 'b', 'anchor' => 'p1'], readable: ['a', 'b']);
		$this->relations->method('recordProseReference')->willReturn([]);
		$this->assertSame([200, ['results' => [], 'total' => 0]], $this->outcome($again->addLink('r', 's', 'a')));
	}//end testAProseReferenceIsRefusedOrRecorded()

	/**
	 * Withdrawing a text's references answers the count.
	 *
	 * @return void
	 */
	public function testReferencesAreWithdrawn(): void {
		$controller = $this->controller(params: [], readable: ['a']);
		$this->relations->expects($this->once())->method('withdrawProseReferences')->with('a', 'p1')->willReturn(2);

		$this->assertSame([200, ['removed' => 2]], $this->outcome($controller->removeReferences('r', 's', 'a', 'p1')));
	}//end testReferencesAreWithdrawn()

	/**
	 * A row of another object is never removed; a row of this one is.
	 *
	 * @return void
	 */
	public function testOnlyARowOfThisObjectIsRemoved(): void {
		$foreign = $this->controller(params: [], readable: ['a']);
		$this->relations->method('relationsFor')->willReturn([['uuid' => 'mine']]);
		$this->relations->expects($this->never())->method('remove');
		$this->assertSame([404, ['error' => 'No such relation on this object']], $this->outcome($foreign->removeLink('r', 's', 'a', 'theirs')));

		$own = $this->controller(params: [], readable: ['a']);
		$this->relations->method('relationsFor')->willReturn([['uuid' => 'other'], ['uuid' => 'mine']]);
		$this->relations->expects($this->once())->method('remove')->with('mine')->willReturn(true);
		$this->assertSame([200, ['removed' => 'mine']], $this->outcome($own->removeLink('r', 's', 'a', 'mine')));

		$gone = $this->controller(params: [], readable: ['a']);
		$this->relations->method('relationsFor')->willReturn([['uuid' => 'mine']]);
		$this->relations->method('remove')->willReturn(false);
		$this->assertSame([404, ['error' => 'No such relation on this object']], $this->outcome($gone->removeLink('r', 's', 'a', 'mine')));
	}//end testOnlyARowOfThisObjectIsRemoved()

	/**
	 * A derive without a body, or whose save fails, answers 422.
	 *
	 * @return void
	 */
	public function testADeriveIsRefusedWithoutABodyOrOnAFailedSave(): void {
		$noBody = $this->controller(params: ['object' => 'x'], readable: ['a']);
		$this->assertSame(
			[422, ['error' => 'A derived object needs an "object" body to start from']],
			$this->outcome($noBody->derive('r', 's', 'a'))
		);

		$failing = $this->controller(params: ['object' => ['a' => 1]], readable: ['a']);
		$this->relations->method('applyInheritance')->willReturn(['data' => ['a' => 1], 'inherited' => []]);
		$this->objects->method('saveObject')->willThrowException(new \Exception('invalid'));
		$this->relations->expects($this->never())->method('recordSplit');
		$this->assertSame([422, ['error' => 'invalid']], $this->outcome($failing->derive('r', 's', 'a')));
	}//end testADeriveIsRefusedWithoutABodyOrOnAFailedSave()

	/**
	 * A derive that inherits records a derivation with the declared type.
	 *
	 * @return void
	 */
	public function testADeriveThatInheritsIsADerivation(): void {
		$controller = $this->controller(params: ['object' => ['t' => 1], 'property' => 'parent', 'entry' => 'e1'], readable: ['a']);
		$this->relations->expects($this->once())->method('declarationFor')->with(4, 'parent', 'nl')
			->willReturn(['inherits' => ['zaaktype'], 'type' => 'deelzaak']);
		$this->relations->expects($this->once())->method('applyInheritance')
			->with($this->isInstanceOf(ObjectEntity::class), ['t' => 1], ['zaaktype'])
			->willReturn(['data' => ['t' => 1, 'zaaktype' => 'z'], 'inherited' => ['zaaktype' => 'z']]);
		$created = $this->object(uuid: 'child');
		$this->objects->expects($this->once())->method('saveObject')->with(['t' => 1, 'zaaktype' => 'z'], $this->callback(static fn (ObjectSaveOptions $options): bool => $options->extend == [] && $options->target->register == 'r' && $options->target->schema == 's'))->willReturn($created);
		$this->relations->expects($this->never())->method('recordSplit');
		$this->relations->expects($this->once())->method('recordDerived')
			->with($this->callback(fn (Derivation $derivation): bool => $derivation->parent instanceof ObjectEntity
				&& $derivation->child === $created
				&& $derivation->link == new ProvenanceLink('e1', 'deelzaak')
				&& $derivation->inherited === ['zaaktype' => 'z']))
			->willReturn($this->row(uuid: 'prov'));

		$response = $controller->derive('r', 's', 'a');

		$this->assertSame(201, $response->getStatus());
		$this->assertSame(['uuid' => 'prov', 'direction' => 'outgoing', 'language' => 'nl'], $response->getData()['relation']);
		$this->assertSame($created->jsonSerialize(), $response->getData()['object']);
	}//end testADeriveThatInheritsIsADerivation()

	/**
	 * A derive that inherits nothing records a split with the asked type.
	 *
	 * @return void
	 */
	public function testADeriveThatInheritsNothingIsASplit(): void {
		$controller = $this->controller(params: ['object' => ['t' => 1], 'type' => 'split-from'], readable: ['a']);
		$this->relations->expects($this->never())->method('declarationFor');
		$this->relations->method('applyInheritance')->willReturn(['data' => ['t' => 1], 'inherited' => []]);
		$created = $this->object(uuid: 'child');
		$this->objects->method('saveObject')->willReturn($created);
		$this->relations->expects($this->never())->method('recordDerived');
		$this->relations->expects($this->once())->method('recordSplit')
			->with($this->isInstanceOf(ObjectEntity::class), $created, new ProvenanceLink(null, 'split-from'))
			->willReturn($this->row(uuid: 'prov'));

		$this->assertSame(201, $controller->derive('r', 's', 'a')->getStatus());
	}//end testADeriveThatInheritsNothingIsASplit()

	/**
	 * The graph of a readable object at the asked depth; a refused purpose answers its own body.
	 *
	 * @return void
	 */
	public function testTheGraphIsAnsweredOrRefusedForItsPurpose(): void {
		$controller = $this->controller(params: ['depth' => '0'], readable: ['a']);
		$graph = ['root' => 'a', 'nodes' => [], 'edges' => []];
		$this->graphs->expects($this->once())->method('graph')->with('a', new GraphExportOptions(1, 'nl'))->willReturn($graph);
		$this->assertSame([200, $graph], $this->outcome($controller->graph('r', 's', 'a')));

		$refused = $this->controller(params: [], readable: ['a']);
		$refusal = new PurposeRefusedException(rule: 'r', reason: 'no-purpose');
		$this->purposeGuard->method('enforce')->willThrowException($refusal);
		$this->graphs->expects($this->never())->method('graph');
		$this->assertSame([403, $refusal->toResponseBody()], $this->outcome($refused->graph('r', 's', 'a')));
		$this->assertSame([403, $refusal->toResponseBody()], $this->outcome($refused->exportGraph('r', 's', 'a')));
		$this->assertSame([403, $refusal->toResponseBody()], $this->outcome($refused->tree('r', 's', 'a')));
	}//end testTheGraphIsAnsweredOrRefusedForItsPurpose()

	/**
	 * The export answers the gate's refusal before any walk, and the CSV otherwise.
	 *
	 * @return void
	 */
	public function testTheExportIsGatedThenWritten(): void {
		$refused = $this->controller(params: [], readable: ['a']);
		$this->exportGate->method('refusalFor')->willReturn(new JSONResponse(data: ['error' => 'no export'], statusCode: 403));
		$this->graphs->expects($this->never())->method('graph');
		$this->assertSame([403, ['error' => 'no export']], $this->outcome($refused->exportGraph('r', 's', 'a')));

		$allowed = $this->controller(params: ['depth' => '2'], readable: ['a']);
		$this->exportGate->expects($this->once())->method('refusalFor')->with($this->isInstanceOf(Schema::class), 'relation-graph', 1)->willReturn(null);
		$this->graphs->method('graph')->with('a', new GraphExportOptions(2, 'nl'))->willReturn(['root' => 'a', 'nodes' => [], 'edges' => []]);
		$this->graphs->method('rowsOf')->willReturn([['from', 'to'], ['a', 'b "c"']]);
		$response = $allowed->exportGraph('r', 's', 'a');

		$this->assertInstanceOf(DataDownloadResponse::class, $response);
		$this->assertSame("from,to\na,\"b \"\"c\"\"\"\n", $response->render());
		$this->assertSame('text/csv', $response->getHeaders()['Content-Type']);
		$this->assertStringContainsString('relation-graph-a.csv', $response->getHeaders()['Content-Disposition']);
	}//end testTheExportIsGatedThenWritten()

	/**
	 * The tree refuses an unknown direction and passes the asked fields on.
	 *
	 * @return void
	 */
	public function testTheTreeValidatesItsQuery(): void {
		$bad = $this->controller(params: ['direction' => 'sideways'], readable: ['a']);
		$this->assertSame(
			[400, ['error' => 'direction must be one of: descendants, ancestors, both']],
			$this->outcome($bad->tree('r', 's', 'a'))
		);

		$good = $this->controller(params: ['direction' => 'both', 'depth' => '3', 'fields' => ' a, ,b '], readable: ['a']);
		$this->trees->expects($this->once())->method('tree')
			->with($this->isInstanceOf(ObjectEntity::class), new ObjectTreeQuery('both', 3, ['a', 'b']))
			->willReturn(['root' => 'a']);
		$this->assertSame([200, ['root' => 'a']], $this->outcome($good->tree('r', 's', 'a')));
	}//end testTheTreeValidatesItsQuery()
}//end class
