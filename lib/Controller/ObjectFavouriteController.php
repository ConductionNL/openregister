<?php

/**
 * ObjectFavouriteController: the star's two verbs.
 *
 * Starring is per-user state that must not be written through the object
 * itself, which would put "alice likes this" in the object's audit trail and
 * cut a version on every star, so it gets its own entry point.
 *
 * The state is READ off the object instead of through a third route: every
 * object read already carries `@self.favourite`, so a detail page renders the
 * star from data it has and a list renders a column of them from one query.
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
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/favourites-and-recent/specs/object-interactions/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Service\Interaction\FavouriteService;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Star an object for the calling user, and unstar it.
 */
class ObjectFavouriteController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param string $appName App name.
	 * @param IRequest $request Request.
	 * @param ObjectService $objectService Resolves an object through the RBAC boundary.
	 * @param FavouriteService $favourites The favourite primitive.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly ObjectService $objectService,
		private readonly FavouriteService $favourites,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: $appName, request: $request);

	}//end __construct()

	/**
	 * Star an object for the calling user.
	 *
	 * Idempotent: starring an object that is already starred answers the same
	 * thing rather than failing, because the caller asked for a state and that
	 * state now holds.
	 *
	 * @param string $register Register slug or id.
	 * @param string $schema Schema slug or id.
	 * @param string $id Object uuid.
	 *
	 * @return JSONResponse The stored star.
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/favourites-and-recent/specs/object-interactions/spec.md#requirement-a-user-can-star-an-object-without-changing-it
	 */
	#[NoAdminRequired]
	public function star(string $register, string $schema, string $id): JSONResponse {
		$object = $this->resolveObject(register: $register, schema: $schema, id: $id);
		if ($object instanceof JSONResponse) {
			return $object;
		}

		try {
			$favourite = $this->favourites->star(
				object: $object,
				register: $register,
				schema: $schema
			);
		} catch (NotAuthorizedException $e) {
			return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_FORBIDDEN);
		} catch (\Throwable $e) {
			return $this->unexpected(exception: $e, context: 'star');
		}

		return new JSONResponse(
			array_merge($favourite->jsonSerialize(), ['favourite' => true])
		);

	}//end star()

	/**
	 * Remove the calling user's star from an object.
	 *
	 * It stays starred for everybody else: the row this removes is nobody's but
	 * the caller's. Unstarring something that was never starred answers the
	 * same way, for the same reason starring twice does.
	 *
	 * @param string $register Register slug or id.
	 * @param string $schema Schema slug or id.
	 * @param string $id Object uuid.
	 *
	 * @return JSONResponse The new state.
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/favourites-and-recent/specs/object-interactions/spec.md#requirement-a-user-can-star-an-object-without-changing-it
	 */
	#[NoAdminRequired]
	public function unstar(string $register, string $schema, string $id): JSONResponse {
		$object = $this->resolveObject(register: $register, schema: $schema, id: $id);
		if ($object instanceof JSONResponse) {
			return $object;
		}

		try {
			$removed = $this->favourites->unstar(object: $object);
		} catch (NotAuthorizedException $e) {
			return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_FORBIDDEN);
		} catch (\Throwable $e) {
			return $this->unexpected(exception: $e, context: 'unstar');
		}

		return new JSONResponse(['favourite' => false, 'removed' => $removed]);

	}//end unstar()

	/**
	 * Resolve the target object through the RBAC boundary.
	 *
	 * An object the caller cannot READ is refused with 404 rather than 403, so
	 * this endpoint cannot be used to probe which object ids exist, and a user
	 * without read can never write a favourite row.
	 *
	 * @param string $register Register slug or id.
	 * @param string $schema Schema slug or id.
	 * @param string $id Object uuid.
	 *
	 * @return ObjectEntity|JSONResponse The object, or the response to return.
	 */
	private function resolveObject(string $register, string $schema, string $id): ObjectEntity|JSONResponse {
		$this->objectService->setRegister($register);
		$this->objectService->setSchema($schema);
		$this->objectService->setObject($id);

		try {
			$object = $this->objectService->getObject();
		} catch (\Throwable $e) {
			return new JSONResponse(['message' => 'Object not found'], Http::STATUS_NOT_FOUND);
		}

		if (($object instanceof ObjectEntity) === false) {
			return new JSONResponse(['message' => 'Object not found'], Http::STATUS_NOT_FOUND);
		}

		return $object;

	}//end resolveObject()

	/**
	 * Log an unexpected failure and return a generic error.
	 *
	 * @param \Throwable $exception The failure.
	 * @param string $context Short label for the log line.
	 *
	 * @return JSONResponse A generic 500.
	 */
	private function unexpected(\Throwable $exception, string $context): JSONResponse {
		$this->logger->error(
			message: '[ObjectFavouriteController] '.$context.': '.$exception->getMessage(),
			context: ['file' => __FILE__, 'line' => __LINE__, 'exception' => $exception]
		);

		return new JSONResponse(
			['message' => 'Could not complete the favourite request'],
			Http::STATUS_INTERNAL_SERVER_ERROR
		);

	}//end unexpected()
}//end class
