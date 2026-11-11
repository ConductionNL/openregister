<?php

/**
 * OpenRegister readable object
 *
 * Resolves the object a request path names through the RBAC boundary of the
 * object service, answering 404 for an object that is unknown or unreadable.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Notifications\Controller\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/specs/object-interactions/spec.md#requirement-a-user-can-watch-an-object-they-may-read
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Notifications\Controller\Support;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use Throwable;

/**
 * The object a path names when the caller may read it, else the 404.
 *
 * An object the caller cannot READ is refused with 404 rather than 403, so
 * an endpoint cannot be used to probe which object ids exist.
 *
 * @spec openspec/specs/object-interactions/spec.md#requirement-a-user-can-watch-an-object-they-may-read
 */
final class ReadableObject {
	/**
	 * Constructor.
	 *
	 * @param ObjectOperationsInterface $objects The object service, which applies RBAC on lookup.
	 *
	 * @spec openspec/specs/object-interactions/spec.md#requirement-a-user-can-watch-an-object-they-may-read
	 */
	public function __construct(
		private readonly ObjectOperationsInterface $objects,
	) {
	}//end __construct()

	/**
	 * Resolve the object, register first, then schema, then the object.
	 *
	 * REGISTER FIRST: `setSchema()` scopes its slug lookup to whatever register
	 * is currently set on this shared service, so setting the schema first
	 * resolves it against a register left behind by an unrelated call.
	 *
	 * @param array<string, string> $path The register slug or id, schema slug or id and object uuid.
	 *
	 * @return ObjectEntity|JSONResponse The object, or a 404.
	 *
	 * @spec openspec/specs/object-interactions/spec.md#requirement-a-user-can-watch-an-object-they-may-read
	 */
	public function resolve(array $path): ObjectEntity|JSONResponse {
		try {
			// The setObject() call looks the object up and throws for an unknown object, or one the caller may not read.
			$this->objects->setRegister($path['register']);
			$this->objects->setSchema($path['schema']);
			$this->objects->setObject($path['id']);
			$object = $this->objects->getObject();
		} catch (Throwable $e) {
			return new JSONResponse(['message' => 'Object not found'], Http::STATUS_NOT_FOUND);
		}

		if (($object instanceof ObjectEntity) === false) {
			return new JSONResponse(['message' => 'Object not found'], Http::STATUS_NOT_FOUND);
		}

		return $object;
	}//end resolve()
}//end class
