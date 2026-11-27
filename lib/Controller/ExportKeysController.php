<?php

/**
 * ExportKeysController — administrators list, create, rotate and delete export keys.
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
 * @link https://conduction.nl
 *
 * @spec openspec/changes/exchange-encrypted-instance-export/specs/data-import-export/spec.md#requirement-export-keys-are-kept-in-the-credential-broker
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use OCA\OpenRegister\Kernel\Contracts\ActiveOrganisationReaderInterface;
use OCA\OpenRegister\Service\Exchange\ExportKeyCreated;
use OCA\OpenRegister\Service\Exchange\ExportKeyDirectory;
use OCA\OpenRegister\Service\Exchange\ExportKeyException;
use OCA\OpenRegister\Service\Exchange\ExportKeyService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The "Export keys" section of the Open Register admin settings (task 4.1).
 *
 * Every route is admin-only: the routes carry no `NoAdminRequired`, and the
 * body checks the administrator again so nothing is read or written for
 * anyone else. The organisation is the caller's active organisation. A key
 * is rotated or deleted only when it is in that organisation's own list, so
 * another organisation's key answers the same 404 as a key that does not
 * exist; the broker's organisation guard still runs on every key read behind
 * {@see ExportKeyService}. The recovery string is in the answer to create and
 * rotate only, with `Cache-Control: no-store`, and never in the list.
 *
 * @spec openspec/changes/exchange-encrypted-instance-export/specs/data-import-export/spec.md#requirement-export-keys-are-kept-in-the-credential-broker
 */
class ExportKeysController extends Controller {

	/**
	 * The 403 message for a caller who is not an administrator.
	 *
	 * @var string
	 */
	private const ADMIN_ONLY = 'Only administrators may manage export keys.';

	/**
	 * The 409 message when the caller has no active organisation.
	 *
	 * @var string
	 */
	private const NO_ORGANISATION = 'Choose an active organisation first: export keys belong to an organisation.';

	/**
	 * Constructor.
	 *
	 * @param string                            $appName      The app name.
	 * @param IRequest                          $request      The request.
	 * @param IUserSession                      $userSession  The administrator.
	 * @param IGroupManager                     $groups       Tells an administrator from anyone else.
	 * @param ActiveOrganisationReaderInterface $organisation The caller's active organisation.
	 * @param ExportKeyService                  $exportKeys   Creates, rotates and deletes keys.
	 * @param ExportKeyDirectory                $directory    Lists keys without reading them.
	 * @param LoggerInterface                   $logger       Records an unexpected failure, never a key.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groups,
		private readonly ActiveOrganisationReaderInterface $organisation,
		private readonly ExportKeyService $exportKeys,
		private readonly ExportKeyDirectory $directory,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * The active organisation's export keys, by fingerprint and creation date.
	 *
	 * @return JSONResponse `{results: [{id, fingerprint, createdAt}], organisation}`
	 *
	 * @spec openspec/changes/exchange-encrypted-instance-export/specs/data-import-export/spec.md#requirement-export-keys-are-kept-in-the-credential-broker
	 */
	public function index(): JSONResponse {
		return $this->guarded(
			action: fn (string $uid, string $organisation): JSONResponse => new JSONResponse(
				['results' => $this->directory->list(organisation: $organisation), 'organisation' => $organisation]
			)
		);
	}//end index()

	/**
	 * Create an export key; the answer carries its recovery string, once.
	 *
	 * @return JSONResponse 201 `{id, fingerprint, recovery}`
	 *
	 * @spec openspec/changes/exchange-encrypted-instance-export/specs/data-import-export/spec.md#requirement-export-keys-are-kept-in-the-credential-broker
	 */
	public function create(): JSONResponse {
		return $this->guarded(
			action: fn (string $uid, string $organisation): JSONResponse => $this->shownOnce(
				created: $this->exportKeys->create(organisation: $organisation, owner: $uid),
				previousId: null
			)
		);
	}//end create()

	/**
	 * Rotate an export key: a new key for new sets, the old one kept for old sets.
	 *
	 * @param string $id The key being rotated out.
	 *
	 * @return JSONResponse 201 `{id, fingerprint, recovery, previousId}`
	 *
	 * @spec openspec/changes/exchange-encrypted-instance-export/specs/data-import-export/spec.md#requirement-export-keys-are-kept-in-the-credential-broker
	 */
	public function rotate(string $id): JSONResponse {
		return $this->guarded(
			action: fn (string $uid, string $organisation): JSONResponse => $this->shownOnce(
				created: $this->exportKeys->rotate(previousKeyId: $id, organisation: $organisation, owner: $uid),
				previousId: $id
			),
			keyId: $id
		);
	}//end rotate()

	/**
	 * Delete an export key; a set encrypted with it can no longer be opened here.
	 *
	 * @param string $id The key's credential UUID.
	 *
	 * @return JSONResponse `{id, removed}`, or 500 when the key could only be partly removed.
	 *
	 * @spec openspec/changes/exchange-encrypted-instance-export/specs/data-import-export/spec.md#requirement-export-keys-are-kept-in-the-credential-broker
	 */
	public function destroy(string $id): JSONResponse {
		return $this->guarded(
			action: function (string $uid, string $organisation) use ($id): JSONResponse {
				$removed = $this->exportKeys->delete(keyId: $id, organisation: $organisation, owner: $uid);
				$status  = Http::STATUS_OK;
				if ($removed === false) {
					$status = Http::STATUS_INTERNAL_SERVER_ERROR;
				}

				return new JSONResponse(['id' => $id, 'removed' => $removed], $status);
			},
			keyId: $id
		);
	}//end destroy()

	/**
	 * Run an action for an administrator with an active organisation, mapping refusals to status codes.
	 *
	 * @param callable(string, string): JSONResponse $action Receives the user id and the organisation UUID.
	 * @param string|null                            $keyId  The key the action works on, which must be the organisation's own.
	 *
	 * @return JSONResponse The action's answer, or 401/403/404/409/500.
	 */
	private function guarded(callable $action, ?string $keyId=null): JSONResponse {
		$uid = $this->userSession->getUser()?->getUID();
		if ($uid === null) {
			return new JSONResponse(['message' => 'Unauthorized'], Http::STATUS_UNAUTHORIZED);
		}

		if ($this->groups->isAdmin($uid) !== true) {
			return new JSONResponse(['message' => self::ADMIN_ONLY], Http::STATUS_FORBIDDEN);
		}

		$organisation = (string) ($this->organisation->activeOrganisationUuid() ?? '');
		if ($organisation === '') {
			return new JSONResponse(['message' => self::NO_ORGANISATION], Http::STATUS_CONFLICT);
		}

		try {
			if ($keyId !== null && $this->isOwnKey(keyId: $keyId, organisation: $organisation) === false) {
				return $this->noSuchKey();
			}

			return $action($uid, $organisation);
		} catch (ExportKeyException $e) {
			return $this->noSuchKey();
		} catch (Throwable $e) {
			$this->logger->error('[ExportKeysController] an export key action failed', ['reason' => $e::class]);

			return new JSONResponse(['message' => 'The export key action failed.'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}//end guarded()

	/**
	 * Whether a key is in the organisation's own list of export keys.
	 *
	 * @param string $keyId        The key's credential UUID.
	 * @param string $organisation The organisation UUID.
	 *
	 * @return bool True when the organisation holds the key.
	 */
	private function isOwnKey(string $keyId, string $organisation): bool {
		return in_array($keyId, array_column($this->directory->list(organisation: $organisation), 'id'), true);
	}//end isOwnKey()

	/**
	 * The answer for a key that does not exist or is not the organisation's.
	 *
	 * @return JSONResponse 404.
	 */
	private function noSuchKey(): JSONResponse {
		return new JSONResponse(['message' => 'No such export key.'], Http::STATUS_NOT_FOUND);
	}//end noSuchKey()

	/**
	 * The answer that shows a new key's recovery string, and must not be cached.
	 *
	 * @param ExportKeyCreated $created    The new key.
	 * @param string|null      $previousId The rotated-out key, for a rotation.
	 *
	 * @return JSONResponse 201 with the recovery string.
	 */
	private function shownOnce(ExportKeyCreated $created, ?string $previousId): JSONResponse {
		$body = ['id' => $created->keyId, 'fingerprint' => $created->fingerprint, 'recovery' => $created->recovery];
		if ($previousId !== null) {
			$body['previousId'] = $previousId;
		}

		$response = new JSONResponse($body, Http::STATUS_CREATED);
		$response->addHeader('Cache-Control', 'no-store');

		return $response;
	}//end shownOnce()
}//end class
