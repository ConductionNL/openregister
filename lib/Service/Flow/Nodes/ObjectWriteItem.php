<?php

/**
 * One item an object-write step writes.
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
 * @spec openspec/changes/or-flow-object-write-node/specs/flow-object-write-node/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow\Nodes;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Flow\FlowItems;

/**
 * An input item's json, binary and position, with the payload built from it
 * and the stored object it matched.
 *
 * @spec openspec/changes/or-flow-object-write-node/specs/flow-object-write-node/spec.md
 */
final class ObjectWriteItem
{

	/**
	 * The item's json.
	 *
	 * @var array
	 */
	public readonly array $json;

	/**
	 * The item's binary.
	 *
	 * @var array
	 */
	public readonly array $binary;

	/**
	 * The payload built for the write.
	 *
	 * @var array
	 */
	public array $payload = [];

	/**
	 * The stored object the item matched, if any.
	 *
	 * @var ObjectEntity|null
	 */
	public ?ObjectEntity $matched = null;

	/**
	 * Constructor.
	 *
	 * @param array $item  The flow item.
	 * @param int   $index The item's position in the input.
	 */
	public function __construct(array $item, public readonly int $index) {
		$this->json = (array)($item[FlowItems::JSON] ?? []);
		$this->binary = (array)($item[FlowItems::BINARY] ?? []);
	}//end __construct()

}//end class
