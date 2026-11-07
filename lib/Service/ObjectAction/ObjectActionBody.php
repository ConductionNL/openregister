<?php

/**
 * What a declared action runs, and how.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ObjectAction
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/object-actions-declared-and-run/specs/declared-actions/spec.md#requirement-a-declared-action-may-carry-inputs-a-precondition-surfaces-and-one-body
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ObjectAction;

/**
 * The body kind and body of one declared action, with its run mode and `next` hint.
 *
 * @spec openspec/changes/object-actions-declared-and-run/specs/declared-actions/spec.md#requirement-a-declared-action-may-carry-inputs-a-precondition-surfaces-and-one-body
 */
final class ObjectActionBody {

	/**
	 * Constructor.
	 *
	 * @param string      $mode `sync` or `async`.
	 * @param string|null $next The declared `next` hint, or null.
	 * @param string|null $kind The body kind, or null for a bodiless action.
	 * @param mixed       $body The body: a transition key, a flow name or uuid, or a step list.
	 */
	public function __construct(
		public readonly string $mode,
		public readonly ?string $next,
		public readonly ?string $kind,
		public readonly mixed $body,
	) {
	}//end __construct()
}//end class
