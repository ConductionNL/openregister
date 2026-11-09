<?php

/**
 * The object API's draft action: the owner makes their own draft definitive (decision 216).
 *
 * `POST /api/objects/{register}/{schema}/{id}/activate`
 *
 * An explicit action, not a field on a save: a PUT or PATCH that sets
 * `@self.status` to `active` stays refused, so nothing leaves draft by
 * accident. The acting user is the owner the action asserts; a request cannot
 * name another one.
 *
 * A plain `Controller` on purpose. Nextcloud's OCSMiddleware turns a 403 out
 * of an OCSController into an HTTP 200 that carries the refusal in its body,
 * and a refusal here must reach the caller as the status it was given.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/draft-owner-activation-and-lifetime/specs/form-destination/spec.md#requirement-a-drafts-owner-must-be-able-to-make-it-definitive-through-the-object-api
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use OCA\OpenRegister\Controller\Support\ObjectDraftControllerServices;
use OCA\OpenRegister\Controller\Support\ObjectLinkRoute;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Exception\DraftActivationRefusedException;
use OCA\OpenRegister\Service\Object\DraftActivationInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Leaves draft on the owner's word, after full validation.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) A controller that resolves a record through the
 * RBAC boundary, its register and its schema, and answers five statuses, names those types.
 *
 * @spec openspec/changes/draft-owner-activation-and-lifetime/specs/form-destination/spec.md#requirement-a-drafts-owner-must-be-able-to-make-it-definitive-through-the-object-api
 */
class ObjectDraftController extends Controller {

	/**
	 * Resolves the record through the RBAC boundary.
	 *
	 * @var ObjectOperationsInterface
	 */
	private readonly ObjectOperationsInterface $objects;

	/**
	 * Resolves the register.
	 *
	 * @var RegisterMapperInterface
	 */
	private readonly RegisterMapperInterface $registers;

	/**
	 * Resolves the schema.
	 *
	 * @var SchemaMapperInterface
	 */
	private readonly SchemaMapperInterface $schemas;

	/**
	 * Makes the draft definitive.
	 *
	 * @var DraftActivationInterface
	 */
	private readonly DraftActivationInterface $activation;

	/**
	 * The acting user, who is the asserted owner.
	 *
	 * @var IUserSession
	 */
	private readonly IUserSession $session;

	/**
	 * Translations.
	 *
	 * @var IL10N
	 */
	private readonly IL10N $l10n;

	/**
	 * Logger.
	 *
	 * @var LoggerInterface
	 */
	private readonly LoggerInterface $logger;

	/**
	 * Constructor.
	 *
	 * @param string          $appName    App name.
	 * @param IRequest        $request    Request.
	 * @param ObjectDraftControllerServices $services The object draft controller services.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		ObjectDraftControllerServices $services,
	) {
		parent::__construct(appName: $appName, request: $request);
		$this->objects = $services->domain->objects;
		$this->registers = $services->domain->registers;
		$this->schemas = $services->domain->schemas;
		$this->activation = $services->domain->activation;
		$this->session = $services->platform->session;
		$this->l10n = $services->platform->l10n;
		$this->logger = $services->platform->logger;
	}//end __construct()

	/**
	 * Make the signed-in user's own draft definitive.
	 *
	 * The body may carry `object`: the last property values, laid over the
	 * stored draft before it is judged. Without it the draft is judged as
	 * stored. The answer is the object, now `active`, under the same id.
	 *
	 * Refusals, each with `{ message, reason, findings }`: 401 not signed in,
	 * 404 unknown or not readable, 409 not a draft, 403 somebody else's
	 * draft, 422 not valid in full (one finding per property).
	 *
	 * @param string $register Register slug or id.
	 * @param string $schema   Schema slug or id.
	 * @param string $id       The draft's uuid.
	 *
	 * @return JSONResponse The activated object, or the refusal.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/draft-owner-activation-and-lifetime/specs/form-destination/spec.md#requirement-a-drafts-owner-must-be-able-to-make-it-definitive-through-the-object-api
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[UserRateLimit(limit: 120, period: 60)]
	public function activate(string $register, string $schema, string $id): JSONResponse {
		$user = $this->session->getUser();
		if ($user === null) {
			return $this->notSignedIn();
		}

		// Through the RBAC boundary first: a record the caller may not read
		// answers the same 404 an unknown id gets, so this endpoint cannot be
		// used to probe which ids exist.
		$draft = $this->readableDraft(route: new ObjectLinkRoute(register: $register, schema: $schema, id: $id));
		if ($draft === null) {
			return $this->notFound();
		}

		return $this->activateFor(draft: $draft, user: $user);
	}//end activate()

	/**
	 * The register and schema of a draft the caller may read, or null when it is unknown or not readable.
	 *
	 * The register and the schema are looked up as the object API looks them
	 * up (ObjectService::setRegister()): past the organisation filter. A draft
	 * in a register an administrator made in another organisation is still its
	 * owner's; the read of the draft is the authorisation.
	 *
	 * @param ObjectLinkRoute $route Register slug or id, schema slug or id, and the draft's uuid.
	 *
	 * @return array{register: \OCA\OpenRegister\Db\Register, schema: \OCA\OpenRegister\Db\Schema, id: string}|null The register,
	 *         schema and uuid.
	 */
	private function readableDraft(ObjectLinkRoute $route): ?array {
		try {
			$registerEntity = $this->registers->find(id: $route->register, _rbac: false, _multitenancy: false);
			$schemaEntity = $this->schemas->find(id: $route->schema, _rbac: false, _multitenancy: false);
			$readable = $this->objects->find(id: $route->id, register: $registerEntity, schema: $schemaEntity, _render: false, _audit: false);
		} catch (Throwable) {
			return null;
		}

		if (($readable instanceof ObjectEntity) === false) {
			return null;
		}

		return ['register' => $registerEntity, 'schema' => $schemaEntity, 'id' => $route->id];
	}//end readableDraft()

	/**
	 * The property values the body lays over the stored draft; none when it sends no object.
	 *
	 * @return array<string, mixed> The changes.
	 */
	private function changesFromRequest(): array {
		$changes = $this->request->getParam('object');
		if (is_array($changes) === false) {
			return [];
		}

		return $changes;
	}//end changesFromRequest()

	/**
	 * Make a readable draft definitive for its asserted owner, and answer the outcome.
	 *
	 * @param array<string, mixed> $draft The draft's register, schema and uuid.
	 * @param IUser                $user  The signed-in user.
	 *
	 * @return JSONResponse The activated object, or the refusal.
	 */
	private function activateFor(array $draft, IUser $user): JSONResponse {
		try {
			$activated = $this->activateDraft(draft: $draft, user: $user);
		} catch (DraftActivationRefusedException $refused) {
			return new JSONResponse(data: $refused->toBody(), statusCode: $refused->getStatus());
		} catch (Throwable $exception) {
			return $this->unavailable(exception: $exception);
		}

		// The saved entity goes through the render boundary the object API reads
		// through: a `writeOnly` property is "may be sent, never returned", a
		// property the caller's groups may not read is not theirs, and an
		// encrypted one comes back decrypted for who may read it. The save
		// renders its result already, but an automatic transition the save
		// applied hands back an entity of its own, so it is rendered here once
		// more: rendering is idempotent.
		return $this->renderedActivation(activated: $activated);
	}//end activateFor()

	/**
	 * Make the draft definitive as the signed-in user.
	 *
	 * The property values the request body lays over the draft are read here.
	 *
	 * @param array<string, mixed> $draft The draft's register, schema and uuid.
	 * @param IUser                $user  The signed-in user.
	 *
	 * @return ObjectEntity The activated object.
	 *
	 * @throws DraftActivationRefusedException When the draft may not be made definitive.
	 */
	private function activateDraft(array $draft, IUser $user): ObjectEntity {
		return $this->activation->activateAsUser(
			register: $draft['register'],
			schema: $draft['schema'],
			draftId: $draft['id'],
			user: $user,
			changes: $this->changesFromRequest()
		);
	}//end activateDraft()

	/**
	 * The 401 for a caller who is not signed in.
	 *
	 * @return JSONResponse The refusal.
	 */
	private function notSignedIn(): JSONResponse {
		return $this->refusal(
			message: $this->l10n->t('Please sign in to make this draft definitive.'),
			reason: 'not-signed-in',
			status: Http::STATUS_UNAUTHORIZED
		);
	}//end notSignedIn()

	/**
	 * The 404 for a draft that is unknown or that the caller may not read.
	 *
	 * @return JSONResponse The refusal.
	 */
	private function notFound(): JSONResponse {
		return $this->refusal(
			message: $this->l10n->t('This draft does not exist.'),
			reason: DraftActivationRefusedException::NOT_FOUND,
			status: Http::STATUS_NOT_FOUND
		);
	}//end notFound()

	/**
	 * The 500 for an activation that failed for another reason; the message is never echoed, it can carry storage details.
	 *
	 * @param Throwable $exception The failure.
	 *
	 * @return JSONResponse The refusal.
	 */
	private function unavailable(Throwable $exception): JSONResponse {
		$this->logger->error(
			message: '[ObjectDraftController] activate: ' . $exception->getMessage(),
			context: ['file' => __FILE__, 'line' => __LINE__, 'exception' => $exception]
		);

		return $this->refusal(
			message: $this->l10n->t('The draft could not be made definitive right now. Nothing was changed; try again later.'),
			reason: 'unavailable',
			status: Http::STATUS_INTERNAL_SERVER_ERROR
		);
	}//end unavailable()

	/**
	 * The answer for an activation that was committed: the rendered object, or its id and status when rendering fails.
	 *
	 * The activation has been committed by now, so a render that fails must
	 * not answer 500: the caller would read "nothing changed" and retry or
	 * give up on a draft that is definitive. The fallback names the object and
	 * its new status only, and the failure is logged.
	 *
	 * @param ObjectEntity $activated The activated object.
	 *
	 * @return JSONResponse The 200 answer.
	 */
	private function renderedActivation(ObjectEntity $activated): JSONResponse {
		try {
			return new JSONResponse(data: $this->objects->renderEntity(entity: $activated, _rbac: true));
		} catch (Throwable $exception) {
			$this->logger->error(
				message: '[ObjectDraftController] activate: the activated object could not be rendered: ' . $exception->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__, 'exception' => $exception]
			);

			$uuid = (string)$activated->getUuid();

			return new JSONResponse(
				data: ['id' => $uuid, 'uuid' => $uuid, '@self' => ['id' => $uuid, 'status' => $activated->getStatus()]]
			);
		}
	}//end renderedActivation()

	/**
	 * A refusal in the one body shape this endpoint answers with.
	 *
	 * @param string $message The sentence the caller shows.
	 * @param string $reason  A stable word a client can branch on.
	 * @param int    $status  The HTTP status.
	 *
	 * @return JSONResponse The refusal.
	 */
	private function refusal(string $message, string $reason, int $status): JSONResponse {
		return new JSONResponse(data: ['message' => $message, 'reason' => $reason, 'findings' => []], statusCode: $status);
	}//end refusal()
}//end class
