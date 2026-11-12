<?php

/**
 * OpenRegister ConsentSaveContext
 *
 * One save as the consent envelope reads it: the event that can refuse it, the
 * schema's consent properties and the stored data they are compared against.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Listener
 * @package  OCA\OpenRegister\Listener\Support
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

namespace OCA\OpenRegister\Listener\Support;

use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;

/**
 * A save as the consent envelope reads it.
 *
 * @spec openspec/specs/consent-evidence-envelope/spec.md
 */
final class ConsentSaveContext {

	/**
	 * Constructor.
	 *
	 * @param ObjectCreatingEvent|ObjectUpdatingEvent $event      The event that can refuse the save.
	 * @param array<string, array<string, mixed>>     $properties Each consent property's annotation, by property name.
	 * @param array<string, mixed>                    $incoming   The object data as it would be saved.
	 * @param array<string, mixed>                    $persisted  The object data as stored; empty on a create.
	 */
	public function __construct(
		public readonly ObjectCreatingEvent|ObjectUpdatingEvent $event,
		public readonly array $properties,
		public readonly array $incoming,
		public readonly array $persisted,
	) {
	}//end __construct()
}//end class
