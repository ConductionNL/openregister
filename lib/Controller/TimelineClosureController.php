<?php

/**
 * TimelineClosureController — close an object to new timeline entries and notes, and reopen it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/object-archive-state/specs/object-lifecycle/spec.md#requirement-a-record-closes-to-new-entries-and-an-entry-can-be-withdrawn-or-locked-req-oas-006
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\AppIdentity;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Notifications\Service\Timeline\EntryClosureService;
use OCA\OpenRegister\Notifications\Service\Timeline\TimelinePermissionException;
use OCA\OpenRegister\Notifications\Service\Timeline\TimelineValidationException;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * `/api/objects/{register}/{schema}/{id}/entries-closure`: read, close (POST with `reason`) and reopen (DELETE).
 *
 * The object is resolved through the RBAC boundary, so an object the caller may not read is a 404.
 * Closing and reopening also need the right to manage the object's timeline (403 otherwise).
 *
 * @spec openspec/changes/object-archive-state/specs/object-lifecycle/spec.md#requirement-a-record-closes-to-new-entries-and-an-entry-can-be-withdrawn-or-locked-req-oas-006
 */
class TimelineClosureController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest                  $request  The request.
	 * @param ObjectOperationsInterface $objects  Resolves the object through the RBAC boundary.
	 * @param EntryClosureService       $closures Closes, reopens and reads the closure.
	 * @param LoggerInterface           $logger   Logs a failure nobody expected.
	 *
	 * @spec openspec/changes/object-archive-state/specs/object-lifecycle/spec.md#requirement-a-record-closes-to-new-entries-and-an-entry-can-be-withdrawn-or-locked-req-oas-006
	 */
	public function __construct(
		IRequest $request,
		private readonly ObjectOperationsInterface $objects,
		private readonly EntryClosureService $closures,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Whether the object accepts new entries, and its closure when it does not.
	 *
	 * @param string $register Register slug or id.
	 * @param string $schema   Schema slug or id.
	 * @param string $id       Object uuid.
	 *
	 * @return JSONResponse `{closed, closure}`, or 404.
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/object-archive-state/specs/object-lifecycle/spec.md#requirement-a-record-closes-to-new-entries-and-an-entry-can-be-withdrawn-or-locked-req-oas-006
	 */
	#[NoAdminRequired]
	public function show(string $register, string $schema, string $id): JSONResponse {
		$object = $this->resolve(path: ['register' => $register, 'schema' => $schema, 'id' => $id]);
		if ($object instanceof JSONResponse) {
			return $object;
		}

		return $this->answer(closure: $this->closures->closureOf(objectUuid: (string)$object->getUuid()));
	}//end show()

	/**
	 * Close the object to new entries, with a reason.
	 *
	 * @param string $register Register slug or id.
	 * @param string $schema   Schema slug or id.
	 * @param string $id       Object uuid.
	 *
	 * @return JSONResponse `{closed: true, closure}`, or 400/403/404.
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/object-archive-state/specs/object-lifecycle/spec.md#requirement-a-record-closes-to-new-entries-and-an-entry-can-be-withdrawn-or-locked-req-oas-006
	 */
	#[NoAdminRequired]
	public function close(string $register, string $schema, string $id): JSONResponse {
		$object = $this->resolve(path: ['register' => $register, 'schema' => $schema, 'id' => $id]);
		if ($object instanceof JSONResponse) {
			return $object;
		}

		$reason = $this->request->getParam('reason', '');
		if (is_string($reason) === false) {
			$reason = '';
		}

		try {
			return $this->answer(closure: $this->closures->close(object: $object, reason: $reason));
		} catch (TimelinePermissionException $e) {
			return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_FORBIDDEN);
		} catch (TimelineValidationException $e) {
			return new JSONResponse(['message' => $e->getMessage(), 'errors' => $e->getErrors()], Http::STATUS_BAD_REQUEST);
		} catch (Throwable $e) {
			return $this->unexpected(exception: $e);
		}
	}//end close()

	/**
	 * Reopen the object to new entries.
	 *
	 * @param string $register Register slug or id.
	 * @param string $schema   Schema slug or id.
	 * @param string $id       Object uuid.
	 *
	 * @return JSONResponse `{closed: false, closure: null}`, or 403/404.
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/object-archive-state/specs/object-lifecycle/spec.md#requirement-a-record-closes-to-new-entries-and-an-entry-can-be-withdrawn-or-locked-req-oas-006
	 */
	#[NoAdminRequired]
	public function reopen(string $register, string $schema, string $id): JSONResponse {
		$object = $this->resolve(path: ['register' => $register, 'schema' => $schema, 'id' => $id]);
		if ($object instanceof JSONResponse) {
			return $object;
		}

		try {
			$this->closures->reopen(object: $object);
		} catch (TimelinePermissionException $e) {
			return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_FORBIDDEN);
		} catch (Throwable $e) {
			return $this->unexpected(exception: $e);
		}

		return $this->answer(closure: null);
	}//end reopen()

	/**
	 * The object at a route path, or the 404 for one the caller may not read.
	 *
	 * @param array<string, string> $path The register, schema and object id.
	 *
	 * @return ObjectEntity|JSONResponse The object, or the refusal.
	 */
	private function resolve(array $path): ObjectEntity|JSONResponse {
		if ($path['register'] === '' || $path['schema'] === '' || $path['id'] === '') {
			return new JSONResponse(['message' => 'Object not found'], Http::STATUS_NOT_FOUND);
		}

		try {
			// Register first, then schema, then the object: setObject() throws for an unknown
			// object and for one the caller may not read.
			$this->objects->setRegister($path['register']);
			$this->objects->setSchema($path['schema']);
			$this->objects->setObject($path['id']);
			$object = $this->objects->getObject();
		} catch (Throwable) {
			return new JSONResponse(['message' => 'Object not found'], Http::STATUS_NOT_FOUND);
		}

		if (($object instanceof ObjectEntity) === false) {
			return new JSONResponse(['message' => 'Object not found'], Http::STATUS_NOT_FOUND);
		}

		return $object;
	}//end resolve()

	/**
	 * The answer for a closure, or for an open object.
	 *
	 * @param array{by: string, at: string|null, reason: string}|null $closure The closure.
	 *
	 * @return JSONResponse `{closed, closure}`.
	 */
	private function answer(?array $closure): JSONResponse {
		return new JSONResponse(['closed' => $closure !== null, 'closure' => $closure]);
	}//end answer()

	/**
	 * A 500 that says nothing about the instance, with the failure logged.
	 *
	 * @param Throwable $exception The failure.
	 *
	 * @return JSONResponse The 500.
	 */
	private function unexpected(Throwable $exception): JSONResponse {
		$this->logger->error('[TimelineClosureController] failed', ['exception' => $exception]);

		return new JSONResponse(['message' => 'The closure could not be changed'], Http::STATUS_INTERNAL_SERVER_ERROR);
	}//end unexpected()
}//end class
