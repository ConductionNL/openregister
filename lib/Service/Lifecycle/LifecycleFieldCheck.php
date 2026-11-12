<?php

/**
 * LifecycleFieldCheck: the lifecycle `field` must be named and declared.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Lifecycle
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Lifecycle;

/**
 * The `field` check shared by the modes whose field declares no enum (graph, provider).
 */
trait LifecycleFieldCheck {

	/**
	 * The errors for a `field` that is missing, empty or not declared in `properties`.
	 *
	 * @param array<string, mixed> $annotation The normalised annotation block.
	 * @param array<string, mixed> $schema     Full schema definition.
	 *
	 * @return array<int, array{code: string, message: string}> List of errors (empty = valid).
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function declaredFieldErrors(array $annotation, array $schema): array {
		$field = ($annotation['field'] ?? null);
		if (is_string($field) === false || $field === '') {
			return [['code' => 'lifecycle-missing-key', 'message' => 'x-openregister-lifecycle is missing required key "field".']];
		}

		$properties = ($schema['properties'] ?? []);
		if (is_array($properties) === true && isset($properties[$field]) === false) {
			$message = sprintf('x-openregister-lifecycle.field "%s" is not declared in `properties`.', $field);
			return [['code' => 'lifecycle-field-missing', 'message' => $message]];
		}

		return [];
	}//end declaredFieldErrors()
}//end trait
