<?php

/**
 * OpenRegister AutoTransitionWrite
 *
 * The write an automatic transition is selected against.
 * schema references recorded off the event, and the schema's slug.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Lifecycle
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

namespace OCA\OpenRegister\Service\Lifecycle;

/**
 * One triggering write as the selector reads it: the object after and before
 * the write, and the schema's slug for the log lines.
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
final class AutoTransitionWrite {

	/**
	 * Capture the write.
	 *
	 * @param array<string, mixed> $objectData The object as stored, after the triggering write.
	 * @param array<string, mixed> $previous   The object as it was before the triggering write.
	 * @param string               $schemaSlug The schema's slug, for the log lines.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function __construct(
		public readonly array $objectData,
		public readonly array $previous,
		public readonly string $schemaSlug,
	) {
	}//end __construct()
}//end class
