<?php

/**
 * Opens an object's document in Nextcloud Office for the acting person.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\File
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
 * @spec openspec/changes/object-files-follow-object-access/specs/file-actions/spec.md#requirement-nextcloud-office-opens-an-objects-document-by-the-object-rule-req-ofoa-006
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Files\Service\File;

use OCA\OpenRegister\Exception\OfficeOpenRefusedException;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IURLGenerator;
use Psr\Container\ContainerInterface;
use Throwable;

/**
 * Issues a Nextcloud Office (richdocuments) session after OpenRegister's own object check.
 *
 * The caller has already applied the object rule; this class only turns the
 * verdict into a WOPI token. Why OpenRegister issues the token itself:
 * richdocuments makes a token for the signed-in user by finding the file in
 * that user's own home (`TokenManager::generateWopiToken()`, richdocuments
 * 12.0.1 `lib/TokenManager.php:69-80`), and an object's file is in the
 * `openregister` account's home, not theirs.
 *
 * The token is made with `WopiMapper::generateFileToken()` with the
 * `openregister` account as owner and the acting person as editor. A display
 * name makes it a guest-type token, and for those richdocuments reads the file
 * through the owner's home (`Wopi::getUserForFileAccess()`, `lib/Db/Wopi.php:158-163`).
 * The editor stays the person: `PutFile` sets the user scope to the editor
 * before it writes (`lib/Controller/WopiController.php:637`), so the new
 * version names them, and refuses a token without `canwrite` (`:631`).
 *
 * The richdocuments classes are not Nextcloud's public API. They are reached
 * through the container only when the app is enabled, and every missing piece
 * refuses with 409 rather than failing open.
 *
 * @spec openspec/changes/object-files-follow-object-access/specs/file-actions/spec.md#requirement-nextcloud-office-opens-an-objects-document-by-the-object-rule-req-ofoa-006
 */
class OfficeSessionService {

	/**
	 * The app id of Nextcloud Office.
	 *
	 * @var string
	 */
	private const OFFICE_APP = 'richdocuments';

	/**
	 * The account that owns every managed file.
	 *
	 * @var string
	 */
	private const OWNER = 'openregister';

	/**
	 * Constructor.
	 *
	 * @param IAppManager           $appManager   Tells whether Office is enabled.
	 * @param ContainerInterface    $container    Resolves the richdocuments services lazily.
	 * @param IURLGenerator         $urlGenerator Builds the server host and the WOPI source.
	 * @param OfficeSessionSettings $settings     The instance config and the app config.
	 */
	public function __construct(
		private readonly IAppManager $appManager,
		private readonly ContainerInterface $container,
		private readonly IURLGenerator $urlGenerator,
		private readonly OfficeSessionSettings $settings,
	) {
	}//end __construct()

	/**
	 * Issue an Office session for one open request.
	 *
	 * Write access needs both the caller's write right and Office's own edit
	 * right; a user Office refuses gets no token at all.
	 *
	 * @param OfficeOpenRequest $request The file, the editor, their display name and the caller's write right.
	 *
	 * @return OfficeSession The session the browser posts into the Office frame.
	 *
	 * @throws OfficeOpenRefusedException 409, 415 or 403 when the document cannot be opened.
	 *
	 * @spec openspec/changes/object-files-follow-object-access/specs/file-actions/spec.md#requirement-nextcloud-office-opens-an-objects-document-by-the-object-rule-req-ofoa-006
	 */
	public function openSession(OfficeOpenRequest $request): OfficeSession {
		$services = $this->richdocuments();

		$urlSrc = $this->officeUrl(services: $services, request: $request);
		$this->assertOfficeForUser(services: $services, request: $request);

		$writable = ($request->canWrite === true && $services['permissionManager']->userCanEdit($request->editorUid) === true);
		$grant = new OfficeTokenGrant(request: $request, writable: $writable);
		$wopi = $this->issueToken(services: $services, grant: $grant);

		return new OfficeSession(
			urlSrc: $urlSrc,
			wopiSrc: $this->wopiSrc(fileId: $grant->fileId()),
			access: new OfficeSessionToken(
				token: (string)$wopi->getToken(),
				tokenTtl: ((int)$wopi->getExpiry() * 1000),
				readOnly: ($writable === false)
			)
		);
	}//end openSession()

	/**
	 * The Office frame URL for the file's MIME type.
	 *
	 * @param array<string, object> $services The richdocuments token manager, permission manager and WOPI mapper.
	 * @param OfficeOpenRequest     $request  The open request.
	 *
	 * @return string
	 *
	 * @throws OfficeOpenRefusedException 415 when the file type does not open in Office.
	 */
	private function officeUrl(array $services, OfficeOpenRequest $request): string {
		$urlSrc = $services['tokenManager']->getUrlSrcForMimeType($request->file->getMimeType());
		if (is_string($urlSrc) === false || $urlSrc === '') {
			throw new OfficeOpenRefusedException(
				httpStatus: OfficeOpenRefusedException::UNSUPPORTED_TYPE,
				message: 'This file type does not open in Nextcloud Office.'
			);
		}

		return $urlSrc;
	}//end officeUrl()

	/**
	 * Refuse an editor Office does not let use it.
	 *
	 * @param array<string, object> $services The richdocuments token manager, permission manager and WOPI mapper.
	 * @param OfficeOpenRequest     $request  The open request.
	 *
	 * @return void
	 *
	 * @throws OfficeOpenRefusedException 403 when Office refuses the editor.
	 */
	private function assertOfficeForUser(array $services, OfficeOpenRequest $request): void {
		if ($services['permissionManager']->isEnabledForUser($request->editorUid) !== true) {
			throw new OfficeOpenRefusedException(
				httpStatus: OfficeOpenRefusedException::NOT_PERMITTED,
				message: 'You may not use Nextcloud Office on this instance.'
			);
		}
	}//end assertOfficeForUser()

	/**
	 * Issue the WOPI token; a failure is a refusal carrying the cause.
	 *
	 * @param array<string, object> $services The richdocuments token manager, permission manager and WOPI mapper.
	 * @param OfficeTokenGrant      $grant    The request and the token's write right.
	 *
	 * @return object The richdocuments Wopi token row.
	 *
	 * @throws OfficeOpenRefusedException 503-style when the token cannot be issued.
	 */
	private function issueToken(array $services, OfficeTokenGrant $grant): object {
		try {
			return $this->generateToken(wopiMapper: $services['wopiMapper'], grant: $grant);
		} catch (Throwable $e) {
			throw new OfficeOpenRefusedException(
				httpStatus: OfficeOpenRefusedException::UNAVAILABLE,
				message: 'Nextcloud Office could not open this document.',
				previous: $e
			);
		}
	}//end issueToken()

	/**
	 * Ask richdocuments for a WOPI token owned by OpenRegister and naming the editor.
	 *
	 * @param object           $wopiMapper The richdocuments WOPI mapper.
	 * @param OfficeTokenGrant $grant      The request and the token's write right.
	 *
	 * @return object The richdocuments Wopi token row.
	 */
	private function generateToken(object $wopiMapper, OfficeTokenGrant $grant): object {
		return $wopiMapper->generateFileToken(
			$grant->fileId(),
			self::OWNER,
			$grant->request->editorUid,
			'0',
			$grant->writable,
			$this->urlGenerator->getAbsoluteURL('/'),
			$grant->guestName()
		);
	}//end generateToken()

	/**
	 * Whether Nextcloud Office is enabled on this instance.
	 *
	 * @return bool True when richdocuments is enabled.
	 *
	 * @spec openspec/changes/object-files-follow-object-access/specs/file-actions/spec.md#requirement-nextcloud-office-opens-an-objects-document-by-the-object-rule-req-ofoa-006
	 */
	public function isAvailable(): bool {
		try {
			return $this->appManager->isEnabledForAnyone(self::OFFICE_APP);
		} catch (Throwable $e) {
			return false;
		}
	}//end isAvailable()

	/**
	 * The richdocuments services this class uses, or a 409 refusal.
	 *
	 * @return array{tokenManager: object, permissionManager: object, wopiMapper: object}
	 *
	 * @throws OfficeOpenRefusedException 409 when Office or one of its services is missing.
	 */
	private function richdocuments(): array {
		$unavailable = new OfficeOpenRefusedException(
			httpStatus: OfficeOpenRefusedException::UNAVAILABLE,
			message: 'Nextcloud Office is not available on this instance.'
		);

		if ($this->isAvailable() === false) {
			throw $unavailable;
		}

		$wanted = [
			'tokenManager' => ['OCA\\Richdocuments\\TokenManager', 'getUrlSrcForMimeType'],
			'permissionManager' => ['OCA\\Richdocuments\\PermissionManager', 'userCanEdit'],
			'wopiMapper' => ['OCA\\Richdocuments\\Db\\WopiMapper', 'generateFileToken'],
		];

		$services = [];
		foreach ($wanted as $key => [$class, $method]) {
			try {
				$service = $this->container->get($class);
			} catch (Throwable $e) {
				throw $unavailable;
			}

			if (is_object($service) === false || method_exists($service, $method) === false) {
				throw $unavailable;
			}

			$services[$key] = $service;
		}

		return $services;
	}//end richdocuments()

	/**
	 * The WOPI source Collabora calls back on, as richdocuments' own viewer builds it.
	 *
	 * @param string $fileId The file id.
	 *
	 * @return string The WOPI source address.
	 */
	private function wopiSrc(string $fileId): string {
		$base = rtrim($this->settings->appConfig->getValueString(self::OFFICE_APP, 'wopi_callback_url', ''), '/');
		if ($base === '') {
			$base = rtrim($this->urlGenerator->getAbsoluteURL('/'), '/');
		}

		$instanceId = $this->settings->config->getSystemValueString('instanceid', '');

		return $base . '/index.php/apps/richdocuments/wopi/files/' . $fileId . '_' . $instanceId;
	}//end wopiSrc()
}//end class
