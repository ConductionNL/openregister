<?php

/**
 * OpenRegister ActingHumanRefusedException
 *
 * Thrown when a machine write does not name, or may not act for, the human behind it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Exception
 * @package  OCA\OpenRegister\Exception
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

namespace OCA\OpenRegister\Exception;

use Exception;
use OCP\AppFramework\Http;

/**
 * A 403 with a machine-readable error: `acting-human-required` or `acting-human-not-delegated`.
 *
 * @spec openspec/changes/scoped-api-tokens-machine-callers/specs/auth-system/spec.md#requirement-a-machine-write-names-its-acting-human-when-the-instance-or-schema-requires-it-req-sat-006
 */
class ActingHumanRefusedException extends Exception {

	/**
	 * The write named no human.
	 *
	 * @var string
	 */
	public const REQUIRED = 'acting-human-required';

	/**
	 * The write named a human who gave the caller no live delegation.
	 *
	 * @var string
	 */
	public const NOT_DELEGATED = 'acting-human-not-delegated';

	/**
	 * Constructor.
	 *
	 * @param string      $error      One of the constants above.
	 * @param string|null $actingUser The user the write named, when it named one.
	 *
	 * @spec openspec/changes/scoped-api-tokens-machine-callers/specs/auth-system/spec.md#requirement-a-machine-write-names-its-acting-human-when-the-instance-or-schema-requires-it-req-sat-006
	 */
	public function __construct(
		private readonly string $error,
		private readonly ?string $actingUser = null,
	) {
		parent::__construct(message: $error, code: Http::STATUS_FORBIDDEN);
	}//end __construct()

	/**
	 * Named refusal: the write named nobody.
	 *
	 * @return self The refusal.
	 *
	 * @spec openspec/changes/scoped-api-tokens-machine-callers/specs/auth-system/spec.md#requirement-a-machine-write-names-its-acting-human-when-the-instance-or-schema-requires-it-req-sat-006
	 */
	public static function required(): self {
		return new self(error: self::REQUIRED);
	}//end required()

	/**
	 * Named refusal: the write named a user who did not delegate to the caller.
	 *
	 * @param string $actingUser The user the write named.
	 *
	 * @return self The refusal.
	 *
	 * @spec openspec/changes/scoped-api-tokens-machine-callers/specs/auth-system/spec.md#requirement-a-machine-write-names-its-acting-human-when-the-instance-or-schema-requires-it-req-sat-006
	 */
	public static function notDelegated(string $actingUser): self {
		return new self(error: self::NOT_DELEGATED, actingUser: $actingUser);
	}//end notDelegated()

	/**
	 * The machine-readable error.
	 *
	 * @return string One of the constants above.
	 *
	 * @spec openspec/changes/scoped-api-tokens-machine-callers/specs/auth-system/spec.md#requirement-a-machine-write-names-its-acting-human-when-the-instance-or-schema-requires-it-req-sat-006
	 */
	public function getError(): string {
		return $this->error;
	}//end getError()

	/**
	 * The user the write named, or null when it named nobody.
	 *
	 * @return string|null The user id.
	 *
	 * @spec openspec/changes/scoped-api-tokens-machine-callers/specs/auth-system/spec.md#requirement-a-machine-write-names-its-acting-human-when-the-instance-or-schema-requires-it-req-sat-006
	 */
	public function getActingUser(): ?string {
		return $this->actingUser;
	}//end getActingUser()

	/**
	 * The response body the HTTP routes answer with.
	 *
	 * @return array<string, string> The error, plus the named user when there is one.
	 *
	 * @spec openspec/changes/scoped-api-tokens-machine-callers/specs/auth-system/spec.md#requirement-a-machine-write-names-its-acting-human-when-the-instance-or-schema-requires-it-req-sat-006
	 */
	public function toResponseBody(): array {
		$body = ['error' => $this->error];
		if ($this->actingUser !== null) {
			$body['actingUser'] = $this->actingUser;
		}

		return $body;
	}//end toResponseBody()
}//end class
