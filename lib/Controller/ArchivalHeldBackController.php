<?php

/**
 * OpenRegister Archival Held-Back Controller
 *
 * Serves the records the next destruction run would hold back because an
 * ancestor's appraisal outweighs their own, for the destruction list review.
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
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/appraisal-inherited-from-a-parent/specs/archivering-vernietiging/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use OCA\OpenRegister\Service\Archival\DestructionDryRun;
use OCA\OpenRegister\Service\Archival\InheritedAppraisal;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * The held-back read of the destruction list review.
 *
 * @spec openspec/changes/appraisal-inherited-from-a-parent/specs/archivering-vernietiging/spec.md
 */
class ArchivalHeldBackController extends Controller {

	/**
	 * The group that does the archiving work.
	 */
	private const ARCHIVIST_GROUP = 'archivaris';

	/**
	 * Constructor.
	 *
	 * @param string            $appName      The app name.
	 * @param IRequest          $request      The request.
	 * @param DestructionDryRun $dryRun       What the next destruction run would do.
	 * @param IUserSession      $userSession  Who is asking.
	 * @param IGroupManager     $groupManager Whether they may.
	 * @param IL10N             $l10n         Translates the reasons.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly DestructionDryRun $dryRun,
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
		private readonly IL10N $l10n,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * The records held back by an inherited appraisal, each with the ancestor that holds it.
	 *
	 * GET /api/archival/held-back
	 *
	 * @return JSONResponse `results` and `truncated`, or 401/403.
	 *
	 * @spec openspec/changes/appraisal-inherited-from-a-parent/specs/archivering-vernietiging/spec.md#requirement-the-hold-back-is-visible-before-anything-is-destroyed-req-aip-003
	 */
	#[NoAdminRequired]
	public function index(): JSONResponse {
		$refusal = $this->refuseNonArchivist();
		if ($refusal !== null) {
			return $refusal;
		}

		$run  = $this->dryRun->run();
		$rows = [];
		foreach ($run['heldBack'] as $entry) {
			$rows[] = $this->row(entry: $entry);
		}

		return new JSONResponse(
			data: ['results' => $rows, 'truncated' => $run['truncated']],
			statusCode: Http::STATUS_OK
		);
	}//end index()

	/**
	 * One held-back record as the review shows it.
	 *
	 * @param array<string, mixed> $entry A held-back entry of the dry run.
	 *
	 * @return array<string, mixed> The row.
	 *
	 * @spec openspec/changes/appraisal-inherited-from-a-parent/specs/archivering-vernietiging/spec.md#requirement-the-hold-back-is-visible-before-anything-is-destroyed-req-aip-003
	 */
	private function row(array $entry): array {
		$ancestor = ($entry['ancestor'] ?? []);
		$heldBy   = (string) ($ancestor['uuid'] ?? '');
		if (is_string($ancestor['name'] ?? null) === true && $ancestor['name'] !== '') {
			$heldBy = $ancestor['name'];
		}

		$reason = $this->l10n->t('Its ancestor %1$s has appraisal %2$s', [$heldBy, (string) ($ancestor['appraisal'] ?? '')]);
		if (($entry['reason'] ?? '') === InheritedAppraisal::REASON_UNRESOLVED_ANCESTOR
			|| is_string($ancestor['appraisal'] ?? null) === false
		) {
			$reason = $this->l10n->t('Its ancestor %s could not be read', [$heldBy]);
		}

		return [
			'uuid'       => (string) ($entry['uuid'] ?? ''),
			'name'       => (string) ($entry['name'] ?? ''),
			'schema'     => (string) ($entry['schema'] ?? ''),
			'heldBy'     => $heldBy,
			'heldByUuid' => (string) ($ancestor['uuid'] ?? ''),
			'appraisal'  => ($ancestor['appraisal'] ?? null),
			'reason'     => $reason,
		];
	}//end row()

	/**
	 * Refuse anybody who is not an archivist or an admin.
	 *
	 * @return JSONResponse|null A refusal, or null when the caller may proceed.
	 */
	private function refuseNonArchivist(): ?JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(
				data: ['error' => 'Niet geauthenticeerd'],
				statusCode: Http::STATUS_UNAUTHORIZED
			);
		}

		$uid = $user->getUID();
		if ($this->groupManager->isInGroup($uid, self::ARCHIVIST_GROUP) === false
			&& $this->groupManager->isAdmin($uid) === false
		) {
			return new JSONResponse(
				data: ['error' => 'Onvoldoende rechten: archivaris rol is vereist'],
				statusCode: Http::STATUS_FORBIDDEN
			);
		}

		return null;
	}//end refuseNonArchivist()
}//end class
