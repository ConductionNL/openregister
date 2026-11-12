<?php

/**
 * OpenRegister AutoTransitionSubject
 *
 * The object an automatic move is decided for: its uuid, the register and
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
 * Which object a decision is about, as {@see AutoTransitionDecision} exposes it.
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
final class AutoTransitionSubject {

	/**
	 * Capture the object's identity.
	 *
	 * @param string $uuid       The object's uuid.
	 * @param string $register   The object's register reference, as recorded off the event.
	 * @param string $schema     The object's schema reference, as recorded off the event.
	 * @param string $schemaSlug The schema's slug, for log lines.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function __construct(
		public readonly string $uuid,
		public readonly string $register,
		public readonly string $schema,
		public readonly string $schemaSlug,
	) {
	}//end __construct()
}//end class
