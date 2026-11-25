<?php

/**
 * Port: remember the answer of a request sent under an Idempotency-Key.
 *
 * A caller outside the form area (the integration create) replays a remembered
 * answer instead of running a request twice. The port speaks plain values only,
 * so the caller never names the form store, its request value or its refusal.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Kernel
 * @package  OCA\OpenRegister\Kernel\Contracts
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

namespace OCA\OpenRegister\Kernel\Contracts;

/**
 * Reserve an Idempotency-Key, and remember or repeat its answer for 24 hours.
 *
 * @spec openspec/specs/integration-registry/spec.md
 */
interface IdempotentAnswerStoreInterface {
	/**
	 * A remembered answer under this key exists and belongs to this asker.
	 */
	public const REMEMBERED = 'remembered';

	/**
	 * Nothing is remembered under this key yet.
	 */
	public const NONE = 'none';

	/**
	 * The key was used by another asker or for another payload.
	 */
	public const TAKEN = 'taken';

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
	public function bindingFor(string $subject, array $payload): string;

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
	public function tryReserve(string $scope, string $key): bool;

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
	public function release(string $scope, string $key): void;

	/**
	 * The remembered answer under a key, for this binding.
	 *
	 * @param string $scope   What the key is scoped to.
	 * @param string $key     The Idempotency-Key.
	 * @param string $binding The asker's binding (see bindingFor()).
	 *
	 * @return array{state: string, answer: array<mixed>|null} state is REMEMBERED, NONE or TAKEN; answer only with REMEMBERED.
	 *
	 * @spec openspec/specs/integration-registry/spec.md
	 */
	public function remembered(string $scope, string $key, string $binding): array;

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
	public function remember(string $scope, string $key, string $binding, array $answer): void;
}//end interface
