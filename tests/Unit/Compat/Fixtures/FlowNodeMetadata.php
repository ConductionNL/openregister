<?php

/**
 * The metadata half of a flow node, shared by the flow node fixtures.
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

trait FlowNodeMetadata {
	public function getId(): string {
		return 'fixture:node';
	}

	public function getDisplayName(): string {
		return 'Fixture';
	}

	public function getDescription(): string {
		return 'Fixture';
	}

	public function getIcon(): string {
		return '';
	}

	public function isAvailableForScope(int $scope): bool {
		return true;
	}

	public function validateConfig(array $config): void {
	}
}
