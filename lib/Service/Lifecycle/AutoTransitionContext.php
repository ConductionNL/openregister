<?php

/**
 * OpenRegister AutoTransitionContext
 *
 * The write one automatic-transition selection is asked about: the lifecycle
 * field and its current value, the declared transitions, the object before
 * and after the write, and the schema for the log lines.
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
 * What one {@see AutoTransitionSelector::select()} call reads.
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
final class AutoTransitionContext {

	/**
	 * The object as stored, after the triggering write.
	 *
	 * @var array<string, mixed>
	 */
	public readonly array $objectData;

	/**
	 * The object as it was before the triggering write.
	 *
	 * @var array<string, mixed>
	 */
	public readonly array $previous;

	/**
	 * The schema's slug, for the log lines.
	 *
	 * @var string
	 */
	public readonly string $schemaSlug;

	/**
	 * Constructor.
	 *
	 * @param string               $field       The lifecycle field's name.
	 * @param string               $current     The object's current lifecycle value.
	 * @param array<mixed>         $transitions The annotation's transition map, in declaration order.
	 * @param array<string, mixed> $write       The object after (`objectData`) and before (`previous`) the write,
	 *                                          and the schema's slug (`schemaSlug`).
	 *
	 * @phpstan-param array{objectData: array<string, mixed>, previous: array<string, mixed>, schemaSlug: string} $write
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function __construct(
		public readonly string $field,
		public readonly string $current,
		public readonly array $transitions,
		array $write,
	) {
		$this->objectData = $write['objectData'];
		$this->previous   = $write['previous'];
		$this->schemaSlug = $write['schemaSlug'];
	}//end __construct()
}//end class
