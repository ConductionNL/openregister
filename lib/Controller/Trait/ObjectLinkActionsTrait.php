<?php

/**
 * ObjectLinkActionsTrait
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Controller
 * @package   OCA\OpenRegister
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/openregister
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller\Trait;

use Closure;
use Exception;
use OCA\OpenRegister\Controller\Support\ObjectLinkRoute;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\ObjectRef;
use OCA\OpenRegister\Service\LinkTarget;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\JSONResponse;

/**
 * The shared frame of the object link controllers (Talk, Deck, Polls, ...).
 *
 * Every link action resolves the object from its route, answers 404 when it does
 * not exist, and runs its own step on it. The using controller provides
 * `$this->objectService` and `mapException()`.
 *
 * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-1/tasks.md#task-1
 */
trait ObjectLinkActionsTrait
{
	use ResolvesLinkedObjectTrait;

	/**
	 * The 501 answer when the linked Nextcloud app is not installed.
	 *
	 * @param string $appName The app as users know it, e.g. `Nextcloud Talk`.
	 *
	 * @return JSONResponse The response.
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-1/tasks.md#task-1
	 */
	private function appNotInstalled(string $appName): JSONResponse {
		return new JSONResponse(['error' => $appName . ' app is not installed', 'code' => 'APP_NOT_AVAILABLE'], 501);
	}//end appNotInstalled()

	/**
	 * Run a link action on the object its route names; a failure is mapped by mapException().
	 *
	 * @param ObjectLinkRoute $route  The object's route.
	 * @param Closure         $action The step, given the object; returns the response.
	 *
	 * @return JSONResponse The step's response, 404 for a missing object, or the mapped failure.
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-1/tasks.md#task-1
	 */
	private function onLinkedObject(ObjectLinkRoute $route, Closure $action): JSONResponse {
		try {
			return $this->runOnLinkedObject(route: $route, action: $action);
		} catch (Exception $e) {
			return $this->mapException(exception: $e);
		}
	}//end onLinkedObject()

	/**
	 * The link target (uuid, register, schema) of a stored object.
	 *
	 * @param ObjectEntity $object The object.
	 *
	 * @return LinkTarget The target.
	 */
	private function linkTargetOf(ObjectEntity $object): LinkTarget {
		return (new LinkTarget(objectUuid: $object->getUuid(), registerId: (int)$object->getRegister()))
			->inSchema(schemaId: (int)$object->getSchema());
	}//end linkTargetOf()

	/**
	 * Run a listing on the object its route names; a failure answers 500 with its message.
	 *
	 * @param ObjectLinkRoute $route  The object's route.
	 * @param Closure         $action The listing, given the object; returns the response.
	 *
	 * @return JSONResponse The listing, 404 for a missing object, or 500.
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-1/tasks.md#task-1
	 */
	private function listLinkedObject(ObjectLinkRoute $route, Closure $action): JSONResponse {
		try {
			return $this->runOnLinkedObject(route: $route, action: $action);
		} catch (Exception $e) {
			return new JSONResponse(['error' => $e->getMessage()], 500);
		}
	}//end listLinkedObject()

	/**
	 * Resolve the object of a route and run the step on it, answering 404 when it does not exist.
	 *
	 * @param ObjectLinkRoute $route  The object's route.
	 * @param Closure         $action The step, given the object; returns the response.
	 *
	 * @return JSONResponse The step's response, or 404.
	 *
	 * @throws Exception Whatever the step throws; the caller maps it.
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-1/tasks.md#task-1
	 */
	private function runOnLinkedObject(ObjectLinkRoute $route, Closure $action): JSONResponse {
		try {
			$object = $this->resolveLinkedObject(ref: new ObjectRef(register: $route->register, schema: $route->schema, id: $route->id));
		} catch (DoesNotExistException $e) {
			return new JSONResponse(['error' => 'Object not found'], 404);
		}

		if ($object === null) {
			return new JSONResponse(['error' => 'Object not found'], 404);
		}

		try {
			return $action($object);
		} catch (DoesNotExistException $e) {
			return new JSONResponse(['error' => 'Object not found'], 404);
		}
	}//end runOnLinkedObject()

	/**
	 * A link action that answers the links the fetch finds, with their count.
	 *
	 * @param Closure $fetch Given the object, returns its links.
	 *
	 * @return Closure The action, for onLinkedObject() or listLinkedObject().
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-1/tasks.md#task-1
	 */
	private function resultsOf(Closure $fetch): Closure {
		return function (ObjectEntity $object) use ($fetch): JSONResponse {
			$results = $fetch($object);
			return new JSONResponse(['results' => $results, 'total' => count($results)]);
		};
	}//end resultsOf()

	/**
	 * A link action that creates a named item and links it: 400 without a name, 201 with the link.
	 *
	 * @param Closure $create Given the object's uuid, register id, schema id and the name, creates and links the item; returns the link.
	 *
	 * @return Closure The action, for onLinkedObject().
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-1/tasks.md#task-1
	 */
	private function namedLinkOf(Closure $create): Closure {
		return function (ObjectEntity $object) use ($create): JSONResponse {
			$name = (string)$this->request->getParam('name', '');
			if (trim($name) === '') {
				return new JSONResponse(['error' => 'name is required'], 400);
			}

			$link = $create($this->linkTargetOf(object: $object), $name);
			return new JSONResponse($link->jsonSerialize(), 201);
		};
	}//end namedLinkOf()
}//end trait
