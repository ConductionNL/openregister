<?php

/**
 * A released-form provider extending AbstractIntegrationProvider and overriding only list(), as integriq does.
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

use OCA\OpenRegister\Service\Integration\AbstractIntegrationProvider;

class ReleasedFormSubclassProvider extends AbstractIntegrationProvider {
	public function getId(): string {
		return 'fixture-subclass';
	}

	public function getLabel(): string {
		return 'Fixture';
	}

	public function getIcon(): string {
		return '';
	}

	public function getRequiredApp(): ?string {
		return null;
	}

	public function getStorageStrategy(): string {
		return 'query-time';
	}

	public function isEnabled(): bool {
		return true;
	}

	public function list(string $register, string $schema, string $objectId, array $filters = []): array {
		return [[$register, $schema, $objectId, $filters]];
	}
}
