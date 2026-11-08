<?php

/**
 * OpenRegister object relations purpose scope
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller\Support;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Service\Audit\PurposeGuard;
use OCA\OpenRegister\Service\Audit\PurposeRefusedException;
use OCA\OpenRegister\Service\Object\ObjectReadAccessInterface;
use OCP\AppFramework\Http\JSONResponse;

/**
 * The doelbinding rule the object read runs, applied to the relation graph and tree routes.
 *
 * The names and properties these routes answer for many objects at once are
 * the same data the object GET would refuse, and the accepted purpose is what
 * puts the read in the processing register.
 *
 * @spec openspec/specs/verwerkingsregister-api/spec.md
 */
final class ObjectRelationsPurposeScope {
	/**
	 * Cuts refused nodes out of a graph.
	 *
	 * @var ObjectRelationsReachability
	 */
	private readonly ObjectRelationsReachability $reachability;

	/**
	 * Constructor.
	 *
	 * @param ObjectReadAccessInterface $access         Resolves an object's schema under the caller's own RBAC.
	 * @param PurposeGuard              $purposeGuard   The doelbinding guard the object read runs.
	 * @param RegisterMapperInterface   $registerMapper Resolves an object's register for that guard.
	 */
	public function __construct(
		public readonly ObjectReadAccessInterface $access,
		public readonly PurposeGuard $purposeGuard,
		public readonly RegisterMapperInterface $registerMapper,
	) {
		$this->reachability = new ObjectRelationsReachability();
	}//end __construct()

	/**
	 * The refusal for a read that has to name a purpose and did not, or null.
	 *
	 * Asked after the read check so a refusal never confirms an object the
	 * caller may not see. An object whose register or schema does not resolve
	 * is answered as not readable: the rule cannot be applied.
	 *
	 * @param ObjectEntity $object The object the route answers for, already read under the caller's RBAC.
	 *
	 * @return JSONResponse|null The refusal, or null when the read may go on.
	 *
	 * @spec openspec/specs/verwerkingsregister-api/spec.md
	 */
	public function refusalFor(ObjectEntity $object): ?JSONResponse {
		$schema = $this->access->schemaOf(object: $object);
		$register = $this->registerFor(registerId: $object->getRegister());
		if ($schema === null || $register === null) {
			return $this->access->notReadable();
		}

		try {
			$this->purposeGuard->enforce(register: $register, schema: $schema);
		} catch (PurposeRefusedException $refusal) {
			return new JSONResponse(data: $refusal->toResponseBody(), statusCode: $refusal->getStatusCode());
		}

		return null;
	}//end refusalFor()

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
	public function narrow(array $graph): array {
		$refused = $this->refusedIn(nodes: ($graph['nodes'] ?? []));
		if ($refused === []) {
			return $graph;
		}

		return $this->reachability->reachableFromRoot(graph: $graph, refused: $refused);
	}//end narrow()

	/**
	 * The uuids of the resolved, internal nodes whose schema the guard refuses.
	 *
	 * Each register and schema pair is decided once.
	 *
	 * @param iterable<mixed> $nodes The graph's nodes.
	 *
	 * @return array<string, true> The refused uuids.
	 */
	private function refusedIn(iterable $nodes): array {
		$refused = [];
		$decided = [];
		foreach ($nodes as $node) {
			if (self::isResolvedInternal(node: $node) === false) {
				continue;
			}

			$key = ((string)($node['register'] ?? '')).'/'.((string)($node['schema'] ?? ''));
			$decided[$key] ??= $this->admits(registerId: $node['register'] ?? null, schemaId: $node['schema'] ?? null);
			if ($decided[$key] === false) {
				$refused[(string)($node['uuid'] ?? '')] = true;
			}
		}

		return $refused;
	}//end refusedIn()

	/**
	 * Whether a node names an object of this instance that the walk resolved.
	 *
	 * @param mixed $node The node.
	 *
	 * @return bool True for a resolved node that is not external.
	 */
	private static function isResolvedInternal(mixed $node): bool {
		return ($node['resolved'] ?? false) === true && ($node['external'] ?? false) !== true;
	}//end isResolvedInternal()

	/**
	 * Whether the doelbinding guard admits a read of the schema of one node.
	 *
	 * @param mixed $registerId The node's register id.
	 * @param mixed $schemaId   The node's schema id.
	 *
	 * @return bool False when a purpose is needed and none is usable, or the schema does not resolve.
	 */
	private function admits(mixed $registerId, mixed $schemaId): bool {
		$schema = null;
		if (is_scalar($schemaId) === true) {
			$schema = $this->access->schemaById(schemaId: $schemaId);
		}

		$register = $this->registerFor(registerId: $registerId);
		if ($schema === null || $register === null) {
			return false;
		}

		try {
			$this->purposeGuard->enforce(register: $register, schema: $schema);
		} catch (PurposeRefusedException $refusal) {
			return false;
		}

		return true;
	}//end admits()

	/**
	 * The register an id names, or null when it is none or does not resolve.
	 *
	 * @param mixed $registerId The register id.
	 *
	 * @return Register|null The register.
	 */
	private function registerFor(mixed $registerId): ?Register {
		if (is_scalar($registerId) === false || $registerId === '') {
			return null;
		}

		try {
			return $this->registerMapper->find($registerId, _rbac: false, _multitenancy: false);
		} catch (\Throwable $e) {
			return null;
		}
	}//end registerFor()
}//end class
