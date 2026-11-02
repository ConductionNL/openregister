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
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\ObjectRelation;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Service\Audit\PurposeGuard;
use OCA\OpenRegister\Service\Audit\PurposeRefusedException;
use OCA\OpenRegister\Service\Export\ExportGate;
use OCA\OpenRegister\Service\Object\ObjectReadAccess;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\Relation\ObjectRelationService;
use OCA\OpenRegister\Service\Relation\ObjectTreeService;
use OCA\OpenRegister\Service\Relation\RelationGraphService;
use OCA\OpenRegister\Service\Relation\RelationTypeResolver;
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
	 * Constructor.
	 *
	 * @param string $appName Application name.
	 * @param IRequest $request HTTP request.
	 * @param ObjectRelationService $relations The relation row service.
	 * @param RelationGraphService $graphs The bounded graph walk.
	 * @param ObjectService $objectService Reads and writes objects, with RBAC.
	 * @param ExportGate $exportGate The export verb, checked before the graph leaves.
	 * @param ObjectReadAccess $access Resolves an object under the caller's own RBAC.
	 * @param ObjectTreeService $trees The bounded walk along a schema's parent property.
	 * @param PurposeGuard $purposeGuard The doelbinding guard the object read runs, asked before a tree or a graph is answered.
	 * @param RegisterMapper $registerMapper Resolves an object's register for that guard.
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList) Constructor injection; each dependency is named above.
	 *
	 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly ObjectRelationService $relations,
		private readonly RelationGraphService $graphs,
		private readonly ObjectService $objectService,
		private readonly ExportGate $exportGate,
		private readonly ObjectReadAccess $access,
		private readonly ObjectTreeService $trees,
		private readonly PurposeGuard $purposeGuard,
		private readonly RegisterMapper $registerMapper,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

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
			language: $this->language()
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

		$target = $this->stringParam(name: 'target');
		$anchor = $this->stringParam(name: 'anchor');
		if ($target !== null) {
			return $this->addProseReference(
				source: $object,
				register: $register,
				schema: $schema,
				target: $target,
				anchor: $anchor
			);
		}

		try {
			$row = $this->relations->addExternalLink(
				sourceUuid: (string)$object->getUuid(),
				url: (string)$this->request->getParam('url', ''),
				title: $this->stringParam(name: 'title'),
				relationType: $this->stringParam(name: 'type'),
				label: $this->stringParam(name: 'label'),
				register: $this->asInt(value: $object->getRegister()),
				schema: $this->asInt(value: $object->getSchema())
			);
		} catch (InvalidArgumentException $e) {
			return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 422);
		}

		return new JSONResponse(
			data: $this->relations->render(
				row: $row,
				direction: RelationTypeResolver::DIRECTION_OUTGOING,
				language: $this->language()
			),
			statusCode: 201
		);
	}//end addLink()

	/**
	 * Record a reference written in prose, on both sides.
	 *
	 * The target has to be readable by this caller too. A mention writes a row
	 * on the far object, and writing onto an object somebody may not see would
	 * let a reference leak the existence of one they may.
	 *
	 * @param ObjectEntity $source The object the text belongs to.
	 * @param string $register The register slug or id.
	 * @param string $schema The schema slug or id.
	 * @param string $target The object the text names.
	 * @param string|null $anchor What identifies the text, so it can be withdrawn.
	 *
	 * @return JSONResponse The rows, or the refusal.
	 *
	 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
	 */
	private function addProseReference(
		ObjectEntity $source,
		string $register,
		string $schema,
		string $target,
		?string $anchor,
	): JSONResponse {
		if ($anchor === null) {
			return new JSONResponse(
				data: ['error' => 'A reference recorded from text needs an "anchor", or nothing can withdraw it'],
				statusCode: 422
			);
		}

		$far = $this->access->readable(register: $register, schema: $schema, id: $target);
		if ($far === null) {
			return $this->access->notReadable();
		}

		$rows = $this->relations->recordProseReference(
			sourceUuid: (string)$source->getUuid(),
			targetUuid: (string)$far->getUuid(),
			anchor: $anchor,
			relationType: $this->stringParam(name: 'type'),
			scope: [
				'sourceRegister' => $this->asInt(value: $source->getRegister()),
				'sourceSchema' => $this->asInt(value: $source->getSchema()),
				'targetRegister' => $this->asInt(value: $far->getRegister()),
				'targetSchema' => $this->asInt(value: $far->getSchema()),
			]
		);

		$rendered = [];
		foreach ($rows as $row) {
			$rendered[] = $this->relations->render(
				row: $row,
				direction: RelationTypeResolver::DIRECTION_OUTGOING,
				language: $this->language()
			);
		}

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
	}//end addProseReference()

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

		$objectUuid = (string)$object->getUuid();
		$owned = false;
		foreach ($this->relations->relationsFor(objectUuid: $objectUuid) as $row) {
			if (($row['uuid'] ?? null) === $relationId) {
				$owned = true;
				break;
			}
		}

		// A row uuid is not a capability. Without this the route would delete
		// any row on any object for anybody who could read one object, which
		// is the shape of every IDOR that ever shipped.
		if ($owned === false) {
			return new JSONResponse(data: ['error' => 'No such relation on this object'], statusCode: 404);
		}

		if ($this->relations->remove(uuid: $relationId) === false) {
			return new JSONResponse(data: ['error' => 'No such relation on this object'], statusCode: 404);
		}

		return new JSONResponse(data: ['removed' => $relationId]);
	}//end removeLink()

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

		$entry = $this->stringParam(name: 'entry');
		$relationType = $this->stringParam(name: 'type');
		$property = $this->stringParam(name: 'property');

		// The inheritance a relation type declares, read off the SOURCE
		// object's schema, because that is where the property naming the link
		// lives.
		$inherits = [];
		if ($property !== null) {
			$descriptor = $this->relations->declarationFor(
				schemaId: $this->asInt(value: $source->getSchema()),
				property: $property,
				language: $this->language()
			);

			if ($descriptor !== null) {
				$inherits = ($descriptor['inherits'] ?? []);
				if ($relationType === null) {
					$relationType = ($descriptor['type'] ?? null);
				}
			}
		}

		$applied = $this->relations->applyInheritance(
			parent: $source,
			childData: $data,
			inherits: $inherits
		);

		try {
			$created = $this->objectService->saveObject(
				object: $applied['data'],
				register: $register,
				schema: $schema
			);
		} catch (\Exception $e) {
			return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 422);
		}

		// A derive that inherited something is a derivation; one that only
		// names where it came from is a split. Writing the split row and then
		// mutating it into a derivation was two writes for one act, and left
		// recordDerivation() with no caller at all: a write capability nobody
		// reaches is a capability nobody can exercise, which is the shape
		// gate-orphaned-write-capability exists to catch.
		$row = $this->recordProvenance(
			source: $source,
			created: $created,
			entry: $entry,
			relationType: $relationType,
			inherited: $applied['inherited']
		);

		return new JSONResponse(
			data: [
				'object' => $created->jsonSerialize(),
				'relation' => $this->relations->render(
					row: $row,
					direction: RelationTypeResolver::DIRECTION_OUTGOING,
					language: $this->language()
				),
			],
			statusCode: 201
		);
	}//end derive()

	/**
	 * Record where a newly created object came from: one row, not two.
	 *
	 * A derive that inherited something is a derivation; one that only names
	 * where it came from is a split. Both carry the entry, because a
	 * derivation and a split are the same act seen twice and a sub-case
	 * started from a timeline entry is both.
	 *
	 * @param ObjectEntity $source The object it came out of.
	 * @param ObjectEntity $created The new object.
	 * @param string|null $entry The entry it came out of, when it came out of one.
	 * @param string|null $relationType The vocabulary key naming the link.
	 * @param array<string, mixed> $inherited What the child took at creation.
	 *
	 * @return ObjectRelation The provenance row.
	 *
	 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
	 */
	private function recordProvenance(
		ObjectEntity $source,
		ObjectEntity $created,
		?string $entry,
		?string $relationType,
		array $inherited,
	): ObjectRelation {
		if ($inherited !== []) {
			return $this->relations->recordDerivation(
				parent: $source,
				child: $created,
				relationType: $relationType,
				inherited: $inherited,
				entry: $entry
			);
		}

		return $this->relations->recordSplit(
			source: $source,
			created: $created,
			entry: $entry,
			relationType: $relationType
		);
	}//end recordProvenance()

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
		$object = $this->access->readable(register: $register, schema: $schema, id: $id);
		if ($object === null) {
			return $this->access->notReadable();
		}

		$refusal = $this->purposeRefusal(object: $object);
		if ($refusal !== null) {
			return $refusal;
		}

		return new JSONResponse(
			data: $this->withinPurpose(
				graph: $this->graphs->graph(
					rootUuid: (string)$object->getUuid(),
					depth: $this->depth(),
					language: $this->language()
				)
			)
		);
	}//end graph()

	/**
	 * The graph without the nodes of schemas whose purpose the caller did not name.
	 *
	 * The root's schema is checked before the walk; a node carries the title and
	 * schema of a related object that may sit in another schema, so every schema
	 * present in the answer passes the same guard. A refused node leaves the
	 * answer with its edges, and so does everything that was only reachable
	 * through it.
	 *
	 * @param array<string, mixed> $graph The graph the walk answered.
	 *
	 * @return array<string, mixed> The graph the caller may see.
	 */
	private function withinPurpose(array $graph): array {
		$refused = [];
		$decided = [];
		foreach (($graph['nodes'] ?? []) as $node) {
			if (($node['resolved'] ?? false) !== true || ($node['external'] ?? false) === true) {
				continue;
			}

			$key = ((string)($node['register'] ?? '')).'/'.((string)($node['schema'] ?? ''));
			if (isset($decided[$key]) === false) {
				$decided[$key] = $this->purposeAdmits(registerId: $node['register'] ?? null, schemaId: $node['schema'] ?? null);
			}

			if ($decided[$key] === false) {
				$refused[(string)($node['uuid'] ?? '')] = true;
			}
		}

		if ($refused === []) {
			return $graph;
		}

		return $this->reachableFromRoot(graph: $graph, refused: $refused);
	}//end withinPurpose()

	/**
	 * Whether the doelbinding guard admits a read of the schema of one node.
	 *
	 * @param mixed $registerId The node's register id.
	 * @param mixed $schemaId   The node's schema id.
	 *
	 * @return bool False when a purpose is needed and none is usable, or the schema does not resolve.
	 */
	private function purposeAdmits(mixed $registerId, mixed $schemaId): bool {
		$schema = null;
		if (is_scalar($schemaId) === true) {
			$schema = $this->access->schemaById(schemaId: $schemaId);
		}

		$register = null;
		if (is_scalar($registerId) === true && $registerId !== '') {
			try {
				$register = $this->registerMapper->find($registerId, _rbac: false, _multitenancy: false);
			} catch (\Throwable $e) {
				$register = null;
			}
		}

		if ($schema === null || $register === null) {
			return false;
		}

		try {
			$this->purposeGuard->enforce(register: $register, schema: $schema);
		} catch (PurposeRefusedException $refusal) {
			return false;
		}

		return true;
	}//end purposeAdmits()

	/**
	 * The graph without the refused nodes, their edges and what hangs only on them.
	 *
	 * @param array<string, mixed> $graph   The graph.
	 * @param array<string, true>  $refused The uuids of the refused nodes.
	 *
	 * @return array<string, mixed> The graph.
	 */
	private function reachableFromRoot(array $graph, array $refused): array {
		$allEdges = $this->rowsIn(value: ($graph['edges'] ?? null));
		$allNodes = $this->rowsIn(value: ($graph['nodes'] ?? null));

		$edges = array_values(
			array_filter(
				$allEdges,
				static fn (array $edge): bool => isset($refused[(string)($edge['from'] ?? '')]) === false
					&& isset($refused[(string)($edge['to'] ?? '')]) === false
			)
		);

		$reached = $this->reached(root: (string)($graph['root'] ?? ''), edges: $edges);

		$graph['nodes'] = array_values(
			array_filter(
				$allNodes,
				static fn (array $node): bool => isset($reached[(string)($node['uuid'] ?? '')]) === true
					&& isset($refused[(string)($node['uuid'] ?? '')]) === false
			)
		);
		$graph['edges'] = array_values(
			array_filter(
				$edges,
				static fn (array $edge): bool => isset($reached[(string)($edge['from'] ?? '')]) === true
					&& isset($reached[(string)($edge['to'] ?? '')]) === true
			)
		);

		return $graph;
	}//end reachableFromRoot()

	/**
	 * A graph list (its nodes or edges) as a list of rows; anything else is no rows.
	 *
	 * @param mixed $value The list as the graph holds it.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 */
	private function rowsIn(mixed $value): array {
		if (is_array($value) === false) {
			return [];
		}

		return array_values(array_filter($value, static fn (mixed $row): bool => is_array($row) === true));
	}//end rowsIn()

	/**
	 * The uuids connected to the root through the given edges, either direction.
	 *
	 * @param string                           $root  The root uuid.
	 * @param array<int, array<string, mixed>> $edges The edges that remain.
	 *
	 * @return array<string, true> The reached uuids.
	 */
	private function reached(string $root, array $edges): array {
		$reached = [$root => true];
		do {
			$grew = false;
			foreach ($edges as $edge) {
				$from = (string)($edge['from'] ?? '');
				$to = (string)($edge['to'] ?? '');
				if (isset($reached[$from]) === isset($reached[$to])) {
					continue;
				}

				$reached[$from] = true;
				$reached[$to] = true;
				$grew = true;
			}
		} while ($grew === true);

		return $reached;
	}//end reached()


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
		$object = $this->access->readable(register: $register, schema: $schema, id: $id);
		if ($object === null) {
			return $this->access->notReadable();
		}

		$refusal = $this->purposeRefusal(object: $object);
		if ($refusal !== null) {
			return $refusal;
		}

		$direction = (string)$this->request->getParam('direction', ObjectTreeService::DIRECTION_DESCENDANTS);
		$directions = [
			ObjectTreeService::DIRECTION_DESCENDANTS,
			ObjectTreeService::DIRECTION_ANCESTORS,
			ObjectTreeService::DIRECTION_BOTH,
		];
		if (in_array($direction, $directions, true) === false) {
			return new JSONResponse(
				data: ['error' => 'direction must be one of: '.implode(', ', $directions)],
				statusCode: 400
			);
		}

		$depth = null;
		if ($this->stringParam(name: 'depth') !== null) {
			$depth = $this->depth();
		}

		$fields = array_values(
			array_filter(
				array_map('trim', explode(',', (string)$this->stringParam(name: 'fields'))),
				static fn (string $field): bool => $field !== ''
			)
		);

		return new JSONResponse(
			data: $this->trees->tree(
				root: $object,
				direction: $direction,
				depth: $depth,
				fields: $fields
			)
		);
	}//end tree()

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
		$object = $this->access->readable(register: $register, schema: $schema, id: $id);
		if ($object === null) {
			return $this->access->notReadable();
		}

		$purposeRefusal = $this->purposeRefusal(object: $object);
		if ($purposeRefusal !== null) {
			return $purposeRefusal;
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

		if ($refusal !== null) {
			return $refusal;
		}

		// The CSV carries the titles and uuids of every node, so it is narrowed by
		// the same doelbinding rule as the graph answer before its rows are written.
		$uuid = (string)$object->getUuid();
		$graph = $this->withinPurpose(
			graph: $this->graphs->graph(
				rootUuid: $uuid,
				depth: $this->depth(),
				language: $this->language()
			)
		);

		$handle = fopen('php://temp', 'r+');
		foreach ($this->graphs->rowsOf(graph: $graph) as $row) {
			fputcsv($handle, $row, escape: '');
		}

		rewind($handle);
		$csv = (string)stream_get_contents($handle);
		fclose($handle);

		return new DataDownloadResponse(
			data: $csv,
			filename: 'relation-graph-'.$uuid.'.csv',
			contentType: 'text/csv'
		);
	}//end exportGraph()

	/**
	 * The refusal for a read that has to name a purpose and did not, or null.
	 *
	 * The doelbinding guard the object GET runs, asked after the read check so
	 * a refusal never confirms an object the caller may not see. The names and
	 * properties these routes answer for many objects at once are the same
	 * data the object GET would refuse, and the accepted purpose is what puts
	 * the read in the processing register. An object whose register or schema
	 * does not resolve is answered as not readable: the rule cannot be applied.
	 *
	 * @param ObjectEntity $object The object the route answers for, already read under the caller's RBAC.
	 *
	 * @return JSONResponse|null The refusal, or null when the read may go on.
	 *
	 * @spec openspec/specs/verwerkingsregister-api/spec.md
	 */
	private function purposeRefusal(ObjectEntity $object): ?JSONResponse {
		$schema = $this->access->schemaOf(object: $object);
		$register = $this->registerOf(object: $object);
		if ($schema === null || $register === null) {
			return $this->access->notReadable();
		}

		try {
			$this->purposeGuard->enforce(register: $register, schema: $schema);
		} catch (PurposeRefusedException $refusal) {
			return new JSONResponse(data: $refusal->toResponseBody(), statusCode: $refusal->getStatusCode());
		}

		return null;
	}//end purposeRefusal()

	/**
	 * The register an object belongs to, or null when it does not resolve.
	 *
	 * @param ObjectEntity $object The object.
	 *
	 * @return Register|null The register.
	 */
	private function registerOf(ObjectEntity $object): ?Register {
		$registerId = $object->getRegister();
		if ($registerId === null || $registerId === '') {
			return null;
		}

		try {
			return $this->registerMapper->find($registerId, _rbac: false, _multitenancy: false);
		} catch (\Throwable $e) {
			return null;
		}
	}//end registerOf()

	/**
	 * The depth this request asks for.
	 *
	 * @return int The depth.
	 *
	 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
	 */
	private function depth(): int {
		$depth = $this->request->getParam('depth', 1);

		if (is_numeric($depth) === false) {
			return 1;
		}

		return max(1, (int)$depth);
	}//end depth()

	/**
	 * The language the caller asked for, defaulting to Dutch.
	 *
	 * @return string The BCP-47 tag.
	 *
	 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
	 */
	private function language(): string {
		$explicit = trim((string)$this->request->getParam('language', ''));

		if ($explicit !== '') {
			return $explicit;
		}

		return 'nl';
	}//end language()

	/**
	 * Read a string request parameter, or null when it is absent or empty.
	 *
	 * @param string $name The parameter name.
	 *
	 * @return string|null The value.
	 *
	 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
	 */
	private function stringParam(string $name): ?string {
		$value = $this->request->getParam($name);

		if (is_string($value) === false || trim($value) === '') {
			return null;
		}

		return trim($value);
	}//end stringParam()

	/**
	 * Read a register or schema reference as an int, when it is one.
	 *
	 * @param mixed $value The reference.
	 *
	 * @return int|null The id.
	 *
	 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
	 */
	private function asInt(mixed $value): ?int {
		if (is_numeric($value) === false) {
			return null;
		}

		return (int)$value;
	}//end asInt()
}//end class
