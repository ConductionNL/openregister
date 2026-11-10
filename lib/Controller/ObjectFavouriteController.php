<?php

/**
 * ObjectFavouriteController: the star's two verbs, deprecated.
 *
 * Since `merge-follow-and-favourites` a star is a follow with notifications
 * off. These routes stay for one release so nextcloud-vue and the leaf apps
 * can move to `.../watch` on their own schedule; every response says so in a
 * `Deprecation` header and points at the successor in a `Link` header.
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
 * @spec openspec/specs/object-interactions/spec.md#requirement-a-user-can-star-an-object-without-changing-it
 *
 * @deprecated Use PUT|DELETE .../watch. Removed in the release after merge-follow-and-favourites.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use OCA\OpenRegister\AppInfo\Application;
use OCA\OpenRegister\Controller\Support\ObjectFavouriteControllerServices;
use OCA\OpenRegister\Controller\Support\ObjectLinkRoute;
use OCA\OpenRegister\Controller\Support\ObjectSharingEndpoint;
use OCA\OpenRegister\Kernel\Contracts\ObjectRef;
use OCA\OpenRegister\Notifications\Db\Watcher;
use OCA\OpenRegister\Service\Interaction\AddressedObject;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Star an object for the calling user, and unstar it.
 */
class ObjectFavouriteController extends Controller {

	/**
	 * Resolves the target object through the RBAC boundary, runs the primitive
	 * and maps its refusals.
	 *
	 * An object the caller cannot READ is refused with 404 rather than 403, so
	 * these endpoints cannot be used to probe which object ids exist.
	 *
	 * @var ObjectSharingEndpoint
	 */
	private readonly ObjectSharingEndpoint $endpoint;

	/**
	 * The controller's collaborators.
	 *
	 * @var ObjectFavouriteControllerServices
	 */
	private readonly ObjectFavouriteControllerServices $services;

	/**
	 * Constructor.
	 *
	 * @param IRequest                          $request  Request.
	 * @param ObjectFavouriteControllerServices $services The controller's collaborators.
	 */
	public function __construct(
		IRequest $request,
		ObjectFavouriteControllerServices $services,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
		$this->services = $services;
		$failure = 'Could not complete the favourite request';
		$this->endpoint = new ObjectSharingEndpoint(objectService: $services->objectService, logger: $services->logger, file: __FILE__, failure: $failure);
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
	 * @spec openspec/specs/object-interactions/spec.md#requirement-a-user-can-star-an-object-without-changing-it
	 */
	#[NoAdminRequired]
	public function star(string $register, string $schema, string $id): JSONResponse {
		return $this->starForObject(ref: new ObjectRef(register: $register, schema: $schema, id: $id));
	}//end star()

	/**
	 * star() for the object the route names.
	 *
	 * @param ObjectRef $ref The object the route names.
	 *
	 * @return JSONResponse The stored star.
	 */
	private function starForObject(ObjectRef $ref): JSONResponse {
		$object = $this->endpoint->resolve(ref: $ref);
		if ($object instanceof JSONResponse) {
			return $object;
		}

		$star = fn (): Watcher => $this->services->favourites->star(target: new AddressedObject(object: $object, register: (string)$ref->register, schema: (string)$ref->schema));
		$favourite = $this->endpoint->run(operation: $star, context: 'star');
		if ($favourite instanceof JSONResponse) {
			return $favourite;
		}

		$body = array_merge($favourite->jsonSerialize(), ['favourite' => true, 'watching' => true]);
		return $this->deprecated(
			response: new JSONResponse($body),
			route: new ObjectLinkRoute(register: (string)$ref->register, schema: (string)$ref->schema, id: $ref->id)
		);
	}//end starForObject()

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
	 * @spec openspec/specs/object-interactions/spec.md#requirement-a-user-can-star-an-object-without-changing-it
	 */
	#[NoAdminRequired]
	public function unstar(string $register, string $schema, string $id): JSONResponse {
		return $this->unstarForObject(ref: new ObjectRef(register: $register, schema: $schema, id: $id));
	}//end unstar()

	/**
	 * unstar() for the object the route names.
	 *
	 * @param ObjectRef $ref The object the route names.
	 *
	 * @return JSONResponse The new state.
	 */
	private function unstarForObject(ObjectRef $ref): JSONResponse {
		$object = $this->endpoint->resolve(ref: $ref);
		if ($object instanceof JSONResponse) {
			return $object;
		}

		$removed = $this->endpoint->run(operation: fn (): bool => $this->services->favourites->unstar(object: $object), context: 'unstar');
		if ($removed instanceof JSONResponse) {
			return $removed;
		}

		$body = ['favourite' => false, 'watching' => false, 'removed' => $removed];
		return $this->deprecated(
			response: new JSONResponse($body),
			route: new ObjectLinkRoute(register: (string)$ref->register, schema: (string)$ref->schema, id: $ref->id)
		);
	}//end unstarForObject()

	/**
	 * Mark a response as coming from a deprecated route, naming its successor.
	 *
	 * @param JSONResponse    $response The response to mark.
	 * @param ObjectLinkRoute $route    Register slug or id, schema slug or id, and object uuid.
	 *
	 * @return JSONResponse The same response, with the two headers.
	 */
	private function deprecated(JSONResponse $response, ObjectLinkRoute $route): JSONResponse {
		$successor = '/index.php/apps/openregister/api/objects/'.rawurlencode($route->register)
			.'/'.rawurlencode($route->schema).'/'.rawurlencode($route->id).'/watch';

		$response->addHeader('Deprecation', 'true');
		$response->addHeader('Link', '<'.$successor.'>; rel="successor-version"');

		return $response;

	}//end deprecated()
}//end class
