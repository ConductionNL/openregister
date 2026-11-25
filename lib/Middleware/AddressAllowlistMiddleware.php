<?php

/**
 * AddressAllowlistMiddleware: refuses a request from outside the address allowlist of its surface.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Middleware
 * @package  OCA\OpenRegister\Middleware
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/instance-hardening-controls/specs/instance-hardening/spec.md#requirement-a-scope-may-require-a-second-factor-and-a-surface-may-be-bound-to-addresses-req-ihc-003
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Middleware;

use OCA\OpenRegister\Kernel\Contracts\AddressAllowlistsInterface;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Middleware;
use ReflectionMethod;
use Throwable;

/**
 * Binds the administration surface and the API to their administered address allowlists.
 *
 * Two surfaces, each with its own list (D-3 of instance-hardening-controls):
 *
 *  - **admin**: a route that requires an administrator, which is every route
 *    carrying neither `NoAdminRequired` nor `PublicPage`, as an annotation or
 *    as an attribute. That is the same reading Nextcloud's own security
 *    middleware makes before it lets an administrator through.
 *  - **api**: a route under the app's `/api/` path.
 *
 * A route on both surfaces must pass both lists. An empty list leaves its
 * surface unbound, as before this control existed.
 *
 * 🔴 THE REFUSAL NAMES NOTHING. The response is a bare 403 that names neither
 * the address it saw, nor the list, nor any entry on it: a caller probing the
 * instance learns only that it may not come in from where it is. The surface
 * and the address go to the server log, where the administrator reads them.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Middleware must reference NC framework types.
 *
 * @spec openspec/changes/instance-hardening-controls/specs/instance-hardening/spec.md#requirement-a-scope-may-require-a-second-factor-and-a-surface-may-be-bound-to-addresses-req-ihc-003
 */
class AddressAllowlistMiddleware extends Middleware {

	/**
	 * The path every API route of the app sits under.
	 *
	 * @var string
	 */
	public const API_PATH = '/apps/openregister/api/';

	/**
	 * Constructor.
	 *
	 * @param InboundCall                $call   The request and the annotations of the route it reaches.
	 * @param AddressAllowlistsInterface $policy Answers from the administered address allowlists.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly InboundCall $call,
		private readonly AddressAllowlistsInterface $policy,
	) {
	}//end __construct()

	/**
	 * Refuse the request when a surface it reaches is bound and its address is not on the list.
	 *
	 * @param Controller|string $controller The controller about to run.
	 * @param string            $methodName The method about to run.
	 *
	 * @return void
	 *
	 * @throws AddressNotAllowedException When the address is not allowed on a surface the route reaches.
	 *
	 * @spec openspec/changes/instance-hardening-controls/specs/instance-hardening/spec.md#requirement-a-scope-may-require-a-second-factor-and-a-surface-may-be-bound-to-addresses-req-ihc-003
	 */
	public function beforeController(Controller|string $controller, string $methodName): void {
		foreach ($this->surfaces(method: new DispatchedMethod(controller: $controller, methodName: $methodName)) as $surface) {
			if ($this->policy->allowsAddress(surface: $surface, address: $this->call->request->getRemoteAddress()) === false) {
				throw new AddressNotAllowedException(surface: $surface);
			}
		}
	}//end beforeController()

	/**
	 * Answer a refusal with a bare 403; any other exception is not ours.
	 *
	 * @param Controller|string $controller The controller that was to run.
	 * @param string            $methodName The method that was to run.
	 * @param \Exception        $exception  The exception thrown.
	 *
	 * @return JSONResponse The refusal.
	 *
	 * @throws \Exception The exception itself, when it is not a refusal of this middleware.
	 *
	 * @spec openspec/changes/instance-hardening-controls/specs/instance-hardening/spec.md#requirement-a-scope-may-require-a-second-factor-and-a-surface-may-be-bound-to-addresses-req-ihc-003
	 */
	public function afterException(Controller|string $controller, string $methodName, \Exception $exception): JSONResponse {
		if (($exception instanceof AddressNotAllowedException) === false) {
			throw $exception;
		}

		$this->call->logger->warning(
			'[AddressAllowlist] Refused a request from {address} on the {surface} surface',
			['address' => $this->call->request->getRemoteAddress(), 'surface' => $exception->surface]
		);

		return new JSONResponse(['message' => 'Access forbidden'], Http::STATUS_FORBIDDEN);
	}//end afterException()

	/**
	 * The surfaces a route reaches: `admin` for an administrator-only route, `api` for one under the API path.
	 *
	 * @param DispatchedMethod $method The route.
	 *
	 * @return array<int, string> The surfaces.
	 */
	private function surfaces(DispatchedMethod $method): array {
		$surfaces = [];
		if ($this->requiresAdministrator(method: $method) === true) {
			$surfaces[] = AddressAllowlistsInterface::ADMIN;
		}

		if (str_contains((string)$this->call->request->getPathInfo(), self::API_PATH) === true) {
			$surfaces[] = AddressAllowlistsInterface::API;
		}

		return $surfaces;
	}//end surfaces()

	/**
	 * Whether a route requires an administrator: neither `NoAdminRequired` nor `PublicPage`, as annotation or attribute.
	 *
	 * @param DispatchedMethod $method The route.
	 *
	 * @return bool True for an administrator-only route.
	 */
	private function requiresAdministrator(DispatchedMethod $method): bool {
		// `reflect()` is only on the concrete reflector the container injects,
		// as PublicApiCorsMiddleware notes; guarded so a double works too.
		if (method_exists($this->call->reflector, 'reflect') === true) {
			$this->call->reflector->reflect($method->controller, $method->methodName);
		}

		foreach (['NoAdminRequired', 'PublicPage'] as $annotation) {
			if ($this->call->reflector->hasAnnotation($annotation) === true) {
				return false;
			}
		}

		return $this->carriesOpenAttribute(method: $method) === false;
	}//end requiresAdministrator()

	/**
	 * Whether the method carries the `NoAdminRequired` or `PublicPage` attribute.
	 *
	 * A method that cannot be reflected is read as carrying neither, so it
	 * stays on the administration surface: the stricter reading.
	 *
	 * @param DispatchedMethod $method The route.
	 *
	 * @return bool True when either attribute is present.
	 */
	private function carriesOpenAttribute(DispatchedMethod $method): bool {
		try {
			$reflection = new ReflectionMethod($method->controller, $method->methodName);
		} catch (Throwable) {
			return false;
		}

		return $reflection->getAttributes(NoAdminRequired::class) !== []
			|| $reflection->getAttributes(PublicPage::class) !== [];
	}//end carriesOpenAttribute()
}//end class
