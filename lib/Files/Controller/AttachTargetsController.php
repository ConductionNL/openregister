<?php

/**
 * The attach picker's search: where the caller may save a file from Files or Talk.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Files\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/files-leaf-save-to-object/specs/file-actions/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Files\Controller;

use OCA\OpenRegister\AppInfo\Application;
use OCA\OpenRegister\Files\Service\Integration\AttachTargetSearch;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Answers the "Add to object" and "Save chat to object" pickers.
 *
 * @spec openspec/changes/files-leaf-save-to-object/specs/file-actions/spec.md
 */
class AttachTargetsController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest           $request The request.
	 * @param AttachTargetSearch $search  The picker's search.
	 * @param LoggerInterface    $logger  The logger.
	 *
	 * @spec openspec/changes/files-leaf-save-to-object/specs/file-actions/spec.md
	 */
	public function __construct(
		IRequest $request,
		private readonly AttachTargetSearch $search,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The schemas the caller may save into, and the objects whose title matches `search`.
	 *
	 * `targets` (comma-separated schema slugs, or a list) is the consuming
	 * app's `fileActions.attachTargets`: it narrows the offer and never widens it.
	 *
	 * @return JSONResponse `{targets, results}`.
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/files-leaf-save-to-object/specs/file-actions/spec.md#requirement-a-files-action-attaches-a-file-to-a-register-object
	 */
	#[NoAdminRequired]
	public function index(): JSONResponse {
		try {
			return new JSONResponse(
				data: $this->search->find(
					search: (string)$this->request->getParam('search', ''),
					declared: self::declared(raw: $this->request->getParam('targets'))
				)
			);
		} catch (Throwable $e) {
			$this->logger->warning('[AttachTargetsController] The attach picker could not be answered', ['error' => $e->getMessage()]);
			return new JSONResponse(data: ['error' => 'The places to save to could not be loaded.'], statusCode: 500);
		}
	}//end index()

	/**
	 * The declared targets, or null when the request pinned none.
	 *
	 * @param mixed $raw The `targets` parameter.
	 *
	 * @return array<int,string>|null
	 *
	 * @spec openspec/changes/files-leaf-save-to-object/specs/file-actions/spec.md
	 */
	public static function declared(mixed $raw): ?array {
		if (is_string($raw) === true) {
			$raw = explode(',', $raw);
		}

		if (is_array($raw) === false) {
			return null;
		}

		$declared = [];
		foreach ($raw as $slug) {
			if (is_scalar($slug) === false) {
				continue;
			}

			$slug = trim((string)$slug);
			if ($slug !== '') {
				$declared[] = $slug;
			}
		}

		if ($declared === []) {
			return null;
		}

		return $declared;
	}//end declared()
}//end class
