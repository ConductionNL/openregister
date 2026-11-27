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
use OCA\OpenRegister\Kernel\Contracts\AppIdentity;
use OCA\OpenRegister\Controller\Support\PersonalTokenGrantsServices;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\ObjectRef;
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
 * GET, PUT and DELETE /api/tokens/{tokenId}/grant; GET /api/tokens/grants,
 * POST /api/tokens/grants/resolve and POST /api/tokens/grants/preview for the
 * grant editor on the account page.
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
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
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

		$label  = $this->request->getParam('label');
		$record = $this->services->store->write(
			uid: $uid,
			tokenId: $tokenId,
			grant: $grant,
			actor: $uid,
			now: $now,
			label: self::labelOf(label: $label)
		);

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
	 * The caller's own tokens that carry a grant.
	 *
	 * @return JSONResponse `{results: [{tokenId, label, actorVia, grant, readable, issuedBy, issuedAt, renewals}]}`; 401 without a user.
	 *
	 * @spec openspec/changes/scoped-api-tokens/specs/auth-system/spec.md#requirement-a-token-or-consumer-may-carry-a-grant-narrower-than-its-user
	 */
	#[NoAdminRequired]
	public function index(): JSONResponse {
		$uid = $this->uid();
		if ($uid === null) {
			return self::refusal(status: Http::STATUS_UNAUTHORIZED, message: 'not-logged-in');
		}

		$results = [];
		foreach ($this->services->store->all() as $entry) {
			if ($entry['uid'] !== $uid) {
				continue;
			}

			$record = $this->services->store->read(uid: $uid, tokenId: $entry['tokenId']);
			if ($record !== null) {
				$results[] = self::describe(tokenId: $entry['tokenId'], record: $record);
			}
		}

		return new JSONResponse(data: ['results' => $results]);
	}//end index()

	/**
	 * Name one of the caller's app passwords by the password itself.
	 *
	 * Nextcloud offers apps no list of a user's app passwords, so the editor
	 * asks the owner to paste the password once. It is resolved to the token
	 * id and name and never stored or returned. A password that is not a
	 * token, or is somebody else's, answers the same 404, so the endpoint
	 * cannot be used to probe other accounts' tokens.
	 *
	 * @return JSONResponse `{tokenId, name, grant}`; 401, 403, 404 or 422 when refused.
	 *
	 * @spec openspec/changes/scoped-api-tokens/specs/auth-system/spec.md#requirement-a-token-or-consumer-may-carry-a-grant-narrower-than-its-user
	 */
	#[NoAdminRequired]
	public function resolve(): JSONResponse {
		$uid = $this->uid();
		if ($uid === null) {
			return self::refusal(status: Http::STATUS_UNAUTHORIZED, message: 'not-logged-in');
		}

		if ($this->isTokenCaller() === true) {
			return self::refusal(status: Http::STATUS_FORBIDDEN, message: 'A token grant can only be changed from an interactive sign-in');
		}

		$password = $this->request->getParam('password');
		if (is_string($password) === false || trim($password) === '') {
			return self::refusal(status: Http::STATUS_UNPROCESSABLE_ENTITY, message: 'an app password is required');
		}

		try {
			$token = $this->services->tokenProvider?->getToken(trim($password));
		} catch (\Throwable $unknown) {
			$token = null;
		}

		if ($token === null || $token->getUID() !== $uid) {
			return self::refusal(status: Http::STATUS_NOT_FOUND, message: 'This is not one of your app passwords');
		}

		$tokenId = (string) $token->getId();
		$record  = $this->services->store->read(uid: $uid, tokenId: $tokenId);

		$described = null;
		if ($record !== null) {
			$described = self::describe(tokenId: $tokenId, record: $record);
		}

		return new JSONResponse(
			data: [
				'tokenId' => $tokenId,
				'name'    => $token->getName(),
				'grant'   => $described,
			]
		);
	}//end resolve()

	/**
	 * What a grant would let its token do on one object the caller can read.
	 *
	 * Per row verb the caller holds on the object, whether the token would hold
	 * it too, answered by the same permission check that enforces a request.
	 * Nothing is stored. An object the caller cannot read answers 404.
	 *
	 * @return JSONResponse `{verbs: [{verb, holder, token}], expired, empty}`; 401, 404 or 422 when refused.
	 *
	 * @spec openspec/changes/scoped-api-tokens/specs/auth-system/spec.md#requirement-a-token-or-consumer-may-carry-a-grant-narrower-than-its-user
	 */
	#[NoAdminRequired]
	public function preview(): JSONResponse {
		if ($this->uid() === null) {
			return self::refusal(status: Http::STATUS_UNAUTHORIZED, message: 'not-logged-in');
		}

		$grant = TokenGrant::fromStored(stored: $this->request->getParam(TokenGrant::KEY));
		$ref   = $this->objectRef();
		if ($grant === null || $ref === null) {
			return self::refusal(status: Http::STATUS_UNPROCESSABLE_ENTITY, message: 'a grant, a register, a schema and an object are required');
		}

		$object = $this->readableObject(ref: $ref);
		if ($object === null || $this->services->schemas === null || $this->services->preview === null) {
			return self::refusal(status: Http::STATUS_NOT_FOUND, message: 'Object not found');
		}

		try {
			$schema = $this->services->schemas->find((string) $object->getSchema());
		} catch (\Throwable $unreadable) {
			return self::refusal(status: Http::STATUS_NOT_FOUND, message: 'Object not found');
		}

		return new JSONResponse(
			data: $this->services->preview->preview(grant: $grant, schema: $schema, object: $object, now: new DateTimeImmutable())
		);
	}//end preview()

	/**
	 * The object the preview is asked about, from the request.
	 *
	 * @return ObjectRef|null The reference, or null when a part is missing.
	 */
	private function objectRef(): ?ObjectRef {
		$parts = [];
		foreach (['register', 'schema', 'object'] as $name) {
			$value = $this->request->getParam($name);
			if (is_string($value) === false && is_int($value) === false) {
				return null;
			}

			$value = trim((string) $value);
			if ($value === '') {
				return null;
			}

			$parts[$name] = $value;
		}

		return new ObjectRef(register: $parts['register'], schema: $parts['schema'], id: $parts['object']);
	}//end objectRef()

	/**
	 * The object, when the caller may read it; read with no grant bound so the
	 * preview is asked of the caller's own rights.
	 *
	 * @param ObjectRef $ref The object asked about.
	 *
	 * @return ObjectEntity|null The object, or null.
	 */
	private function readableObject(ObjectRef $ref): ?ObjectEntity {
		$gate = $this->services->gate;
		if ($gate === null) {
			return null;
		}

		return $this->services->source->runWithoutGrant(fn (): ?ObjectEntity => $gate->resolve(ref: $ref));
	}//end readableObject()

	/**
	 * A token label as stored and shown: trimmed, at most 200 characters, or null.
	 *
	 * @param mixed $label The submitted or stored label.
	 *
	 * @return string|null The label, or null when there is none.
	 */
	private static function labelOf(mixed $label): ?string {
		if (is_string($label) === false || trim($label) === '') {
			return null;
		}

		return mb_substr(trim($label), 0, 200);
	}//end labelOf()

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
			'label'    => self::labelOf(label: ($record['label'] ?? null)),
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
