<?php

/**
 * The object a party route acts on, and the right a party write asks.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller\Support
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

namespace OCA\OpenRegister\Controller\Support;

use Exception;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Service\Object\PermissionHandlerInterface;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\JSONResponse;

/**
 * Resolves the object of a party route and runs a handler against it.
 *
 * A read resolves the object through the object service, which applies the
 * caller's read right. A write additionally asks the `update` verdict on the
 * object before the handler runs: who holds a role on an object is part of
 * the object. Failures become responses keeping the status they named.
 *
 * @spec openspec/specs/party-model/spec.md
 */
final class PartyObjectAccess {

	/**
	 * The statuses an exception may name for its response.
	 *
	 * @var array<int, int>
	 */
	private const KEPT_STATUSES = [400, 401, 403, 404, 409, 422];

	/**
	 * Bundle the object service and the permission check.
	 *
	 * @param ObjectOperationsInterface  $objects     Object service, to resolve the object.
	 * @param PermissionHandlerInterface $permissions The object-level permission check.
	 *
	 * @spec openspec/specs/party-model/spec.md
	 */
	public function __construct(
		public readonly ObjectOperationsInterface $objects,
		public readonly PermissionHandlerInterface $permissions,
	) {
	}//end __construct()

	/**
	 * Run a handler against a resolved object, mapping the failures to status codes.
	 *
	 * @param array{0: string, 1: string, 2: string} $target  Register slug, schema slug, object id.
	 * @param callable                               $handler What to do with the object.
	 *
	 * @return JSONResponse The handler's response, or the error.
	 *
	 * @spec openspec/specs/party-model/spec.md
	 */
	public function read(array $target, callable $handler): JSONResponse {
		try {
			$object = $this->resolve(target: $target);
			if ($object === null) {
				return $this->notFound();
			}

			return $handler($object);
		} catch (DoesNotExistException $e) {
			return $this->notFound();
		} catch (Exception $e) {
			return $this->errorResponse(exception: $e);
		}//end try
	}//end read()

	/**
	 * Run a handler against a resolved object the caller may update.
	 *
	 * The update right is asked before anything is written; a refusal is a 403.
	 *
	 * @param array{0: string, 1: string, 2: string} $target  Register slug, schema slug, object id.
	 * @param callable                               $handler What to do with the object.
	 *
	 * @return JSONResponse The handler's response, or the error.
	 *
	 * @spec openspec/specs/party-model/spec.md#requirement-a-party-write-needs-the-update-right-on-the-object-req-prm-005
	 */
	public function write(array $target, callable $handler): JSONResponse {
		return $this->read(
			target: $target,
			handler: function (ObjectEntity $object) use ($handler): JSONResponse {
				$this->assertMayUpdate(object: $object);

				return $handler($object);
			}
		);
	}//end write()

	/**
	 * An exception as a response, keeping the status it named.
	 *
	 * @param Exception $exception The exception.
	 *
	 * @return JSONResponse The error.
	 *
	 * @spec openspec/specs/party-model/spec.md
	 */
	public function errorResponse(Exception $exception): JSONResponse {
		$code = (int)$exception->getCode();
		if (in_array($code, self::KEPT_STATUSES, true) === false) {
			$code = 400;
		}

		return new JSONResponse(['error' => $exception->getMessage()], $code);
	}//end errorResponse()

	/**
	 * Point the object service at the target and read the object.
	 *
	 * @param array{0: string, 1: string, 2: string} $target Register slug, schema slug, object id.
	 *
	 * @return ObjectEntity|null The object, or null when there is none.
	 *
	 * @spec openspec/specs/party-model/spec.md
	 */
	private function resolve(array $target): ?ObjectEntity {
		$this->objects->setRegister($target[0]);
		$this->objects->setSchema($target[1]);
		$this->objects->setObject($target[2]);

		return $this->objects->getObject();
	}//end resolve()

	/**
	 * Ask the `update` verdict on the object; throws when it is refused.
	 *
	 * @param ObjectEntity $object The object a party write changes.
	 *
	 * @return void
	 *
	 * @throws NotAuthorizedException When the schema cannot be read or the right is refused.
	 *
	 * @spec openspec/specs/party-model/spec.md#requirement-a-party-write-needs-the-update-right-on-the-object-req-prm-005
	 */
	private function assertMayUpdate(ObjectEntity $object): void {
		$schemaEntity = $this->objects->getCurrentSchemaEntity();
		if ($schemaEntity === null) {
			throw new NotAuthorizedException(message: 'The object\'s schema could not be read, so its update right cannot be asked');
		}

		$this->permissions->checkPermission(
			schema: $schemaEntity,
			action: 'update',
			userId: null,
			objectOwner: $object->getOwner(),
			_rbac: true,
			object: $object
		);
	}//end assertMayUpdate()

	/**
	 * The response for an object that does not resolve.
	 *
	 * @return JSONResponse HTTP 404.
	 *
	 * @spec openspec/specs/party-model/spec.md
	 */
	private function notFound(): JSONResponse {
		return new JSONResponse(['error' => 'Object not found'], 404);
	}//end notFound()
}//end class
