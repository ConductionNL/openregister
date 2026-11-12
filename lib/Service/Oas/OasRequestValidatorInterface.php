<?php

/**
 * OasRequestValidatorInterface: OasRequestValidator as the OAS validation middleware reaches it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Oas
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

namespace OCA\OpenRegister\Service\Oas;

/**
 * OasRequestValidator, typed for callers outside the Service component.
 *
 * Its outside caller, the OAS validation middleware, calls validate(), so that is
 * the whole contract. Binding it in ContractBindings lets that caller resolve
 * OasRequestValidator without naming the class.
 */
interface OasRequestValidatorInterface {
	/**
	 * Validate `$body` against `$schema`. Returns the list of errors —
	 * empty array on success. Each error is `{ path, message }`.
	 *
	 * @param mixed $body The decoded request body (array / scalar / null).
	 * @param array $schema The JSON-Schema to validate against (decoded).
	 *
	 * @return array<int, array{path: string, message: string}>
	 *
	 * @spec openspec/specs/oas-validation/spec.md#requirement-request-validation-against-oas-schema
	 */
	public function validate(mixed $body, array $schema): array;
}//end interface
