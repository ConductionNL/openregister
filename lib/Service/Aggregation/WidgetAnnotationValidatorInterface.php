<?php

/**
 * WidgetAnnotationValidatorInterface: what callers outside the component use of WidgetAnnotationValidator.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Aggregation
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

namespace OCA\OpenRegister\Service\Aggregation;

/**
 * Declares only what callers outside the component use of WidgetAnnotationValidator; the rest
 * of the class stays its own.
 */
interface WidgetAnnotationValidatorInterface {

	/**
	 * Validate the widget annotation.
	 *
	 * @param array<string, mixed> $schema Full schema definition.
	 *
	 * @return array<int, array{code: string, message: string}>
	 *
	 * @spec openspec/specs/aggregations-backend-native/spec.md
	 */
	public function validate(array $schema): array;
}//end interface
