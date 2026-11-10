<?php

/**
 * UnlockRequest: what a caller asks for when it releases an object lock.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/run-scoped-object-locking/specs/run-scoped-object-locking/spec.md#requirement-a-lock-is-released-through-its-own-endpoint-and-a-release-says-whether-there-was-one
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

/**
 * The object, the kind of lock and who releases it.
 *
 * @spec openspec/changes/run-scoped-object-locking/specs/run-scoped-object-locking/spec.md#requirement-a-lock-is-released-through-its-own-endpoint-and-a-release-says-whether-there-was-one
 */
final class UnlockRequest {

	/**
	 * Constructor.
	 *
	 * @param string      $identifier Object ID or UUID (or the advisory key).
	 * @param bool        $advisory   Release a pre-creation advisory lock without a lookup.
	 * @param string|null $runUuid    Flow run releasing the lock, for a run-scoped lock.
	 * @param bool        $break      Release regardless of holder; authorized at the call site.
	 */
	public function __construct(
		public readonly string $identifier,
		public readonly bool $advisory=false,
		public readonly ?string $runUuid=null,
		public readonly bool $break=false,
	) {
	}//end __construct()
}//end class
