<?php

/**
 * ProblemDetailsBuilderInterface: ProblemDetailsBuilder as the OAS validation middleware reaches it.
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
 * ProblemDetailsBuilder, typed for callers outside the Service component.
 *
 * Its outside caller, the OAS validation middleware, calls validationFailed() and reads
 * CONTENT_TYPE, so those are the whole contract. Binding it in ContractBindings lets that caller resolve
 * ProblemDetailsBuilder without naming the class.
 */
interface ProblemDetailsBuilderInterface {
	/**
	 * The media type of a problem document.
	 *
	 * @var string
	 */
	public const CONTENT_TYPE = 'application/problem+json';

	/**
	 * Wrap a list of validation errors as a 422 problem document.
	 *
	 * @param array              $errors      List of validation errors (each free-form, typically `{path, message}`).
	 * @param ProblemDescription $description The optional explanation and instance URI ('' = omit); its type is ignored.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw-svc-mid2/tasks.md#task-10
	 */
	public function validationFailed(array $errors, ProblemDescription $description = new ProblemDescription()): array;
}//end interface
