<?php

/**
 * Repeats the answer of an integration create that is sent again under the same Idempotency-Key.
 *
 * A phone that books time while offline queues the create and sends it again
 * when the connection comes back; when the first send did reach the server but
 * its answer got lost, the replay would make a second linked thing. With an
 * Idempotency-Key the replay gets the first answer and nothing is created.
 *
 * The answers live in the same store as the form submit answers (24 hours, one
 * small file per key, a hash of the scope and the key as its name). A stored
 * answer is bound to the signed-in user and the payload, so a key alone never
 * hands somebody else's answer over: another user or another payload under the
 * same key gets a 409, as does a replay while the first create still runs.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Integration
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/integration-registry/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Integration;

use OCA\OpenRegister\Kernel\Contracts\IdempotentAnswerStoreInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectRef;
use OCP\IUserSession;

/**
 * Run an integration create at most once per Idempotency-Key.
 *
 * @spec openspec/specs/integration-registry/spec.md
 */
class IntegrationCreateIdempotency {
	/**
	 * Constructor.
	 *
	 * Without a store every create runs, key or not (a controller built outside the container).
	 *
	 * @param IdempotentAnswerStoreInterface|null $store       Reserves keys and remembers answers for 24 hours.
	 * @param IUserSession|null                   $userSession Who sends the create; a stored answer is bound to them.
	 *
	 * @spec openspec/specs/integration-registry/spec.md
	 */
	public function __construct(
		private readonly ?IdempotentAnswerStoreInterface $store = null,
		private readonly ?IUserSession $userSession = null,
	) {
	}//end __construct()

	/**
	 * Run the create once per key: a remembered answer is repeated, a fresh one remembered.
	 *
	 * An empty key, or a runner without a store, runs the create without any idempotency handling.
	 *
	 * @param string                       $key           The Idempotency-Key header, as sent.
	 * @param string                       $integrationId The integration the create is for.
	 * @param ObjectRef                    $object        The object the linked thing is created on.
	 * @param array<string, mixed>         $payload       The create's payload.
	 * @param callable(): array<mixed>     $create        Runs the create and returns its answer.
	 *
	 * @return array{answer: array<mixed>, replayed: bool} The answer, and whether it was a remembered one.
	 *
	 * @throws IdempotencyConflictException When the key is in use by a running create, another user or another payload.
	 *
	 * @spec openspec/specs/integration-registry/spec.md
	 */
	public function run(string $key, string $integrationId, ObjectRef $object, array $payload, callable $create): array {
		$key = trim($key);
		if ($key === '' || $this->store === null) {
			return ['answer' => $create(), 'replayed' => false];
		}

		$scope   = 'integration:' . $integrationId . ':' . $object->register . '/' . $object->schema . '/' . $object->id;
		$binding = $this->store->bindingFor(subject: $this->subject(), payload: $payload);

		if ($this->store->tryReserve(scope: $scope, key: $key) === false) {
			throw new IdempotencyConflictException(message: 'A create with this Idempotency-Key is still running.');
		}

		try {
			return $this->answerOnce(store: $this->store, scope: $scope, key: $key, binding: $binding, create: $create);
		} finally {
			$this->store->release(scope: $scope, key: $key);
		}
	}//end run()

	/**
	 * The remembered answer of a reserved key, or the answer of a fresh create, remembered.
	 *
	 * @param IdempotentAnswerStoreInterface $store   The store the key is reserved in.
	 * @param string                         $scope   What the key is scoped to.
	 * @param string                         $key     The Idempotency-Key.
	 * @param string                         $binding Who sent what.
	 * @param callable(): array<mixed>       $create  Runs the create and returns its answer.
	 *
	 * @return array{answer: array<mixed>, replayed: bool} The answer, and whether it was a remembered one.
	 *
	 * @throws IdempotencyConflictException When the key was used by another user or for another payload.
	 */
	private function answerOnce(IdempotentAnswerStoreInterface $store, string $scope, string $key, string $binding, callable $create): array {
		$remembered = $store->remembered(scope: $scope, key: $key, binding: $binding);
		if ($remembered['state'] === IdempotentAnswerStoreInterface::TAKEN) {
			throw new IdempotencyConflictException(message: 'This Idempotency-Key was already used for another create.');
		}

		if ($remembered['state'] === IdempotentAnswerStoreInterface::REMEMBERED && $remembered['answer'] !== null) {
			return ['answer' => $remembered['answer'], 'replayed' => true];
		}

		$answer = $create();
		$store->remember(scope: $scope, key: $key, binding: $binding, answer: $answer);

		return ['answer' => $answer, 'replayed' => false];
	}//end answerOnce()

	/**
	 * The user a stored answer is bound to; an anonymous call binds to a marker.
	 *
	 * @return string The subject.
	 */
	private function subject(): string {
		$user = $this->userSession?->getUser();
		if ($user === null) {
			return 'anonymous';
		}

		return 'user:' . $user->getUID();
	}//end subject()
}//end class
