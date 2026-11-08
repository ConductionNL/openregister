<?php

/**
 * RedactionWriteServiceInterface: what callers outside the Service component use of RedactionWriteService.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Gdpr\Redaction
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Gdpr\Redaction;

use RuntimeException;

/**
 * Applies a redaction to a DSAR disclosure.
 */
interface RedactionWriteServiceInterface {

	/**
	 * Apply a field-level redaction to a case field.
	 *
	 * Captures the field's current value as `before`, sets the redacted `after`
	 * value on the case field, and appends a `redactions` entry recording
	 * `field` / `before` / `after` / `ground`. Persists once (audited). It does
	 * NOT call the erase pseudonymise path.
	 *
	 * @param string $caseUuid The case object uuid.
	 * @param string $field The case field being redacted.
	 * @param string $after The redacted replacement value.
	 * @param string $ground The generic ground key the redaction was applied under.
	 *
	 * @return array{caseUuid: string, field: string, before: string, after: string, ground: string, recordType: string}
	 *
	 * @throws RuntimeException When the case cannot be loaded (absent or unauthorised).
	 *
	 * @spec openspec/specs/dsar-redaction-write/spec.md
	 */
	public function applyRedaction(string $caseUuid, string $field, string $after, string $ground): array;
}//end interface
