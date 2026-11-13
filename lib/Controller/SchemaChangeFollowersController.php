<?php

/**
 * Follow and unfollow a schema's breaking changes.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/schema-breaking-change-notice/specs/schema-migration/spec.md#requirement-a-reader-may-follow-a-schemas-breaking-changes
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use OCA\OpenRegister\Db\SchemaFollowerMapper;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Throwable;

/**
 * `POST` and `DELETE /api/schemas/{id}/change-followers` for the signed-in user.
 *
 * Following needs read on the schema, asked the way the schema read endpoint
 * asks it (the mapper's RBAC and organisation scope for the current user); a
 * user who cannot read it gets 404, so the schema's existence is not told.
 *
 * @spec openspec/changes/schema-breaking-change-notice/specs/schema-migration/spec.md#requirement-a-reader-may-follow-a-schemas-breaking-changes
 */
class SchemaChangeFollowersController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param string                $appName   The app.
	 * @param IRequest              $request   The request.
	 * @param SchemaMapperInterface $schemas   The schemas, read as the current user.
	 * @param SchemaFollowerMapper  $followers The followers.
	 * @param IUserSession          $session   The signed-in user.
	 *
	 * @spec openspec/changes/schema-breaking-change-notice/specs/schema-migration/spec.md#requirement-a-reader-may-follow-a-schemas-breaking-changes
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly SchemaMapperInterface $schemas,
		private readonly SchemaFollowerMapper $followers,
		private readonly IUserSession $session,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * Follow the schema's breaking changes; 201 when new, 200 when already following.
	 *
	 * @param int $id The schema id.
	 *
	 * @return JSONResponse The follow state, or 404.
	 *
	 * @spec openspec/changes/schema-breaking-change-notice/specs/schema-migration/spec.md#requirement-a-reader-may-follow-a-schemas-breaking-changes
	 */
	#[NoAdminRequired]
	public function follow(int $id): JSONResponse {
		$uid = $this->readerOf(id: $id);
		if ($uid === null) {
			return $this->notFound();
		}

		$isNew = $this->followers->follow(schemaId: $id, uid: $uid);
		$status = 200;
		if ($isNew === true) {
			$status = 201;
		}

		return new JSONResponse(data: ['schemaId' => $id, 'following' => true], statusCode: $status);
	}//end follow()

	/**
	 * Stop following the schema's breaking changes.
	 *
	 * @param int $id The schema id.
	 *
	 * @return JSONResponse The follow state, or 404.
	 *
	 * @spec openspec/changes/schema-breaking-change-notice/specs/schema-migration/spec.md#requirement-a-reader-may-follow-a-schemas-breaking-changes
	 */
	#[NoAdminRequired]
	public function unfollow(int $id): JSONResponse {
		$uid = $this->readerOf(id: $id);
		if ($uid === null) {
			return $this->notFound();
		}

		$this->followers->unfollow(schemaId: $id, uid: $uid);

		return new JSONResponse(data: ['schemaId' => $id, 'following' => false]);
	}//end unfollow()

	/**
	 * The signed-in user when they may read the schema, else null.
	 *
	 * @param int $id The schema id.
	 *
	 * @return string|null The user id.
	 */
	private function readerOf(int $id): ?string {
		$user = $this->session->getUser();
		if ($user === null) {
			return null;
		}

		try {
			$this->schemas->find(id: $id);
		} catch (DoesNotExistException $e) {
			return null;
		} catch (Throwable $e) {
			// A failing read check refuses; it never lets a follow through.
			return null;
		}

		return $user->getUID();
	}//end readerOf()

	/**
	 * The answer for a schema the user cannot read or that does not exist.
	 *
	 * @return JSONResponse 404.
	 */
	private function notFound(): JSONResponse {
		return new JSONResponse(data: ['error' => 'Schema not found'], statusCode: 404);
	}//end notFound()
}//end class
