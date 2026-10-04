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

namespace OCA\OpenRegister\Service\File;

use OCA\OpenRegister\Exception\OfficeOpenRefusedException;
use OCP\App\IAppManager;
use OCP\Files\File;
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
	 * @param IAppManager        $appManager   Tells whether Office is enabled.
	 * @param ContainerInterface $container    Resolves the richdocuments services lazily.
	 * @param IURLGenerator      $urlGenerator Builds the server host and the WOPI source.
	 * @param IConfig            $config       Reads the instance id.
	 * @param IAppConfig         $appConfig    Reads richdocuments' callback address.
	 */
	public function __construct(
		private readonly IAppManager $appManager,
		private readonly ContainerInterface $container,
		private readonly IURLGenerator $urlGenerator,
		private readonly IConfig $config,
		private readonly IAppConfig $appConfig,
	) {
	}//end __construct()

	/**
	 * Issue an Office session for one file.
	 *
	 * @param File   $file        The object's file, already known to belong to a readable object.
	 * @param string $editorUid   The acting person.
	 * @param string $displayName Their display name, shown in Office.
	 * @param bool   $canWrite    Whether the object rule lets them change the file.
	 *
	 * @return OfficeSession The session the browser posts into the Office frame.
	 *
	 * @throws OfficeOpenRefusedException 409, 415 or 403 when the document cannot be opened.
	 *
	 * @spec openspec/changes/object-files-follow-object-access/specs/file-actions/spec.md#requirement-nextcloud-office-opens-an-objects-document-by-the-object-rule-req-ofoa-006
	 */
	public function open(File $file, string $editorUid, string $displayName, bool $canWrite): OfficeSession {
		$services = $this->richdocuments();

		$urlSrc = $services['tokenManager']->getUrlSrcForMimeType($file->getMimeType());
		if (is_string($urlSrc) === false || $urlSrc === '') {
			throw new OfficeOpenRefusedException(
				httpStatus: OfficeOpenRefusedException::UNSUPPORTED_TYPE,
				message: 'This file type does not open in Nextcloud Office.'
			);
		}

		$permissions = $services['permissionManager'];
		if ($permissions->isEnabledForUser($editorUid) !== true) {
			throw new OfficeOpenRefusedException(
				httpStatus: OfficeOpenRefusedException::NOT_PERMITTED,
				message: 'You may not use Nextcloud Office on this instance.'
			);
		}

		$writable = ($canWrite === true && $permissions->userCanEdit($editorUid) === true);
		$fileId = (string)$file->getId();

		$guestName = $displayName;
		if ($guestName === '') {
			$guestName = $editorUid;
		}

		try {
			$wopi = $services['wopiMapper']->generateFileToken(
				$fileId,
				self::OWNER,
				$editorUid,
				'0',
				$writable,
				$this->urlGenerator->getAbsoluteURL('/'),
				$guestName
			);
		} catch (Throwable $e) {
			throw new OfficeOpenRefusedException(
				httpStatus: OfficeOpenRefusedException::UNAVAILABLE,
				message: 'Nextcloud Office could not open this document.',
				previous: $e
			);
		}

		return new OfficeSession(
			urlSrc: $urlSrc,
			wopiSrc: $this->wopiSrc(fileId: $fileId),
			token: (string)$wopi->getToken(),
			tokenTtl: ((int)$wopi->getExpiry() * 1000),
			readOnly: ($writable === false)
		);
	}//end open()

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
		$base = rtrim($this->appConfig->getValueString(self::OFFICE_APP, 'wopi_callback_url', ''), '/');
		if ($base === '') {
			$base = rtrim($this->urlGenerator->getAbsoluteURL('/'), '/');
		}

		$instanceId = $this->config->getSystemValueString('instanceid', '');

		return $base . '/index.php/apps/richdocuments/wopi/files/' . $fileId . '_' . $instanceId;
	}//end wopiSrc()
}//end class
