<?php

/**
 * Take emergency self-granted access on one record, with a reason.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/grants-that-follow-a-slot-a-relation-or-a-reason/specs/rbac-scopes/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use InvalidArgumentException;
use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Kernel\Contracts\AppIdentity;
use OCA\OpenRegister\Service\Rbac\BreakGlassDeclaration;
use OCA\OpenRegister\Service\Rbac\BreakGlassService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * `POST /api/objects/{register}/{schema}/{id}/break-glass` with `{"reason": "..."}`.
 *
 * THE CALLER HAS NO ACCESS YET, which is the point: the record is looked up
 * without the read check, because a read check would answer 404 to exactly the
 * person the declaration is for. Two things keep that from becoming an oracle.
 * The schema's declaration is checked FIRST, so a schema that does not offer
 * emergency access is refused naming the schema before any record is looked
 * up. And the service decides who may take it: a signed-in caller, in the
 * declared groups when the declaration names any, with a reason. Nothing is
 * returned of the record itself; the answer is the grant.
 *
 * @spec openspec/changes/grants-that-follow-a-slot-a-relation-or-a-reason/specs/rbac-scopes/spec.md#requirement-emergency-self-granted-access-is-declared-bounded-and-announced-req-gsr-004
 */
class BreakGlassController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest                $request    The request.
	 * @param RegisterMapperInterface $registers  Finds the register.
	 * @param SchemaMapperInterface   $schemas    Finds the schema.
	 * @param MagicMapperInterface    $objects    Finds the record without the read check.
	 * @param BreakGlassService       $breakGlass Takes the grant.
	 * @param LoggerInterface         $logger     Logs an unexpected failure.
	 */
	public function __construct(
		IRequest $request,
		private readonly RegisterMapperInterface $registers,
		private readonly SchemaMapperInterface $schemas,
		private readonly MagicMapperInterface $objects,
		private readonly BreakGlassService $breakGlass,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Take emergency access on one record.
	 *
	 * @param string $register Register slug or id.
	 * @param string $schema   Schema slug or id.
	 * @param string $id       Record uuid.
	 *
	 * @return JSONResponse 201 with the grant; 400 without a reason; 403 when refused; 404 when no such record.
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/grants-that-follow-a-slot-a-relation-or-a-reason/specs/rbac-scopes/spec.md#requirement-emergency-self-granted-access-is-declared-bounded-and-announced-req-gsr-004
	 */
	#[NoAdminRequired]
	public function take(string $register, string $schema, string $id): JSONResponse {
		try {
			$schemaEntity = $this->schemas->find(id: $schema, _rbac: false, _multitenancy: false);
			if (BreakGlassDeclaration::fromSchema(schema: $schemaEntity) === null) {
				throw new NotAuthorizedException(
					message: 'Schema "' . ((string)($schemaEntity->getSlug() ?? $schemaEntity->getId())) . '" does not offer emergency access'
				);
			}

			$registerEntity = $this->registers->find(id: $register, _rbac: false, _multitenancy: false);
			$object = $this->objects->find(identifier: $id, register: $registerEntity, schema: $schemaEntity, includeDeleted: false, _rbac: false);

			$grant = $this->breakGlass->take(object: $object, schema: $schemaEntity, reason: $this->request->getParam('reason'));

			return new JSONResponse($grant, Http::STATUS_CREATED);
		} catch (NotAuthorizedException $e) {
			return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_FORBIDDEN);
		} catch (InvalidArgumentException $e) {
			return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (DoesNotExistException $e) {
			return new JSONResponse(['message' => 'Object not found'], Http::STATUS_NOT_FOUND);
		} catch (Throwable $e) {
			$this->logger->error(
				message: '[BreakGlassController] Could not take emergency access: ' . $e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__, 'exception' => $e]
			);

			return new JSONResponse(['message' => 'Could not take emergency access'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}//end try
	}//end take()
}//end class
