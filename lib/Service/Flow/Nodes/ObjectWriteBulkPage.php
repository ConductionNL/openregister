<?php

/**
 * One page of items an object-write step sends through one bulk save.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow\Nodes
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/flow-object-write-bulk/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow\Nodes;

/**
 * The rows of a bulk page, keyed by the index of the item they came from.
 *
 * An item that is skipped has no row and no id: it is not written, but it is
 * still emitted, because dropping it is what would make a later sweep delete it.
 */
final class ObjectWriteBulkPage {

	/**
	 * The payloads to write, each carrying its `id`, by item index.
	 *
	 * @var array<int|string, array<string, mixed>>
	 */
	public array $rows = [];

	/**
	 * The serialised rows the save returned, by uuid.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	public array $written = [];

	/**
	 * The result of the bulk save.
	 *
	 * @var array<string, mixed>
	 */
	public array $result = [];

	/**
	 * Constructor.
	 *
	 * @param array           $items The input items.
	 * @param ObjectWritePlan $plan  The step's resolved plan.
	 */
	public function __construct(
		public readonly array $items,
		public readonly ObjectWritePlan $plan,
	) {
	}//end __construct()
}//end class
