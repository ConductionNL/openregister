<?php

/**
 * OpenRegister Object Relations Controller
 *
 * The HTTP surface of the relation rows a schema property cannot hold, and of
 * the bounded relation graph.
 *
 * Reading and writing a relation row is reading and writing the object it
 * hangs off, so every route resolves that object through ObjectService with
 * RBAC on and answers 404 when the caller may not read it. An object uuid in
 * the path is never taken as permission to act on it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use InvalidArgumentException;
use OCA\OpenRegister\Controller\Support\ObjectDerivation;
use OCA\OpenRegister\Controller\Support\ObjectLinkRoute;
use OCA\OpenRegister\Controller\Support\ObjectRelationsControllerServices;
use OCA\OpenRegister\Controller\Support\ObjectRelationsPurposeScope;
use OCA\OpenRegister\Controller\Support\ObjectRelationsRequest;
use OCA\OpenRegister\Controller\Support\ProseReference;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\ObjectRelation;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Service\Export\ExportGate;
use OCA\OpenRegister\Service\Object\ObjectReadAccessInterface;
use OCA\OpenRegister\Service\Relation\AffectedSet;
use OCA\OpenRegister\Service\Relation\AffectedSetQuery;
use OCA\OpenRegister\Service\Relation\ObjectRelationServiceInterface;
use OCA\OpenRegister\Service\Relation\ObjectTreeServiceInterface;
use OCA\OpenRegister\Service\Relation\RelationGraphServiceInterface;
use OCA\OpenRegister\Service\Relation\RelationTypeResolverInterface;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * ObjectRelationsController.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) A REST surface over the
 * relation row store, the graph walk and the object read it authorises against.
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) One action per route of the relation
 * surface (rows, derive, graph, tree, export), each a short method that refuses first.
 *
 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
 */
class ObjectRelationsController extends Controller {
	/**
	 * The directions a tree walk may take, in the order a refusal names them.
	 *
	 * @var array<int, string>
	 */
	private const TREE_DIRECTIONS = [
		ObjectTreeServiceInterface::DIRECTION_DESCENDANTS,
		ObjectTreeServiceInterface::DIRECTION_ANCESTORS,
		ObjectTreeServiceInterface::DIRECTION_BOTH,
	];

	/**
	 * The relation row service.
	 *
	 * @var ObjectRelationServiceInterface
	 */
	private readonly ObjectRelationServiceInterface $relations;

	/**
	 * The bounded graph walk.
	 *
	 * @var RelationGraphServiceInterface
	 */
	private readonly RelationGraphServiceInterface $graphs;

	/**
	 * Reads and writes objects, with RBAC.
	 *
	 * @var ObjectOperationsInterface
	 */
	private readonly ObjectOperationsInterface $objectService;

	/**
	 * The export verb, checked before the graph leaves.
	 *
	 * @var ExportGate
	 */
	private readonly ExportGate $exportGate;

	/**
	 * Resolves an object under the caller's own RBAC.
	 *
	 * @var ObjectReadAccessInterface
	 */
	private readonly ObjectReadAccessInterface $access;

	/**
	 * The bounded walk along a schema's parent property.
	 *
	 * @var ObjectTreeServiceInterface
	 */
	private readonly ObjectTreeServiceInterface $trees;

	/**
	 * The doelbinding guard the object read runs, asked before a tree or a graph is answered.
	 *
	 * @var ObjectRelationsPurposeScope
	 */
	private readonly ObjectRelationsPurposeScope $purposeScope;

	/**
	 * The request parameters these routes read.
	 *
	 * @var ObjectRelationsRequest
	 */
	private readonly ObjectRelationsRequest $params;

	/**
	 * Constructor.
	 *
	 * @param string $appName Application name.
	 * @param IRequest $request HTTP request.
	 * @param ObjectRelationsControllerServices $services The object relations controller services.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		ObjectRelationsControllerServices $services,
	) {
		parent::__construct(appName: $appName, request: $request);
		$this->relations = $services->graph->relations;
		$this->graphs = $services->graph->graphs;
		$this->objectService = $services->graph->objectService;
		$this->exportGate = $services->graph->exportGate;
		$this->access = $services->guards->access;
		$this->trees = $services->guards->trees;
		$this->purposeScope = self::purposeScopeOf(services: $services);
		$this->params = new ObjectRelationsRequest(request: $request);
	}//end __construct()

	/**
	 * The doelbinding scope over the guards and the register lookup the services bundle.
	 *
	 * @param ObjectRelationsControllerServices $services The object relations controller services.
	 *
	 * @return ObjectRelationsPurposeScope The scope.
	 */
	private static function purposeScopeOf(ObjectRelationsControllerServices $services): ObjectRelationsPurposeScope {
		return new ObjectRelationsPurposeScope(
			access: $services->guards->access,
			purposeGuard: $services->guards->purposeGuard,
			registerMapper: $services->mappers->registerMapper
		);
	}//end purposeScopeOf()

	/**
	 * The stored relation rows on an object, in both directions.
	 *
	 * @param string $register The register slug or id.
	 * @param string $schema The schema slug or id.
	 * @param string $id The object's uuid.
	 *
	 * @return JSONResponse The rows.
	 *
	 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function index(string $register, string $schema, string $id): JSONResponse {
		$object = $this->access->readable(register: $register, schema: $schema, id: $id);
		if ($object === null) {
			return $this->access->notReadable();
		}

		$rows = $this->relations->relationsFor(
			objectUuid: (string)$object->getUuid(),
			language: $this->params->language()
		);

		return new JSONResponse(data: ['results' => $rows, 'total' => count($rows)]);
	}//end index()

	/**
	 * Add a relation row to an object: an external address, or a reference
	 * somebody wrote in prose.
	 *
	 * A body with a `url` names an address outside the product. A body with a
	 * `target` and an `anchor` names another object, and writes the row on both
	 * sides so the mention is a link from either end.
	 *
	 * The prose branch is the seam the timeline change writes through in
	 * process. It is on the API too, deliberately: a service with no reachable
	 * caller is a capability nobody can exercise and nobody can test, which is
	 * indistinguishable from one that was never built.
	 *
	 * @param string $register The register slug or id.
	 * @param string $schema The schema slug or id.
	 * @param string $id The object's uuid.
	 *
	 * @return JSONResponse The row or rows, or the refusal.
	 *
	 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
	 */
	#[NoAdminRequired]
	public function addLink(string $register, string $schema, string $id): JSONResponse {
		$object = $this->access->readable(register: $register, schema: $schema, id: $id);
		if ($object === null) {
			return $this->access->notReadable();
		}

		$target = $this->params->stringParam(name: 'target');
		if ($target !== null) {
			return $this->addProseReference(source: $object, far: new ObjectLinkRoute(register: $register, schema: $schema, id: $target));
		}

		return $this->addExternalLink(object: $object);
	}//end addLink()

	/**
	 * Add an external address to an object.
	 *
	 * @param ObjectEntity $object The object the link hangs off.
	 *
	 * @return JSONResponse The row, or the refusal.
	 *
	 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
	 */
	private function addExternalLink(ObjectEntity $object): JSONResponse {
		try {
			$row = $this->externalLink(object: $object);
		} catch (InvalidArgumentException $e) {
			return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 422);
		}

		return new JSONResponse(data: $this->renderOutgoing(row: $row), statusCode: 201);
	}//end addExternalLink()

	/**
	 * Write the external address the request names onto an object.
	 *
	 * @param ObjectEntity $object The object the link hangs off.
	 *
	 * @return ObjectRelation The row.
	 *
	 * @throws InvalidArgumentException When the store refuses the address.
	 */
	private function externalLink(ObjectEntity $object): ObjectRelation {
		return $this->relations->addExternalLink(
			sourceUuid: (string)$object->getUuid(),
			url: (string)$this->request->getParam('url', ''),
			title: $this->params->stringParam(name: 'title'),
			relationType: $this->params->stringParam(name: 'type'),
			label: $this->params->stringParam(name: 'label'),
			register: $this->params->idOf(reference: $object->getRegister()),
			schema: $this->params->idOf(reference: $object->getSchema())
		);
	}//end externalLink()

	/**
	 * Record a reference written in prose, on both sides.
	 *
	 * The target has to be readable by this caller too. A mention writes a row
	 * on the far object, and writing onto an object somebody may not see would
	 * let a reference leak the existence of one they may.
	 *
	 * The anchor, what identifies the text so it can be withdrawn, is read
	 * off the request here.
	 *
	 * @param ObjectEntity    $source The object the text belongs to.
	 * @param ObjectLinkRoute $far    The object the text names, in the route's register and schema.
	 *
	 * @return JSONResponse The rows, or the refusal.
	 *
	 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
	 */
	private function addProseReference(ObjectEntity $source, ObjectLinkRoute $far): JSONResponse {
		$anchor = $this->params->stringParam(name: 'anchor');
		if ($anchor === null) {
			return new JSONResponse(
				data: ['error' => 'A reference recorded from text needs an "anchor", or nothing can withdraw it'],
				statusCode: 422
			);
		}

		$named = $this->access->readable(register: $far->register, schema: $far->schema, id: $far->id);
		if ($named === null) {
			return $this->access->notReadable();
		}

		return $this->proseAnswer(rendered: $this->recordProse(reference: new ProseReference(source: $source, far: $named, anchor: $anchor)));
	}//end addProseReference()

	/**
	 * Write the rows of one prose reference and render them.
	 *
	 * @param ProseReference $reference The two ends, the far one already read under the caller's RBAC, and the anchor.
	 *
	 * @return array<int, array<string, mixed>> The rendered rows written; none when the mention was already there.
	 */
	private function recordProse(ProseReference $reference): array {
		$rows = $this->relations->recordProseReference(
			sourceUuid: (string)$reference->source->getUuid(),
			targetUuid: (string)$reference->far->getUuid(),
			anchor: $reference->anchor,
			relationType: $this->params->stringParam(name: 'type'),
			scope: $this->proseScope(source: $reference->source, far: $reference->far)
		);

		$rendered = [];
		foreach ($rows as $row) {
			$rendered[] = $this->renderOutgoing(row: $row);
		}

		return $rendered;
	}//end recordProse()

	/**
	 * The register and schema of both ends of a prose reference.
	 *
	 * @param ObjectEntity $source The object the text belongs to.
	 * @param ObjectEntity $far    The object the text names.
	 *
	 * @return array<string, int|null> The scope.
	 */
	private function proseScope(ObjectEntity $source, ObjectEntity $far): array {
		return [
			'sourceRegister' => $this->params->idOf(reference: $source->getRegister()),
			'sourceSchema' => $this->params->idOf(reference: $source->getSchema()),
			'targetRegister' => $this->params->idOf(reference: $far->getRegister()),
			'targetSchema' => $this->params->idOf(reference: $far->getSchema()),
		];
	}//end proseScope()

	/**
	 * The answer to a recorded prose reference.
	 *
	 * @param array<int, array<string, mixed>> $rendered The rendered rows written.
	 *
	 * @return JSONResponse 201 with the rows, or 200 when nothing was written.
	 */
	private function proseAnswer(array $rendered): JSONResponse {
		// 200 rather than 201 when the mention was already there: saving the
		// same text twice writes nothing, and answering 201 would tell the
		// caller a row was created when none was.
		$status = 201;
		if ($rendered === []) {
			$status = 200;
		}

		return new JSONResponse(
			data: ['results' => $rendered, 'total' => count($rendered)],
			statusCode: $status
		);
	}//end proseAnswer()

	/**
	 * Withdraw every relation row one piece of text wrote, on both sides.
	 *
	 * @param string $register The register slug or id.
	 * @param string $schema The schema slug or id.
	 * @param string $id The object the text belongs to.
	 * @param string $anchor The anchor.
	 *
	 * @return JSONResponse How many rows went.
	 *
	 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
	 */
	#[NoAdminRequired]
	public function removeReferences(string $register, string $schema, string $id, string $anchor): JSONResponse {
		$object = $this->access->readable(register: $register, schema: $schema, id: $id);
		if ($object === null) {
			return $this->access->notReadable();
		}

		return new JSONResponse(
			data: [
				'removed' => $this->relations->withdrawProseReferences(
					sourceUuid: (string)$object->getUuid(),
					anchor: $anchor
				),
			]
		);
	}//end removeReferences()

	/**
	 * Remove one stored relation row.
	 *
	 * @param string $register The register slug or id.
	 * @param string $schema The schema slug or id.
	 * @param string $id The object's uuid.
	 * @param string $relationId The row's uuid.
	 *
	 * @return JSONResponse The outcome.
	 *
	 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
	 */
	#[NoAdminRequired]
	public function removeLink(string $register, string $schema, string $id, string $relationId): JSONResponse {
		$object = $this->access->readable(register: $register, schema: $schema, id: $id);
		if ($object === null) {
			return $this->access->notReadable();
		}

		// A row uuid is not a capability. Without the ownership check the route
		// would delete any row on any object for anybody who could read one
		// object, which is the shape of every IDOR that ever shipped.
		if ($this->ownsRelation(objectUuid: (string)$object->getUuid(), relationId: $relationId) === false
			|| $this->relations->remove(uuid: $relationId) === false
		) {
			return new JSONResponse(data: ['error' => 'No such relation on this object'], statusCode: 404);
		}

		return new JSONResponse(data: ['removed' => $relationId]);
	}//end removeLink()

	/**
	 * Whether a relation row is one of an object's own.
	 *
	 * @param string $objectUuid The object's uuid.
	 * @param string $relationId The row's uuid.
	 *
	 * @return bool True when the object carries the row.
	 */
	private function ownsRelation(string $objectUuid, string $relationId): bool {
		foreach ($this->relations->relationsFor(objectUuid: $objectUuid) as $row) {
			if (($row['uuid'] ?? null) === $relationId) {
				return true;
			}
		}

		return false;
	}//end ownsRelation()

	/**
	 * Create a new object out of this one, keeping the provenance.
	 *
	 * The entry is what makes this a split rather than a copy: one melding
	 * that turns out to be two zaken is routine, and the second zaak with no
	 * trail back is a record nobody can account for.
	 *
	 * @param string $register The register slug or id.
	 * @param string $schema The schema slug or id.
	 * @param string $id The source object's uuid.
	 *
	 * @return JSONResponse The created object and the relation row.
	 *
	 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
	 */
	#[NoAdminRequired]
	public function derive(string $register, string $schema, string $id): JSONResponse {
		$source = $this->access->readable(register: $register, schema: $schema, id: $id);
		if ($source === null) {
			return $this->access->notReadable();
		}

		$data = $this->request->getParam('object');
		if (is_array($data) === false) {
			return new JSONResponse(
				data: ['error' => 'A derived object needs an "object" body to start from'],
				statusCode: 422
			);
		}

		return $this->deriveFrom(derivation: new ObjectDerivation(source: $source, register: $register, schema: $schema), data: $data);
	}//end derive()

	/**
	 * Create the derived object and record where it came from.
	 *
	 * @param ObjectDerivation     $derivation The object it comes out of, already read under the caller's RBAC, and where the new one goes.
	 * @param array<string, mixed> $data       The body the new object starts from.
	 *
	 * @return JSONResponse The created object and the relation row, or the refusal.
	 *
	 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
	 */
	private function deriveFrom(ObjectDerivation $derivation, array $data): JSONResponse {
		$source = $derivation->source;
		$link = $this->declaredLink(source: $source);
		$applied = $this->relations->applyInheritance(parent: $source, childData: $data, inherits: $link['inherits']);
		$created = $this->saveDerived(data: $applied['data'], derivation: $derivation);
		if ($created instanceof JSONResponse) {
			return $created;
		}

		$row = $this->recordProvenance(source: $source, created: $created, link: $link, inherited: $applied['inherited']);

		return $this->derivedAnswer(created: $created, row: $row);
	}//end deriveFrom()

	/**
	 * The answer to a derive: the created object and its provenance row.
	 *
	 * @param ObjectEntity   $created The created object.
	 * @param ObjectRelation $row     The provenance row.
	 *
	 * @return JSONResponse The 201 answer.
	 */
	private function derivedAnswer(ObjectEntity $created, ObjectRelation $row): JSONResponse {
		return new JSONResponse(
			data: [
				'object' => $created->jsonSerialize(),
				'relation' => $this->renderOutgoing(row: $row),
			],
			statusCode: 201
		);
	}//end derivedAnswer()

	/**
	 * The link a derive asks for: its entry, its type and what the type inherits.
	 *
	 * The inheritance a relation type declares is read off the SOURCE object's
	 * schema, because that is where the property naming the link lives. The
	 * declared type applies only when the request names none.
	 *
	 * @param ObjectEntity $source The object the derive starts from.
	 *
	 * @return array{entry: ?string, type: ?string, inherits: array} The link.
	 */
	private function declaredLink(ObjectEntity $source): array {
		$link = ['entry' => $this->params->stringParam(name: 'entry'), 'type' => $this->params->stringParam(name: 'type'), 'inherits' => []];
		$descriptor = $this->declaration(source: $source);
		if ($descriptor === null) {
			return $link;
		}

		$link['inherits'] = ($descriptor['inherits'] ?? []);
		if ($link['type'] === null) {
			$link['type'] = ($descriptor['type'] ?? null);
		}

		return $link;
	}//end declaredLink()

	/**
	 * The declaration of the property the request names on the source's schema, or null.
	 *
	 * @param ObjectEntity $source The object the derive starts from.
	 *
	 * @return array<string, mixed>|null The descriptor, or null when no property is named or none is declared.
	 */
	private function declaration(ObjectEntity $source): ?array {
		$property = $this->params->stringParam(name: 'property');
		if ($property === null) {
			return null;
		}

		return $this->relations->declarationFor(
			schemaId: $this->params->idOf(reference: $source->getSchema()),
			property: $property,
			language: $this->params->language()
		);
	}//end declaration()

	/**
	 * Save the derived object.
	 *
	 * @param array<string, mixed> $data       The object's data, with what it inherited.
	 * @param ObjectDerivation     $derivation The register and schema the new object goes into.
	 *
	 * @return ObjectEntity|JSONResponse The created object, or the 422 when the save refuses it.
	 */
	private function saveDerived(array $data, ObjectDerivation $derivation): ObjectEntity|JSONResponse {
		try {
			return $this->objectService->saveObject(
				object: $data,
				register: $derivation->register,
				schema: $derivation->schema
			);
		} catch (\Exception $e) {
			return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 422);
		}
	}//end saveDerived()

	/**
	 * Record where a newly created object came from: one row, not two.
	 *
	 * A derive that inherited something is a derivation; one that only names
	 * where it came from is a split. Both carry the entry, because a
	 * derivation and a split are the same act seen twice and a sub-case
	 * started from a timeline entry is both. Writing the split row and then
	 * mutating it into a derivation was two writes for one act, and left
	 * recordDerivation() with no caller at all: a write capability nobody
	 * reaches is a capability nobody can exercise, which is the shape
	 * gate-orphaned-write-capability exists to catch.
	 *
	 * @param ObjectEntity         $source    The object it came out of.
	 * @param ObjectEntity         $created   The new object.
	 * @param array<string, mixed> $link      The link: the entry it came out of and the vocabulary key naming it.
	 * @param array<string, mixed> $inherited What the child took at creation.
	 *
	 * @return ObjectRelation The provenance row.
	 *
	 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
	 */
	private function recordProvenance(ObjectEntity $source, ObjectEntity $created, array $link, array $inherited): ObjectRelation {
		if ($inherited !== []) {
			return $this->relations->recordDerivation(
				parent: $source,
				child: $created,
				relationType: $link['type'],
				inherited: $inherited,
				entry: $link['entry']
			);
		}

		return $this->relations->recordSplit(source: $source, created: $created, entry: $link['entry'], relationType: $link['type']);
	}//end recordProvenance()

	/**
	 * One relation row as the API answers it, seen from the object it hangs off.
	 *
	 * @param ObjectRelation $row The row.
	 *
	 * @return array<string, mixed> The rendered row.
	 */
	private function renderOutgoing(ObjectRelation $row): array {
		return $this->relations->render(
			row: $row,
			direction: RelationTypeResolverInterface::DIRECTION_OUTGOING,
			language: $this->params->language()
		);
	}//end renderOutgoing()

	/**
	 * What an object is linked to, within a bounded depth.
	 *
	 * @param string $register The register slug or id.
	 * @param string $schema The schema slug or id.
	 * @param string $id The object's uuid.
	 *
	 * @return JSONResponse The graph.
	 *
	 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function graph(string $register, string $schema, string $id): JSONResponse {
		$object = $this->readableWithinPurpose(route: new ObjectLinkRoute(register: $register, schema: $schema, id: $id));
		if ($object instanceof JSONResponse) {
			return $object;
		}

		return new JSONResponse(data: $this->graphWithinPurpose(object: $object));
	}//end graph()

	/**
	 * Who else is affected by an object: the objects and parties its relations reach.
	 *
	 * `types` and `prune` name relation types (comma-separated or repeated);
	 * `partySchemas` names the schemas whose objects are parties. An empty
	 * `types` is refused with 400, because it reads as both "none" and "all".
	 *
	 * @param string $register The register slug or id.
	 * @param string $schema The schema slug or id.
	 * @param string $id The object's uuid.
	 *
	 * @return JSONResponse The affected set.
	 *
	 * @spec openspec/changes/relations-that-travel-and-what-they-expose/specs/referential-integrity/spec.md
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function affected(string $register, string $schema, string $id): JSONResponse {
		$object = $this->readableWithinPurpose(route: new ObjectLinkRoute(register: $register, schema: $schema, id: $id));
		if ($object instanceof JSONResponse) {
			return $object;
		}

		try {
			$query = $this->affectedQuery();
		} catch (InvalidArgumentException $e) {
			return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 400);
		}

		return new JSONResponse(data: (new AffectedSet())->derive(graph: $this->graphWithinPurpose(object: $object), query: $query));
	}//end affected()

	/**
	 * The affected-set query the request asks for.
	 *
	 * @return AffectedSetQuery The query.
	 *
	 * @throws InvalidArgumentException When `types` is present but empty.
	 *
	 * @spec openspec/changes/relations-that-travel-and-what-they-expose/specs/referential-integrity/spec.md
	 */
	private function affectedQuery(): AffectedSetQuery {
		$query = new AffectedSetQuery(types: $this->params->listParam(name: 'types'), prune: ($this->params->listParam(name: 'prune') ?? []));

		return $query->withPartySchemas(schemas: ($this->params->listParam(name: 'partySchemas') ?? []));
	}//end affectedQuery()

	/**
	 * The object a graph or tree route answers for, or the refusal.
	 *
	 * The read check comes first, so a purpose refusal never confirms an
	 * object the caller may not see.
	 *
	 * @param ObjectLinkRoute $route The object as the route names it.
	 *
	 * @return ObjectEntity|JSONResponse The object, or the 404 or purpose refusal.
	 *
	 * @spec openspec/specs/verwerkingsregister-api/spec.md
	 */
	private function readableWithinPurpose(ObjectLinkRoute $route): ObjectEntity|JSONResponse {
		$object = $this->access->readable(register: $route->register, schema: $route->schema, id: $route->id);
		if ($object === null) {
			return $this->access->notReadable();
		}

		return ($this->purposeScope->refusalFor(object: $object) ?? $object);
	}//end readableWithinPurpose()

	/**
	 * The bounded graph from an object, without the nodes whose purpose the caller did not name.
	 *
	 * @param ObjectEntity $object The root, already read under the caller's RBAC.
	 *
	 * @return array<string, mixed> The graph.
	 *
	 * @spec openspec/changes/relations-that-travel-and-what-they-expose/specs/referential-integrity/spec.md
	 */
	private function graphWithinPurpose(ObjectEntity $object): array {
		return $this->purposeScope->narrow(
			graph: $this->graphs->graph(
				rootUuid: (string)$object->getUuid(),
				depth: $this->params->depth(),
				language: $this->params->language()
			)
		);
	}//end graphWithinPurpose()

	/**
	 * The ancestors and descendants of an object along its schema's parent property.
	 *
	 * Query: `direction` (descendants, ancestors or both; default descendants),
	 * `depth` (default the schema's declared bound) and `fields` (comma-separated
	 * properties copied onto each node). Every node passes the caller's RBAC.
	 *
	 * @param string $register The register slug or id.
	 * @param string $schema The schema slug or id.
	 * @param string $id The object's uuid.
	 *
	 * @return JSONResponse The tree.
	 *
	 * @spec openspec/changes/object-tree-read/specs/referential-integrity/spec.md
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	public function tree(string $register, string $schema, string $id): JSONResponse {
		$object = $this->readableWithinPurpose(route: new ObjectLinkRoute(register: $register, schema: $schema, id: $id));
		if ($object instanceof JSONResponse) {
			return $object;
		}

		$direction = (string)$this->request->getParam('direction', ObjectTreeServiceInterface::DIRECTION_DESCENDANTS);
		if (in_array($direction, self::TREE_DIRECTIONS, true) === false) {
			return new JSONResponse(
				data: ['error' => 'direction must be one of: '.implode(', ', self::TREE_DIRECTIONS)],
				statusCode: 400
			);
		}

		return new JSONResponse(data: $this->treeOf(object: $object, direction: $direction));
	}//end tree()

	/**
	 * The tree walk from an object, at the depth and with the fields the request asks for.
	 *
	 * @param ObjectEntity $object    The root, already read under the caller's RBAC.
	 * @param string       $direction The direction of the walk.
	 *
	 * @return array<string, mixed> The tree.
	 *
	 * @spec openspec/changes/object-tree-read/specs/referential-integrity/spec.md
	 */
	private function treeOf(ObjectEntity $object, string $direction): array {
		return $this->trees->tree(
			root: $object,
			direction: $direction,
			depth: $this->params->optionalDepth(),
			fields: $this->params->csvParam(name: 'fields')
		);
	}//end treeOf()

	/**
	 * The same graph, as rows a spreadsheet opens.
	 *
	 * @param string $register The register slug or id.
	 * @param string $schema The schema slug or id.
	 * @param string $id The object's uuid.
	 *
	 * @return DataDownloadResponse|JSONResponse The export, or the refusal.
	 *
	 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function exportGraph(string $register, string $schema, string $id): DataDownloadResponse|JSONResponse {
		$object = $this->readableWithinPurpose(route: new ObjectLinkRoute(register: $register, schema: $schema, id: $id));
		if ($object instanceof JSONResponse) {
			return $object;
		}

		// REQ-EXP-001: a relation graph as a CSV is the object's data leaving
		// the instance, so it is an export and is checked against the export
		// verb, not against the read the caller already passed above. A
		// principal holding read without export meets the same refusal here as
		// on every other export path.
		$refusal = $this->exportGate->refusalFor(
			schema: $this->access->schemaOf(object: $object),
			profile: 'relation-graph',
			registerId: $this->access->registerIdOf(object: $object)
		);

		return ($refusal ?? $this->graphDownload(object: $object));
	}//end exportGraph()

	/**
	 * The graph from an object as a CSV download.
	 *
	 * The CSV carries the titles and uuids of every node, so it is narrowed by
	 * the same doelbinding rule as the graph answer before its rows are written.
	 *
	 * @param ObjectEntity $object The root, already read under the caller's RBAC and export right.
	 *
	 * @return DataDownloadResponse The export.
	 */
	private function graphDownload(ObjectEntity $object): DataDownloadResponse {
		$csv = $this->csvOf(rows: $this->graphs->rowsOf(graph: $this->graphWithinPurpose(object: $object)));

		return new DataDownloadResponse(
			data: $csv,
			filename: 'relation-graph-'.(string)$object->getUuid().'.csv',
			contentType: 'text/csv'
		);
	}//end graphDownload()

	/**
	 * Rows as CSV text.
	 *
	 * @param iterable<array<int, mixed>> $rows The rows.
	 *
	 * @return string The CSV.
	 */
	private function csvOf(iterable $rows): string {
		$handle = fopen('php://temp', 'r+');
		foreach ($rows as $row) {
			fputcsv($handle, $row, escape: '');
		}

		rewind($handle);
		$csv = (string)stream_get_contents($handle);
		fclose($handle);

		return $csv;
	}//end csvOf()
}//end class
