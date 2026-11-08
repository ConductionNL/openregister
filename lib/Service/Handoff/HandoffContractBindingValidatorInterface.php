<?php

/**
 * HandoffContractBindingValidatorInterface: what callers outside the component use of HandoffContractBindingValidator.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Handoff
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Handoff;

/**
 * Declares only what callers outside the component use of HandoffContractBindingValidator; the rest
 * of the class stays its own.
 */
interface HandoffContractBindingValidatorInterface {

	/**
	 * Validate the binding block.
	 *
	 * Expects the shape SchemaMapper hands the annotation validators, with the
	 * configuration-level semantic markers alongside `properties`:
	 * `['properties' => [...], 'implements' => [...]?, 'jsonld' => [...]?,
	 *   'x-schema-org' => ...?, 'handoffContract' => [...]?]`.
	 *
	 * The binding block maps kind URIs to `{contractField: ownProperty}` maps:
	 * `"handoffContract": {"https://openregister.app/ns#Case": {"title": "onderwerp", ...}}`.
	 *
	 * @param array<string, mixed> $schema The schema shape to validate.
	 *
	 * @return array<int, array{code: string, message: string}> Aggregated errors (empty = valid).
	 *
	 * @spec openspec/changes/semantic-object-handoff-engine/specs/semantic-object-handoff/spec.md
	 *   (Scenario: Implementer omits a mandatory contract field)
	 */
	public function validate(array $schema): array;
}//end interface
