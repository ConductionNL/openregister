<?php

/**
 * OpenRegister ActingHumanContext
 *
 * Carries the verified acting human of a machine write for the length of one request.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Authorization
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/scoped-api-tokens-machine-callers/specs/auth-system/spec.md#requirement-a-machine-write-names-its-acting-human-when-the-instance-or-schema-requires-it-req-sat-006
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Authorization;

use OCA\OpenRegister\Kernel\Contracts\ActingHumanRecordInterface;

/**
 * The human a machine caller was verified to act for, and the credential it came through.
 *
 * Request-scoped, the same shape as the token context the audit writer reads:
 * the guard runs at the write's entry and the audit row is built deep inside
 * the save, so the verified pair is handed over here rather than threaded
 * through every save signature. Only the guard records; only a verified pair
 * is ever held.
 *
 * @spec openspec/changes/scoped-api-tokens-machine-callers/specs/auth-system/spec.md#requirement-a-machine-write-names-its-acting-human-when-the-instance-or-schema-requires-it-req-sat-006
 */
class ActingHumanContext implements ActingHumanRecordInterface {

	/**
	 * The verified acting user, or null when none was verified in this request.
	 *
	 * @var string|null
	 */
	private ?string $actingUser = null;

	/**
	 * The token or Consumer the write came through.
	 *
	 * @var string|null
	 */
	private ?string $actorVia = null;

	/**
	 * Record a verified acting human.
	 *
	 * @param string $actingUser The user the caller acts for.
	 * @param string $actorVia   The token or Consumer id the write came through.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/scoped-api-tokens-machine-callers/specs/auth-system/spec.md#requirement-a-machine-write-names-its-acting-human-when-the-instance-or-schema-requires-it-req-sat-006
	 */
	public function record(string $actingUser, string $actorVia): void {
		$this->actingUser = $actingUser;
		$this->actorVia   = $actorVia;
	}//end record()

	/**
	 * The sealed form written onto an audit row, or null when nothing was verified.
	 *
	 * @return array{actingUser: string, actorVia: string}|null The pair.
	 *
	 * @spec openspec/changes/scoped-api-tokens-machine-callers/specs/auth-system/spec.md#requirement-a-machine-write-names-its-acting-human-when-the-instance-or-schema-requires-it-req-sat-006
	 */
	public function toArray(): ?array {
		if ($this->actingUser === null || $this->actorVia === null) {
			return null;
		}

		return ['actingUser' => $this->actingUser, 'actorVia' => $this->actorVia];
	}//end toArray()
}//end class
