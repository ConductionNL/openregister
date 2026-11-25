<?php

/**
 * CallingTokenInterface: the token or Consumer the current request came with.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Contract
 * @package  OCA\OpenRegister\Kernel\Contracts
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/changes/scoped-api-tokens-machine-callers/specs/auth-system/spec.md#requirement-a-machine-write-names-its-acting-human-when-the-instance-or-schema-requires-it-req-sat-006
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts;

/**
 * Names the credential a request was made with, when it is a token or a Consumer.
 *
 * The acting-human guard (Core) asks; the audit trail's request-scoped token
 * context (Compliance) answers and is bound to this contract.
 *
 * @spec openspec/changes/scoped-api-tokens-machine-callers/specs/auth-system/spec.md#requirement-a-machine-write-names-its-acting-human-when-the-instance-or-schema-requires-it-req-sat-006
 */
interface CallingTokenInterface {

	/**
	 * The calling token or Consumer, or null for a browser session or no token at all.
	 *
	 * Null as well when the token cannot be resolved: a caller must never read
	 * a failed lookup as a machine.
	 *
	 * @return array{reference: string|null, ownerUid: string|null, consumerUuid: string|null, consumerName: string|null}|null The credential.
	 *
	 * @spec openspec/changes/scoped-api-tokens-machine-callers/specs/auth-system/spec.md#requirement-a-machine-write-names-its-acting-human-when-the-instance-or-schema-requires-it-req-sat-006
	 */
	public function callingToken(): ?array;
}//end interface
