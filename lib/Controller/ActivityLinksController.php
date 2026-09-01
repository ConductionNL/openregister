<?php

/**
 * ActivityLinksController — Tier-2 read-only REST controller for the
 * `activity` integration leaf.
 *
 * NC Activity entries are core-generated and read-only; there is NO
 * link, create, or delete verb. The Tier-2 surface is narrow:
 *
 *   - GET /api/objects/{r}/{s}/{id}/activity?type=&actor=&after=&limit=&cursor=
 *         — filtered + cursor-paginated entry feed.
 *   - GET /api/integrations/activity/types?object={r}/{s}/{id}
 *         — distinct type values (filter dropdown source).
 *   - GET /api/integrations/activity/actors?object={r}/{s}/{id}
 *         — distinct actor values (filter dropdown source).
 *
 * Entries are linked to an OR object via the `[or:{objectUuid}]` marker
 * in the `activity.subject` column (wave-5.3 MarkerLookupTrait carve-out,
 * preserved). The backing ActivityFilterService wraps that marker query
 * with type/actor/date filters and cursor pagination.
 *
 * @category  Controller
 * @package   OCA\OpenRegister\Controller
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use Exception;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ActivityFilterService;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Tier-2 Activity links controller (read-only).
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 */
class ActivityLinksController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param string $appName App id.
	 * @param IRequest $request HTTP request.
	 * @param ActivityFilterService $filterService Backing service.
	 * @param ObjectService $objectService OR object resolver.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly ActivityFilterService $filterService,
		private readonly ObjectService $objectService,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * List filtered + paginated activity entries for an object.
	 *
	 * Query params: `type`, `actor`, `after` (Unix ts), `limit`, `cursor`.
	 *
	 * @param string $register Register slug or id.
	 * @param string $schema Schema slug or id.
	 * @param string $id Object id.
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/specs/generic-integrations/spec.md#requirement-tier-2-integration-leaf-link-controller-contract
	 */
	public function index(string $register, string $schema, string $id): JSONResponse {
		if ($this->filterService->isActivityAvailable() === false) {
			return $this->unavailable();
		}

		try {
			$object = $this->validateObject(register: $register, schema: $schema, id: $id);
			if ($object === null) {
				return new JSONResponse(['error' => 'Object not found'], 404);
			}

			$result = $this->filterService->getActivityEntries(
				objectUuid: $object->getUuid(),
				type: $this->nullableString(name: 'type'),
				actor: $this->nullableString(name: 'actor'),
				after: $this->nullableInt(name: 'after'),
				limit: (int)$this->request->getParam('limit', 100),
				cursor: $this->nullableInt(name: 'cursor')
			);

			return new JSONResponse(
				[
					'results' => $result['results'],
					'total' => $result['total'],
					'nextCursor' => $result['nextCursor'],
				]
			);
		} catch (DoesNotExistException $e) {
			return new JSONResponse(['error' => 'Object not found'], 404);
		} catch (Exception $e) {
			return new JSONResponse(['error' => $e->getMessage()], 500);
		}//end try
	}//end index()

	/**
	 * Distinct activity types for the filter dropdown.
	 *
	 * Query param: `object` in the form `{register}/{schema}/{id}`.
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/specs/generic-integrations/spec.md#requirement-tier-2-integration-leaf-link-controller-contract
	 */
	public function types(): JSONResponse {
		return $this->dropdown(fetch: fn (string $uuid): array => $this->filterService->getActivityTypes(objectUuid: $uuid));
	}//end types()

	/**
	 * Distinct activity actors for the filter dropdown.
	 *
	 * Query param: `object` in the form `{register}/{schema}/{id}`.
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/specs/generic-integrations/spec.md#requirement-tier-2-integration-leaf-link-controller-contract
	 */
	public function actors(): JSONResponse {
		return $this->dropdown(fetch: fn (string $uuid): array => $this->filterService->getActivityActors(objectUuid: $uuid));
	}//end actors()

	/**
	 * Shared dropdown-source handler: resolve `object` param then run the
	 * supplied distinct-column fetcher.
	 *
	 * @param callable(string):array<int,string> $fetch Distinct-value fetcher keyed by object UUID.
	 *
	 * @return JSONResponse
	 */
	private function dropdown(callable $fetch): JSONResponse {
		if ($this->filterService->isActivityAvailable() === false) {
			return $this->unavailable();
		}

		$object = (string)$this->request->getParam('object', '');
		$parts = explode('/', $object);
		if (count($parts) !== 3 || in_array('', $parts, true) === true) {
			return new JSONResponse(
				['error' => 'object query param must be in the form {register}/{schema}/{id}'],
				400
			);
		}

		try {
			$resolved = $this->validateObject(register: $parts[0], schema: $parts[1], id: $parts[2]);
			if ($resolved === null) {
				return new JSONResponse(['error' => 'Object not found'], 404);
			}

			$values = $fetch($resolved->getUuid());
			return new JSONResponse(['results' => $values, 'total' => count($values)]);
		} catch (DoesNotExistException $e) {
			return new JSONResponse(['error' => 'Object not found'], 404);
		} catch (Exception $e) {
			return new JSONResponse(['error' => $e->getMessage()], 500);
		}//end try
	}//end dropdown()

	/**
	 * Standard "app not installed" 501 response.
	 *
	 * @return JSONResponse
	 */
	private function unavailable(): JSONResponse {
		return new JSONResponse(
			['error' => 'NC Activity app is not installed', 'code' => 'APP_NOT_AVAILABLE'],
			501
		);
	}//end unavailable()

	/**
	 * Read a request param as a trimmed non-empty string, or null.
	 *
	 * @param string $name Param name.
	 *
	 * @return string|null
	 */
	private function nullableString(string $name): ?string {
		$value = $this->request->getParam($name);
		if ($value === null) {
			return null;
		}

		$value = trim((string)$value);
		if ($value === '') {
			return null;
		}

		return $value;
	}//end nullableString()

	/**
	 * Read a request param as an int, or null when absent/empty.
	 *
	 * @param string $name Param name.
	 *
	 * @return int|null
	 */
	private function nullableInt(string $name): ?int {
		$value = $this->request->getParam($name);
		if ($value === null || $value === '') {
			return null;
		}

		return (int)$value;
	}//end nullableInt()

	/**
	 * Resolve an OR object from register/schema/id.
	 *
	 * @param string $register Register slug or id.
	 * @param string $schema Schema slug or id.
	 * @param string $id Object id.
	 *
	 * @return ObjectEntity|null
	 *
	 * @throws DoesNotExistException When no such object exists. Deliberately propagated rather
	 *                               than caught: every call site already wraps this helper and translates it to a 404.
	 *                               Swallowing it here would collapse "no such object" into the same null this method
	 *                               returns for other reasons, which the caller could no longer tell apart.
	 */
	private function validateObject(string $register, string $schema, string $id): ?ObjectEntity {
		$this->objectService->setRegister($register);
		$this->objectService->setSchema($schema);
		$this->objectService->setObject($id);

		return $this->objectService->getObject();
	}//end validateObject()
}//end class
