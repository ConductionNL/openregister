<?php

/**
 * OpenRegister TransitionRequest
 *
 * One delegated transition a caller asked for: the object, the action, its payload and the path it runs on.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
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
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Lifecycle;

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * One delegated transition a caller asked for.
 *
 * The object, the posted action, the caller's payload and whether the move
 * runs on the system path travel together through the graph- and
 * provider-mode helpers of {@see TransitionEngine}. `asSystem` is set only by
 * {@see TransitionEngine::transitionAsSystem()}; the payload can never set it.
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
final class TransitionRequest {

	/**
	 * Name the request.
	 *
	 * @param ObjectEntity         $object   The transitioning object, as it stood before the move.
	 * @param string               $action   The action name the caller posted.
	 * @param array<string, mixed> $data     The caller-supplied input values.
	 * @param bool                 $asSystem True on the system path: the write or re-read skips RBAC
	 *                                       and the organisation filter.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function __construct(
		public readonly ObjectEntity $object,
		public readonly string $action,
		public readonly array $data,
		public readonly bool $asSystem,
	) {
	}//end __construct()
}//end class
