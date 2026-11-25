<?php

/**
 * ActingHumanRecordInterface: the verified acting human of the current request's machine write.
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
 * The pair an audit row of a machine write records: who it acted for, and through what.
 *
 * The audit trail's token attribution (Compliance) asks; the acting-human
 * context (Core) answers and is bound to this contract.
 *
 * @spec openspec/changes/scoped-api-tokens-machine-callers/specs/auth-system/spec.md#requirement-a-machine-write-names-its-acting-human-when-the-instance-or-schema-requires-it-req-sat-006
 */
interface ActingHumanRecordInterface {

	/**
	 * The verified acting user and the token or Consumer id, or null when none was verified.
	 *
	 * @return array{actingUser: string, actorVia: string}|null The pair.
	 *
	 * @spec openspec/changes/scoped-api-tokens-machine-callers/specs/auth-system/spec.md#requirement-a-machine-write-names-its-acting-human-when-the-instance-or-schema-requires-it-req-sat-006
	 */
	public function toArray(): ?array;
}//end interface
