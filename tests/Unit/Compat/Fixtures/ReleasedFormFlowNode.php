<?php

/**
 * A flow node written against the released (items, config, context) signature, as fleet apps ship it.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Compat\Fixtures
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Compat\Fixtures;

use OCA\OpenRegister\Service\Flow\IFlowNode;

class ReleasedFormFlowNode implements IFlowNode {
	use FlowNodeMetadata;

	/** @var list<mixed> */
	public array $received = [];

	public function execute(array $items, array $config, array $context): array {
		$this->received = [$items, $config, $context];
		return [['form' => 'released']];
	}
}
