<?php

/**
 * OpenRegister StateSave
 *
 * A save judged against the lifecycle states: the annotation that declares
 * them and the object as it would be saved.
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
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Lifecycle;

/**
 * The lifecycle annotation and the object as it would be saved (ADR-011 Rule 2).
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
final class StateSave {

	/**
	 * Build the save.
	 *
	 * @param array<string, mixed> $annotation The lifecycle annotation.
	 * @param array<string, mixed> $newData    The object as it would be saved.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function __construct(
		public readonly array $annotation,
		public readonly array $newData,
	) {
	}//end __construct()
}//end class
