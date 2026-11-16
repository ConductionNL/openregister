<?php

/**
 * The grant on a signed-in user's own personal API tokens.
 *
 * A personal API token is a Nextcloud app password; this controller lets its
 * owner put a grant on it, read it back and take it off. Only the caller's own
 * tokens are addressed: the grant is stored in the caller's namespace and is
 * only ever read for a token from its owner's, so an id that is not yours
 * narrows nothing.
 *
 * 🔴 ONLY AN INTERACTIVE SIGN-IN MANAGES A GRANT. Replacing or removing a
 * grant can widen a token, so a request opened with a token (an app password,
 * or a Consumer's credential) is refused with 403: a narrowed token must not be
 * able to lift its own ceiling.
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
 * @spec openspec/changes/scoped-api-tokens/specs/auth-system/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use DateTimeImmutable;
use OCA\OpenRegister\AppInfo\Application;
use OCA\OpenRegister\Controller\Support\PersonalTokenGrantsServices;
use OCA\OpenRegister\Service\Rbac\PersonalTokenGrantStore;
use OCA\OpenRegister\Service\Rbac\TokenGrant;
use OCA\OpenRegister\Service\Rbac\TokenGrantIssue;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * GET, PUT and DELETE /api/tokens/{tokenId}/grant.
 *
 * @spec openspec/changes/scoped-api-tokens/specs/auth-system/spec.md
 */
class PersonalTokenGrantsController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest                    $request     The request.
	 * @param IUserSession                $userSession The calling user.
	 * @param PersonalTokenGrantsServices $services    Store, binder, grant source, validator and catalogue.
	 */
	public function __construct(
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly PersonalTokenGrantsServices $services,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The grant on one of the caller's tokens.
	 *
	 * @param string $tokenId The token id.
	 *
	 * @return JSONResponse `{tokenId, actorVia, grant, issuedBy, issuedAt, renewals}`; 404 when the token has none.
	 *
	 * @spec openspec/changes/scoped-api-tokens/specs/auth-system/spec.md
	 */
	#[NoAdminRequired]
	public function show(string $tokenId): JSONResponse {
		$uid = $this->uid();
		if ($uid === null) {
			return self::refusal(status: Http::STATUS_UNAUTHORIZED, message: 'not-logged-in');
		}

		$record = $this->services->store->read(uid: $uid, tokenId: $tokenId);
		if ($record === null) {
			return self::refusal(status: Http::STATUS_NOT_FOUND, message: 'This token carries no grant');
		}

		return new JSONResponse(data: self::describe(tokenId: $tokenId, record: $record));
	}//end show()

	/**
	 * Put a grant on one of the caller's tokens, or replace it.
	 *
	 * A changed end date is recorded as a renewal, with the actor and the new
	 * end date. A grant that cannot be issued is refused with 422 naming why.
	 *
	 * @param string $tokenId The token id.
	 *
	 * @return JSONResponse The stored record; 401, 403 or 422 when refused.
	 *
	 * @spec openspec/changes/scoped-api-tokens/specs/auth-system/spec.md#requirement-a-token-carries-a-required-end-date-and-is-warned-before-it-lapses-req-sat-003
	 */
	#[NoAdminRequired]
	public function update(string $tokenId): JSONResponse {
		$uid = $this->uid();
		if ($uid === null) {
			return self::refusal(status: Http::STATUS_UNAUTHORIZED, message: 'not-logged-in');
		}

		if ($this->isTokenCaller() === true) {
			return self::refusal(status: Http::STATUS_FORBIDDEN, message: 'A token grant can only be changed from an interactive sign-in');
		}

		$grant = $this->request->getParam(TokenGrant::KEY);
		if (is_array($grant) === false) {
			return self::refusal(status: Http::STATUS_UNPROCESSABLE_ENTITY, message: 'a grant must be an object');
		}

		$now     = new DateTimeImmutable();
		$refusal = $this->services->validator->refusalFor(
			issue: new TokenGrantIssue(grant: $grant, issuerVerbs: $this->services->catalogue->verbs(), now: $now)
		);
		if ($refusal !== null) {
			return self::refusal(status: Http::STATUS_UNPROCESSABLE_ENTITY, message: $refusal);
		}

		$record = $this->services->store->write(uid: $uid, tokenId: $tokenId, grant: $grant, actor: $uid, now: $now);

		return new JSONResponse(data: self::describe(tokenId: $tokenId, record: $record));
	}//end update()

	/**
	 * Take the grant off one of the caller's tokens.
	 *
	 * @param string $tokenId The token id.
	 *
	 * @return JSONResponse `{tokenId, grant: null}`; 401 or 403 when refused.
	 *
	 * @spec openspec/changes/scoped-api-tokens/specs/auth-system/spec.md
	 */
	#[NoAdminRequired]
	public function destroy(string $tokenId): JSONResponse {
		$uid = $this->uid();
		if ($uid === null) {
			return self::refusal(status: Http::STATUS_UNAUTHORIZED, message: 'not-logged-in');
		}

		if ($this->isTokenCaller() === true) {
			return self::refusal(status: Http::STATUS_FORBIDDEN, message: 'A token grant can only be changed from an interactive sign-in');
		}

		$this->services->store->remove(uid: $uid, tokenId: $tokenId);

		return new JSONResponse(data: ['tokenId' => $tokenId, 'grant' => null]);
	}//end destroy()

	/**
	 * The calling user's uid.
	 *
	 * @return string|null The uid, or null without a signed-in user.
	 */
	private function uid(): ?string {
		return $this->userSession->getUser()?->getUID();
	}//end uid()

	/**
	 * Whether this request was opened with a token rather than an interactive sign-in.
	 *
	 * @return bool True for an app password or a bound Consumer.
	 */
	private function isTokenCaller(): bool {
		return $this->services->binder->isTokenRequest() === true || $this->services->source->isBound() === true;
	}//end isTokenCaller()

	/**
	 * The answer for a stored record.
	 *
	 * @param string               $tokenId The token id.
	 * @param array<string, mixed> $record  The stored record.
	 *
	 * @return array<string, mixed> The answer.
	 */
	private static function describe(string $tokenId, array $record): array {
		$grant = $record['grant'] ?? null;
		if (is_array($grant) === false) {
			$grant = null;
		}

		return [
			'tokenId'  => $tokenId,
			'actorVia' => PersonalTokenGrantStore::actorId(tokenId: $tokenId),
			'grant'    => $grant,
			'readable' => ($grant !== null),
			'issuedBy' => ($record['issuedBy'] ?? null),
			'issuedAt' => ($record['issuedAt'] ?? null),
			'renewals' => ($record['renewals'] ?? []),
		];
	}//end describe()

	/**
	 * A refusal naming its reason.
	 *
	 * @param int    $status  The HTTP status.
	 * @param string $message The reason.
	 *
	 * @return JSONResponse The refusal.
	 */
	private static function refusal(int $status, string $message): JSONResponse {
		return new JSONResponse(data: ['error' => $message], statusCode: $status);
	}//end refusal()
}//end class
