<?php

/**
 * A lifecycle action written against the released positional signature, as fleet apps ship it.
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

use OCA\OpenRegister\Lifecycle\LifecycleActionInterface;

class ReleasedFormLifecycleAction implements LifecycleActionInterface {
	/** @var list<mixed> */
	public array $received = [];

	public function execute(array $objectData, array $previousData, array $parameters, string $actionName): array {
		$this->received = [$objectData, $previousData, $parameters, $actionName];
		return $objectData + ['form' => 'released'];
	}
}
