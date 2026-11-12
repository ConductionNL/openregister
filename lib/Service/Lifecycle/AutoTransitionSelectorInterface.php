<?php

/**
 * AutoTransitionSelectorInterface: what callers outside the component use of AutoTransitionSelector.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Lifecycle
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

namespace OCA\OpenRegister\Service\Lifecycle;

/**
 * Whether a schema declares automatic lifecycle transitions.
 *
 * Declares only what callers outside the component use; the rest of the
 * class stays its own.
 */
interface AutoTransitionSelectorInterface {

	/**
	 * Whether the schema's annotation declares any `autoWhen` at all.
	 *
	 * The recording listener asks this on every write, so it is a shape read
	 * over the annotation and never an evaluation.
	 *
	 * @param array<string, mixed> $annotation The schema's x-openregister-lifecycle block.
	 *
	 * @return bool True when at least one transition declares `autoWhen`.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function declaresAutoWhen(array $annotation): bool;
}//end interface
