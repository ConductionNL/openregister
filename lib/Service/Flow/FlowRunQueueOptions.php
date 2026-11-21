<?php

/**
 * FlowRunQueueOptions: what a queued run is about, what started it and who caused it.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/flow-runs/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

/**
 * The optional parts of queueing a run, with the defaults queue() applies.
 *
 * @spec openspec/specs/flow-runs/spec.md
 */
final class FlowRunQueueOptions {

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $subject `{uuid, register, schema}` of the object.
	 * @param string               $trigger What caused this run.
	 * @param array<string, mixed> $context Run-level metadata.
	 * @param string|null          $user    The user whose action caused it.
	 */
	public function __construct(
		public readonly array $subject = [],
		public readonly string $trigger = 'manual',
		public readonly array $context = [],
		public readonly ?string $user = null,
	) {
	}//end __construct()
}//end class
