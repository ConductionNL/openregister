<?php

/**
 * A lifecycle action written against the LifecycleActionContext signature, implementing only the base interface.
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

use OCA\OpenRegister\Lifecycle\LifecycleActionContext;
use OCA\OpenRegister\Lifecycle\LifecycleActionInterface;

class ContextFormLifecycleAction implements LifecycleActionInterface {
	public ?LifecycleActionContext $received = null;

	public function execute(LifecycleActionContext $context): array {
		$this->received = $context;
		return $context->objectData + ['form' => 'context'];
	}
}
