<?php

/**
 * Object read state controller — what you have seen, and putting it back.
 *
 * WHY THESE ENDPOINTS EXIST AT ALL. A read state is per-user, per-object state
 * that must NOT be written through the object: storing it on the object would
 * put "alice looked at this" in the object's audit trail and cut a new version
 * every time somebody opened it. So it gets its own entry point, and the rows
 * live in `openregister_object_read_state`.
 *
 * THE IDOR GUARD, in two layers. Every method resolves the object through
 * `ObjectService` first, which applies register RBAC and multitenancy: an object
 * the caller cannot READ resolves to null and the request is refused with 404,
 * never 403, so existence is not leaked. Then `ReadStateService` applies the
 * second and shorter rule: a caller reads and writes their OWN read state and
 * nobody else's, with no admin override, because a read state is a fact about a
 * person rather than about the object. Naming another user is refused with 403
 * rather than silently answered about yourself, which would be a lie.
 *
 * This is NOT a pass-through to ObjectService: every method calls the read-state
 * primitive, which no other route reaches.
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
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/object-read-state/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use OCA\OpenRegister\Controller\Support\ObjectSharingEndpoint;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\ObjectReadState;
use OCA\OpenRegister\Service\Interaction\ReadStateService;
use OCA\OpenRegister\Notifications\Service\Notification\NotificationClearingServiceInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Read an object's read state, mark it read, and mark it back to unread.
 */
class ObjectReadStateController extends Controller {

	/**
	 * Resolves the target object through the RBAC boundary, runs the primitive
	 * and maps its refusals.
	 *
	 * An object the caller cannot READ is refused with 404 rather than 403, so
	 * these endpoints cannot be used to probe which object ids exist.
	 *
	 * @var ObjectSharingEndpoint
	 */
	private readonly ObjectSharingEndpoint $endpoint;

	/**
	 * Constructor.
	 *
	 * @param string $appName App name.
	 * @param IRequest $request Request.
	 * @param ObjectOperationsInterface $objectService Resolves an object through the RBAC boundary.
	 * @param ReadStateService $readState The read-state primitive.
	 * @param NotificationClearingServiceInterface $clearing Empties the bell when the work is opened.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		ObjectOperationsInterface $objectService,
		private readonly ReadStateService $readState,
		private readonly NotificationClearingServiceInterface $clearing,
		LoggerInterface $logger,
	) {
		parent::__construct(appName: $appName, request: $request);
		$failure = 'Could not complete the read state request';
		$this->endpoint = new ObjectSharingEndpoint(objectService: $objectService, logger: $logger, file: __FILE__, failure: $failure);
	}//end __construct()

	/**
	 * The calling user's own read state for one object.
	 *
	 * @param string $register Register slug or id.
	 * @param string $schema Schema slug or id.
	 * @param string $id Object uuid.
	 *
	 * @return JSONResponse The marker, the moment and the tab badges.
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/specs/object-read-state/spec.md#requirement-an-object-carries-a-read-state-per-user-req-ors-001
	 */
	#[NoAdminRequired]
	public function show(string $register, string $schema, string $id): JSONResponse {
		$object = $this->endpoint->resolve(register: $register, schema: $schema, id: $id);
		if ($object instanceof JSONResponse) {
			return $object;
		}

		$read = fn (): ?ObjectReadState => $this->readState->readStateFor(object: $object, userId: $this->request->getParam('userId'));
		$state = $this->endpoint->run(operation: $read, context: 'show');
		if ($state instanceof JSONResponse) {
			return $state;
		}

		return $this->stateResponse(state: $state, object: $object);

	}//end show()

	/**
	 * Record that the calling user has now seen this object.
	 *
	 * Opening the work is what empties the bell: every unread notice this user
	 * holds about the object is cleared with it, and when a sub-resource is
	 * named, only that sub-resource's notices are.
	 *
	 * @param string $register Register slug or id.
	 * @param string $schema Schema slug or id.
	 * @param string $id Object uuid.
	 *
	 * @return JSONResponse The stored read state and what it cleared.
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md#requirement-a-notification-is-cleared-by-opening-what-it-was-about-req-ors-003
	 */
	#[NoAdminRequired]
	public function markRead(string $register, string $schema, string $id): JSONResponse {
		$object = $this->endpoint->resolve(register: $register, schema: $schema, id: $id);
		if ($object instanceof JSONResponse) {
			return $object;
		}

		$subResource = $this->requestedSubResource();
		$mark = fn (): array => $this->markAndClear(object: $object, register: $register, schema: $schema, subResource: $subResource);
		$marked = $this->endpoint->run(operation: $mark, context: 'markRead');
		if ($marked instanceof JSONResponse) {
			return $marked;
		}

		$cleared = ['unread' => false, 'notificationsCleared' => $marked['cleared']];
		return new JSONResponse(array_merge($marked['state']->jsonSerialize(), $cleared));

	}//end markRead()

	/**
	 * Put this object back to unread for the calling user.
	 *
	 * It stays read for everybody else: the row this removes is nobody's but
	 * the caller's.
	 *
	 * @param string $register Register slug or id.
	 * @param string $schema Schema slug or id.
	 * @param string $id Object uuid.
	 *
	 * @return JSONResponse The new marker.
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/specs/object-read-state/spec.md#requirement-an-object-carries-a-read-state-per-user-req-ors-001
	 */
	#[NoAdminRequired]
	public function markUnread(string $register, string $schema, string $id): JSONResponse {
		$object = $this->endpoint->resolve(register: $register, schema: $schema, id: $id);
		if ($object instanceof JSONResponse) {
			return $object;
		}

		$unmark = function () use ($object): array {
			$this->readState->markUnread(object: $object);
			return ['unread' => true];
		};
		return $this->endpoint->answer(operation: $unmark, context: 'markUnread');

	}//end markUnread()

	/**
	 * The read-state answer: the marker, the moment and the tab badges.
	 *
	 * @param ObjectReadState|null $state  The caller's read state, or null when unread.
	 * @param ObjectEntity         $object The object.
	 *
	 * @return JSONResponse The answer.
	 */
	private function stateResponse(?ObjectReadState $state, ObjectEntity $object): JSONResponse {
		return new JSONResponse(
			[
				'unread' => ($state === null),
				'lastSeenAt' => $state?->jsonSerialize()['lastSeenAt'],
				'subSeen' => ($state?->getSubSeen() ?? []),
				'unreadCounts' => $this->readState->unreadCounts(object: $object),
			]
		);

	}//end stateResponse()

	/**
	 * The sub-resource the request names, or null when it names none.
	 *
	 * @return string|null The sub-resource.
	 */
	private function requestedSubResource(): ?string {
		$subResource = $this->request->getParam('subResource');
		if (is_string($subResource) === false || $subResource === '') {
			return null;
		}

		return $subResource;

	}//end requestedSubResource()

	/**
	 * Store the read state, then clear the notices the opened work was about.
	 *
	 * @param ObjectEntity $object      The object that was opened.
	 * @param string       $register    Register slug or id.
	 * @param string       $schema      Schema slug or id.
	 * @param string|null  $subResource The sub-resource, when one was opened.
	 *
	 * @return array{state: ObjectReadState, cleared: int} The stored state and how many notices were cleared.
	 */
	private function markAndClear(ObjectEntity $object, string $register, string $schema, ?string $subResource): array {
		$state = $this->readState->markRead(
			object: $object,
			register: $register,
			schema: $schema,
			subResource: $subResource
		);

		return ['state' => $state, 'cleared' => $this->clearNotices(object: $object, subResource: $subResource)];

	}//end markAndClear()

	/**
	 * Clear the notices the opened work was about.
	 *
	 * @param ObjectEntity $object The object that was opened.
	 * @param string|null $subResource The sub-resource, when one was opened.
	 *
	 * @return integer How many notices were cleared.
	 */
	private function clearNotices(ObjectEntity $object, ?string $subResource): int {
		$uuid = (string)$object->getUuid();
		if ($subResource === null) {
			return $this->clearing->clearForObject(objectUuid: $uuid);
		}

		return $this->clearing->clearForSubResource(objectUuid: $uuid, subjectId: $subResource);

	}//end clearNotices()
}//end class
