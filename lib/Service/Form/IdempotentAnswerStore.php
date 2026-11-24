<?php

/**
 * The form Idempotency-Key store, offered to other areas through the Kernel port.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Form
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

namespace OCA\OpenRegister\Service\Form;

use OCA\OpenRegister\Exception\FormSubmitRefusedException;
use OCA\OpenRegister\Kernel\Contracts\IdempotentAnswerStoreInterface;

/**
 * Adapter: the same files, TTL and binding as the form submits, refusals turned into plain answers.
 *
 * @spec openspec/specs/integration-registry/spec.md
 */
class IdempotentAnswerStore implements IdempotentAnswerStoreInterface {
	/**
	 * Constructor.
	 *
	 * @param FormIdempotencyStore $store The form submits' Idempotency-Key store.
	 *
	 * @spec openspec/specs/integration-registry/spec.md
	 */
	public function __construct(
		private readonly FormIdempotencyStore $store,
	) {
	}//end __construct()

	/**
	 * The binding of an answer to who asked and what they sent.
	 *
	 * @param string               $subject Who asks (a user marker).
	 * @param array<string, mixed> $payload What they sent.
	 *
	 * @return string The binding.
	 *
	 * @spec openspec/specs/integration-registry/spec.md
	 */
	public function bindingFor(string $subject, array $payload): string {
		return $this->store->bindingFor(subject: $subject, payload: $payload);
	}//end bindingFor()

	/**
	 * Reserve the key for one running request.
	 *
	 * @param string $scope What the key is scoped to.
	 * @param string $key   The Idempotency-Key.
	 *
	 * @return bool False when a request with this key is still running.
	 *
	 * @spec openspec/specs/integration-registry/spec.md
	 */
	public function tryReserve(string $scope, string $key): bool {
		try {
			$this->store->reserve(scope: $scope, key: $key);
		} catch (FormSubmitRefusedException) {
			return false;
		}

		return true;
	}//end tryReserve()

	/**
	 * Release a reservation.
	 *
	 * @param string $scope What the key is scoped to.
	 * @param string $key   The Idempotency-Key.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/integration-registry/spec.md
	 */
	public function release(string $scope, string $key): void {
		$this->store->release(scope: $scope, key: $key);
	}//end release()

	/**
	 * The remembered answer under a key, for this binding.
	 *
	 * @param string $scope   What the key is scoped to.
	 * @param string $key     The Idempotency-Key.
	 * @param string $binding The asker's binding.
	 *
	 * @return array{state: string, answer: array<mixed>|null} REMEMBERED with the answer, NONE, or TAKEN.
	 *
	 * @spec openspec/specs/integration-registry/spec.md
	 */
	public function remembered(string $scope, string $key, string $binding): array {
		try {
			$answer = $this->store->answerFor(request: new IdempotentRequest(scope: $scope, key: $key, binding: $binding));
		} catch (FormSubmitRefusedException) {
			return ['state' => self::TAKEN, 'answer' => null];
		}

		if ($answer === null) {
			return ['state' => self::NONE, 'answer' => null];
		}

		return ['state' => self::REMEMBERED, 'answer' => $answer];
	}//end remembered()

	/**
	 * Remember an answer under a key for 24 hours.
	 *
	 * @param string       $scope   What the key is scoped to.
	 * @param string       $key     The Idempotency-Key.
	 * @param string       $binding The asker's binding.
	 * @param array<mixed> $answer  The answer to repeat.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/integration-registry/spec.md
	 */
	public function remember(string $scope, string $key, string $binding, array $answer): void {
		$this->store->keep(request: new IdempotentRequest(scope: $scope, key: $key, binding: $binding), response: $answer);
	}//end remember()
}//end class
