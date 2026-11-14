<?php

/**
 * The metadata half of an integration provider, shared by the provider fixtures.
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

trait IntegrationProviderMetadata {
	public function getId(): string {
		return 'fixture';
	}

	public function getLabel(): string {
		return 'Fixture';
	}

	public function getIcon(): string {
		return '';
	}

	public function getGroup(): ?string {
		return null;
	}

	public function getRequiredApp(): ?string {
		return null;
	}

	public function getStorageStrategy(): string {
		return 'app-local';
	}

	public function getOpenConnectorSource(): ?string {
		return null;
	}

	public function isEnabled(): bool {
		return true;
	}

	public function requiresPermission(): ?string {
		return null;
	}

	public function authRequirements(): array {
		return ['type' => 'none'];
	}

	public function health(): array {
		return ['status' => 'ok'];
	}
}
