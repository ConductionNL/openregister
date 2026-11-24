<?php
/**
 * CreateGuardInterface: the check a create runs against its schema's duplicate rules, as the save path asks it.
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
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts;

use OCA\OpenRegister\Contract\ObjectEntityInterface;

/**
 * The duplicate guard of a create.
 *
 * The object save path (Core) asks it before it writes a new object and, after
 * the write, records an override it allowed; Search keeps the duplicate rules
 * and binds its policy to this contract in its own wiring.
 *
 * @spec openspec/specs/duplicate-detection/spec.md#requirement-a-schema-declares-what-a-strong-match-does-at-create
 */
interface CreateGuardInterface {

	/**
	 * The payload key a caller sets to save through a blocking match.
	 *
	 * The save path strips it from the body before anything reads the body as
	 * data, so it never reaches schema validation, never lands in the stored
	 * object, and never has to be declared as a schema property.
	 *
	 * @var string
	 */
	public const OVERRIDE_KEY = '_dedupOverride';

	/**
	 * Enforce the schema's create rule for one create.
	 *
	 * Returns the matches an override was exercised over, so the caller can put
	 * them on the audit trail of the object it goes on to write, and an empty
	 * array in every other case. Throws when the create is refused.
	 *
	 * @param CreateCandidate $candidate The register and schema, the body (override flag already removed),
	 *                                   and whether the caller asked to save anyway.
	 *
	 * @return array<int, array<string, mixed>> Matches overridden, for the audit entry; empty otherwise.
	 *
	 * @throws \OCA\OpenRegister\Exception\DuplicateBlockedException When the schema blocks and the caller may not override.
	 *
	 * @spec openspec/specs/duplicate-detection/spec.md#requirement-a-schema-declares-what-a-strong-match-does-at-create
	 */
	public function guardCreate(CreateCandidate $candidate): array;

	/**
	 * Record on the created object that a blocking match was overridden.
	 *
	 * Best-effort: the object is already written, and losing the audit row must
	 * not undo a save that succeeded.
	 *
	 * @param ObjectEntityInterface            $object  The object that was created anyway.
	 * @param array<int, array<string, mixed>> $matches The matches it was created over.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/duplicate-detection/spec.md#requirement-a-schema-declares-what-a-strong-match-does-at-create
	 */
	public function recordOverride(ObjectEntityInterface $object, array $matches): void;
}//end interface
