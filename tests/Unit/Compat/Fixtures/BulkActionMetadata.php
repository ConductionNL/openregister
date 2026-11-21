<?php

/**
 * The metadata half of a bulk action, shared by the bulk action fixtures.
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

trait BulkActionMetadata {
	public function getId(): string {
		return 'fixture:action';
	}

	public function getLabel(): string {
		return 'Fixture';
	}

	public function getDescription(): string {
		return 'Fixture';
	}

	public function requiresJustification(): bool {
		return false;
	}

	public function getGuards(): array {
		return [];
	}

	public function validateParameters(array $parameters): void {
	}
}
