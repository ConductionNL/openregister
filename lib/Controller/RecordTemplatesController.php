<?php

/**
 * OpenRegister RecordTemplatesController
 *
 * `/api/record-templates`: a user's named values for starting a new record,
 * shared like a saved view.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/records-saved-templates/specs/record-templates/spec.md#requirement-a-user-can-save-and-share-a-named-record-template
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use OCA\OpenRegister\Exception\RecordTemplateRefused;
use OCA\OpenRegister\Service\RecordTemplate\RecordTemplateService;
use OCA\OpenRegister\Service\Rbac\ViewerReach;
use OCA\OpenRegister\Service\Rbac\ViewerReachResolver;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Index, show, create, update, patch and destroy for record templates.
 *
 * Every per-template decision (may use, may change) is RecordTemplateService's;
 * this controller only names the caller and maps a refusal to its status.
 *
 * @psalm-suppress UnusedClass Reached through appinfo/routes.php.
 *
 * @spec openspec/changes/records-saved-templates/specs/record-templates/spec.md#requirement-a-user-can-save-and-share-a-named-record-template
 */
class RecordTemplatesController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param string                $appName   The app name.
	 * @param IRequest              $request   The request.
	 * @param RecordTemplateService $templates Decides and stores.
	 * @param ViewerReachResolver   $viewers   Names the caller and their groups.
	 * @param LoggerInterface       $logger    Logger.
	 *
	 * @spec openspec/changes/records-saved-templates/specs/record-templates/spec.md#requirement-a-user-can-save-and-share-a-named-record-template
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly RecordTemplateService $templates,
		private readonly ViewerReachResolver $viewers,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * The templates the caller may use, optionally for one register and schema.
	 *
	 * @return JSONResponse `{results, total}`, 401 without a session.
	 *
	 * @spec openspec/changes/records-saved-templates/specs/record-templates/spec.md#requirement-a-user-can-save-and-share-a-named-record-template
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function index(): JSONResponse {
		return $this->answer(
			action: fn (ViewerReach $reach): array => $this->listed(
				templates: $this->templates->listFor(
					reach: $reach,
					register: $this->filter(name: 'register'),
					schema: $this->filter(name: 'schema')
				)
			)
		);
	}//end index()

	/**
	 * One template the caller may use.
	 *
	 * @param string $id The template uuid.
	 *
	 * @return JSONResponse The template, 404 when absent or not usable.
	 *
	 * @spec openspec/changes/records-saved-templates/specs/record-templates/spec.md#requirement-a-user-can-save-and-share-a-named-record-template
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function show(string $id): JSONResponse {
		return $this->answer(action: fn (ViewerReach $reach): array => $this->templates->get(uuid: $id, reach: $reach)->jsonSerialize());
	}//end show()

	/**
	 * Store a template owned by the caller.
	 *
	 * @return JSONResponse 201 with the template; 400, 403 as refused.
	 *
	 * @spec openspec/changes/records-saved-templates/specs/record-templates/spec.md#requirement-a-user-can-save-and-share-a-named-record-template
	 */
	#[NoAdminRequired]
	public function create(): JSONResponse {
		return $this->answer(
			action: fn (ViewerReach $reach): array => $this->templates->create(data: $this->request->getParams(), reach: $reach)->jsonSerialize(),
			success: 201
		);
	}//end create()

	/**
	 * Replace a template the caller owns (administrators: any).
	 *
	 * @param string $id The template uuid.
	 *
	 * @return JSONResponse The template; 400, 403, 404 as refused.
	 *
	 * @spec openspec/changes/records-saved-templates/specs/record-templates/spec.md#requirement-a-user-can-save-and-share-a-named-record-template
	 */
	#[NoAdminRequired]
	public function update(string $id): JSONResponse {
		return $this->answer(
			action: fn (ViewerReach $reach): array => $this->templates->change(
				uuid: $id,
				data: $this->request->getParams(),
				reach: $reach,
				partial: false
			)->jsonSerialize()
		);
	}//end update()

	/**
	 * Change the fields sent on a template the caller owns (administrators: any).
	 *
	 * @param string $id The template uuid.
	 *
	 * @return JSONResponse The template; 400, 403, 404 as refused.
	 *
	 * @spec openspec/changes/records-saved-templates/specs/record-templates/spec.md#requirement-a-user-can-save-and-share-a-named-record-template
	 */
	#[NoAdminRequired]
	public function patch(string $id): JSONResponse {
		return $this->answer(
			action: fn (ViewerReach $reach): array => $this->templates->change(
				uuid: $id,
				data: $this->request->getParams(),
				reach: $reach,
				partial: true
			)->jsonSerialize()
		);
	}//end patch()

	/**
	 * Delete a template the caller owns (administrators: any).
	 *
	 * @param string $id The template uuid.
	 *
	 * @return JSONResponse 204; 403, 404 as refused.
	 *
	 * @spec openspec/changes/records-saved-templates/specs/record-templates/spec.md#requirement-a-user-can-save-and-share-a-named-record-template
	 */
	#[NoAdminRequired]
	public function destroy(string $id): JSONResponse {
		return $this->answer(
			action: function (ViewerReach $reach) use ($id): array {
				$this->templates->delete(uuid: $id, reach: $reach);
				return [];
			},
			success: 204
		);
	}//end destroy()

	/**
	 * Run an action for the signed-in caller and map its outcome to a response.
	 *
	 * @param callable(ViewerReach): array<string, mixed> $action  The action.
	 * @param int                                         $success The status on success.
	 *
	 * @return JSONResponse The response.
	 */
	private function answer(callable $action, int $success=200): JSONResponse {
		$userId = $this->viewers->currentUid();
		if ($userId === '') {
			return new JSONResponse(data: ['error' => 'User not authenticated'], statusCode: 401);
		}

		try {
			return new JSONResponse(data: $action($this->viewers->reachOf(userId: $userId)), statusCode: $success);
		} catch (RecordTemplateRefused $e) {
			$body = ['error' => $e->getMessage()];
			if ($e->getFindings() !== []) {
				$body['findings'] = $e->getFindings();
			}

			return new JSONResponse(data: $body, statusCode: $e->getCode());
		} catch (Throwable $e) {
			$this->logger->error('[RecordTemplatesController] Record template request failed', ['exception' => $e->getMessage()]);

			return new JSONResponse(data: ['error' => 'The record template request failed.'], statusCode: 500);
		}
	}//end answer()

	/**
	 * The templates as the index answers them.
	 *
	 * @param array<int, \OCA\OpenRegister\Db\RecordTemplate> $templates The templates.
	 *
	 * @return array{results: array<int, array<string, mixed>>, total: int} The body.
	 */
	private function listed(array $templates): array {
		return [
			'results' => array_map(static fn ($template): array => $template->jsonSerialize(), $templates),
			'total' => count($templates),
		];
	}//end listed()

	/**
	 * One optional query filter.
	 *
	 * @param string $name The parameter name.
	 *
	 * @return string|null The value, or null when absent or empty.
	 */
	private function filter(string $name): ?string {
		$value = trim((string)$this->request->getParam(key: $name, default: ''));
		if ($value === '') {
			return null;
		}

		return $value;
	}//end filter()
}//end class
