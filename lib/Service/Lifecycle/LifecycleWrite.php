<?php

/**
 * OpenRegister LifecycleWrite
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
 * The write a lifecycle rule is asked about: the object after and before it,
 * the schema it belongs to, and the lifecycle field that moves.
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
final class LifecycleWrite {
	/**
	 * Name the write.
	 *
	 * @param array<string, mixed> $newData    The object as it would be saved, or as stored.
	 * @param array<string, mixed> $oldData    The object as it was before the write.
	 * @param string               $schemaSlug The schema's slug, for the log line.
	 * @param string               $field      The lifecycle field's name.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function __construct(
		public readonly array $newData,
		public readonly array $oldData,
		public readonly string $schemaSlug,
		public readonly string $field,
	) {
	}//end __construct()
}//end class
