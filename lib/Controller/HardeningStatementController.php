<?php

/**
 * The statement of applicability: publishing it, and accepting it.
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
 * @spec openspec/changes/instance-hardening-controls/specs/instance-hardening/spec.md#requirement-a-published-statement-is-accepted-before-use-per-version-req-ihc-001
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use InvalidArgumentException;
use OCA\OpenRegister\Service\Hardening\ElevationRequiredException;
use OCA\OpenRegister\Service\Hardening\ElevationService;
use OCA\OpenRegister\Service\Hardening\StatementService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Reads, accepts, publishes and withdraws the statement of applicability.
 *
 * 🔴 TWO AUDIENCES ON ONE SURFACE, which is why it is its own controller.
 * `statement()` and `acceptStatement()` are the only hardening endpoints an
 * ORDINARY account may reach, and they carry `#[NoAdminRequired]` for that
 * reason; `publishStatement()` and `withdrawStatement()` do not, and are
 * additionally behind a fresh sign-in. Keeping the two admin-only endpoints
 * beside the two account-facing ones, and nothing else, makes the pairing
 * readable in one screen instead of buried among the report endpoints where
 * every method is administrator-only.
 *
 * In both account-facing endpoints the uid comes from the SESSION. There is
 * no id to tamper with, so nobody can read or record somebody else's
 * acceptance.
 *
 * @psalm-suppress UnusedClass Registered through appinfo/routes.php.
 *
 * @spec openspec/changes/instance-hardening-controls/specs/instance-hardening/spec.md#requirement-a-published-statement-is-accepted-before-use-per-version-req-ihc-001
 */
class HardeningStatementController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param string           $appName     The app name.
	 * @param IRequest         $request     The incoming request.
	 * @param StatementService $statements  Publishes the statement, and records an acceptance.
	 * @param ElevationService $elevation   Guards the administration writes with a fresh sign-in.
	 * @param IUserSession     $userSession Names the account, which is never read from the request.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly StatementService $statements,
		private readonly ElevationService $elevation,
		private readonly IUserSession $userSession,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * The statement in force, and whether this account still has to accept it.
	 *
	 * The one read here an ordinary account may make, because it is the one
	 * thing it is asked to do. It answers about the CALLER and nobody else: the
	 * account comes from the session, so there is no id to tamper with and no
	 * other person's acceptance to read.
	 *
	 * @return JSONResponse The statement, or an empty answer when none is published.
	 *
	 * @psalm-return JSONResponse<200|401, array<string, mixed>, array<never, never>>
	 *
	 * @spec openspec/changes/instance-hardening-controls/specs/instance-hardening/spec.md#requirement-a-published-statement-is-accepted-before-use-per-version-req-ihc-001
	 *
	 * @contract tests/Unit/Controller/HardeningControllerTest.php
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function statement(): JSONResponse {
		$uid = ($this->userSession->getUser()?->getUID() ?? '');
		if ($uid === '') {
			return new JSONResponse(
				data: ['error' => 'A statement is shown to an account.'],
				statusCode: Http::STATUS_UNAUTHORIZED
			);
		}

		return new JSONResponse(
			data: [
				'statement' => $this->statements->published(),
				'acceptance' => $this->statements->acceptanceOf(userId: $uid),
				'needsAcceptance' => $this->statements->needsAcceptance(userId: $uid),
			]
		);

	}//end statement()

	/**
	 * Record that the signed-in account accepted the version in force.
	 *
	 * @return JSONResponse The acceptance as recorded, or the refusal.
	 *
	 * @psalm-return JSONResponse<200|400|401, array<string, mixed>, array<never, never>>
	 *
	 * @spec openspec/changes/instance-hardening-controls/specs/instance-hardening/spec.md#requirement-a-published-statement-is-accepted-before-use-per-version-req-ihc-001
	 *
	 * @contract tests/Unit/Controller/HardeningControllerTest.php
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function acceptStatement(): JSONResponse {
		$uid = ($this->userSession->getUser()?->getUID() ?? '');
		if ($uid === '') {
			return new JSONResponse(
				data: ['error' => 'An acceptance is recorded against an account.'],
				statusCode: Http::STATUS_UNAUTHORIZED
			);
		}

		try {
			// The account is the session's and the version is checked against
			// the one in force, so a client cannot accept on behalf of somebody
			// else, nor close the gate on a text nobody was shown.
			return new JSONResponse(
				data: $this->statements->accept(
					userId: $uid,
					version: (string)($this->request->getParam('version') ?? '')
				)
			);
		} catch (InvalidArgumentException $invalid) {
			return new JSONResponse(data: ['error' => $invalid->getMessage()], statusCode: Http::STATUS_BAD_REQUEST);
		}

	}//end acceptStatement()

	/**
	 * Publish a statement, or a new version of one.
	 *
	 * @return JSONResponse The statement now in force, or the refusal.
	 *
	 * @psalm-return JSONResponse<200|400|403, array<string, mixed>, array<never, never>>
	 *
	 * @spec openspec/changes/instance-hardening-controls/specs/instance-hardening/spec.md#requirement-a-published-statement-is-accepted-before-use-per-version-req-ihc-001
	 *
	 * @contract tests/Unit/Controller/HardeningControllerTest.php
	 */
	#[NoCSRFRequired]
	public function publishStatement(): JSONResponse {
		try {
			$this->elevation->requireElevated();

			return new JSONResponse(
				data: $this->statements->publish(
					version: (string)($this->request->getParam('version') ?? ''),
					body: (string)($this->request->getParam('body') ?? ''),
					title: (string)($this->request->getParam('title') ?? ''),
					userId: ($this->userSession->getUser()?->getUID() ?? '')
				)
			);
		} catch (ElevationRequiredException $stale) {
			return new JSONResponse(data: $stale->toArray(), statusCode: Http::STATUS_FORBIDDEN);
		} catch (InvalidArgumentException $invalid) {
			return new JSONResponse(data: ['error' => $invalid->getMessage()], statusCode: Http::STATUS_BAD_REQUEST);
		}

	}//end publishStatement()

	/**
	 * Withdraw the statement, so nothing is asked.
	 *
	 * @return JSONResponse The empty statement, or the refusal.
	 *
	 * @psalm-return JSONResponse<200|403, array<string, mixed>, array<never, never>>
	 *
	 * @spec openspec/changes/instance-hardening-controls/specs/instance-hardening/spec.md#requirement-a-published-statement-is-accepted-before-use-per-version-req-ihc-001
	 *
	 * @contract tests/Unit/Controller/HardeningControllerTest.php
	 */
	#[NoCSRFRequired]
	public function withdrawStatement(): JSONResponse {
		try {
			$this->elevation->requireElevated();
			$this->statements->withdraw();

			return new JSONResponse(data: ['statement' => null]);
		} catch (ElevationRequiredException $stale) {
			return new JSONResponse(data: $stale->toArray(), statusCode: Http::STATUS_FORBIDDEN);
		}

	}//end withdrawStatement()
}//end class
