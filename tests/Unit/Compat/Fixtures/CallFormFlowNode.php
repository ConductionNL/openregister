<?php

/**
 * A flow node written against the FlowNodeCall signature, implementing only IFlowNode.
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

use OCA\OpenRegister\Service\Flow\FlowNodeCall;
use OCA\OpenRegister\Service\Flow\IFlowNode;

class CallFormFlowNode implements IFlowNode {
	use FlowNodeMetadata;

	public ?FlowNodeCall $received = null;

	public function execute(FlowNodeCall $call): array {
		$this->received = $call;
		return [['form' => 'call']];
	}
}
