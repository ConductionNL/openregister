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
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\Relation\ObjectRelationService;
use OCA\OpenRegister\Service\Relation\RelationGraphService;
use OCA\OpenRegister\Service\Relation\RelationTypeResolver;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * ObjectRelationsController.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) A REST surface over the
 * relation row store, the graph walk and the object read it authorises against.
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
	 * @param IUserSession $userSession Current-user session.
	 *
	 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly ObjectRelationService $relations,
		private readonly RelationGraphService $graphs,
		private readonly ObjectService $objectService,
		private readonly IUserSession $userSession,
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
		$object = $this->readable(register: $register, schema: $schema, id: $id);
		if ($object === null) {
			return $this->notReadable();
		}

		$rows = $this->relations->relationsFor(
			objectUuid: (string)$object->getUuid(),
			language: $this->language()
		);

		return new JSONResponse(data: ['results' => $rows, 'total' => count($rows)]);
	}//end index()

	/**
	 * Add an address outside the product as a relation on an object.
	 *
	 * @param string $register The register slug or id.
	 * @param string $schema The schema slug or id.
	 * @param string $id The object's uuid.
	 *
	 * @return JSONResponse The row, or the refusal.
	 *
	 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
	 */
	#[NoAdminRequired]
	public function addLink(string $register, string $schema, string $id): JSONResponse {
		$object = $this->readable(register: $register, schema: $schema, id: $id);
		if ($object === null) {
			return $this->notReadable();
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
		$object = $this->readable(register: $register, schema: $schema, id: $id);
		if ($object === null) {
			return $this->notReadable();
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
		$source = $this->readable(register: $register, schema: $schema, id: $id);
		if ($source === null) {
			return $this->notReadable();
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

		$row = $this->relations->recordSplit(
			source: $source,
			created: $created,
			entry: $entry,
			relationType: $relationType
		);

		if ($applied['inherited'] !== []) {
			$row->setOrigin(ObjectRelation::ORIGIN_DERIVE);
			$row->setInherited($applied['inherited']);
			$row = $this->relations->render(
				row: $this->relations->saveRow(row: $row),
				direction: RelationTypeResolver::DIRECTION_OUTGOING,
				language: $this->language()
			);
		} else {
			$row = $this->relations->render(
				row: $row,
				direction: RelationTypeResolver::DIRECTION_OUTGOING,
				language: $this->language()
			);
		}

		return new JSONResponse(
			data: ['object' => $created->jsonSerialize(), 'relation' => $row],
			statusCode: 201
		);
	}//end derive()

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
		$object = $this->readable(register: $register, schema: $schema, id: $id);
		if ($object === null) {
			return $this->notReadable();
		}

		return new JSONResponse(
			data: $this->graphs->graph(
				rootUuid: (string)$object->getUuid(),
				depth: $this->depth(),
				language: $this->language()
			)
		);
	}//end graph()

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
		$object = $this->readable(register: $register, schema: $schema, id: $id);
		if ($object === null) {
			return $this->notReadable();
		}

		$uuid = (string)$object->getUuid();
		$export = $this->graphs->export(
			rootUuid: $uuid,
			depth: $this->depth(),
			language: $this->language()
		);

		$handle = fopen('php://temp', 'r+');
		foreach ($export['rows'] as $row) {
			fputcsv($handle, $row);
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
	 * The object behind a path, when the caller may read it.
	 *
	 * @param string $register The register slug or id.
	 * @param string $schema The schema slug or id.
	 * @param string $id The object's uuid.
	 *
	 * @return ObjectEntity|null The object, or null when it is not readable.
	 *
	 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
	 */
	private function readable(string $register, string $schema, string $id): ?ObjectEntity {
		if ($this->userSession->getUser() === null) {
			return null;
		}

		try {
			// REGISTER FIRST: setSchema() resolves its slug inside whatever
			// register is currently set, and ObjectService is reused across
			// calls in one process.
			$this->objectService->setRegister(register: $register);
			$this->objectService->setSchema(schema: $schema);

			return $this->objectService->find(
				id: $id,
				register: $register,
				schema: $schema,
				_rbac: true,
				_multitenancy: true
			);
		} catch (\Exception $e) {
			return null;
		}
	}//end readable()

	/**
	 * The one answer a caller who may not read the object gets.
	 *
	 * 404 rather than 403, so the route does not confirm that an object with
	 * that uuid exists to somebody who may not see it.
	 *
	 * @return JSONResponse The refusal.
	 *
	 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
	 */
	private function notReadable(): JSONResponse {
		return new JSONResponse(data: ['error' => 'Object not found'], statusCode: 404);
	}//end notReadable()

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
