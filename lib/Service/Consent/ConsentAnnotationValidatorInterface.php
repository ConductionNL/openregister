<?php

/**
 * ConsentAnnotationValidatorInterface: what callers outside the component use of ConsentAnnotationValidator.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Consent
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

namespace OCA\OpenRegister\Service\Consent;

/**
 * Declares only what callers outside the component use of ConsentAnnotationValidator; the rest
 * of the class stays its own.
 */
interface ConsentAnnotationValidatorInterface {

	/**
	 * Validate every `x-openregister-consent` annotation declared on a schema's properties.
	 *
	 * @param array<string, mixed> $schema Full schema (must include `properties`).
	 *
	 * @return array<int, array{code: string, message: string}>
	 *
	 * @spec openspec/specs/consent-evidence-envelope/spec.md
	 */
	public function validate(array $schema): array;
}//end interface
