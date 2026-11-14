<?php

/**
 * An integration provider written against the released (register, schema, objectId, ...) signatures.
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

use OCA\OpenRegister\Service\Integration\IntegrationProvider;

class ReleasedFormIntegrationProvider implements IntegrationProvider {
	use IntegrationProviderMetadata;

	/** @var array<string, list<mixed>> */
	public array $received = [];

	public function list(string $register, string $schema, string $objectId, array $filters = []): array {
		$this->received['list'] = [$register, $schema, $objectId, $filters];
		return [['form' => 'released']];
	}

	public function get(string $register, string $schema, string $objectId, string $entityId): array {
		$this->received['get'] = [$register, $schema, $objectId, $entityId];
		return ['form' => 'released'];
	}

	public function create(string $register, string $schema, string $objectId, array $payload): array {
		$this->received['create'] = [$register, $schema, $objectId, $payload];
		return ['form' => 'released'];
	}

	public function update(string $register, string $schema, string $objectId, string $entityId, array $payload): array {
		$this->received['update'] = [$register, $schema, $objectId, $entityId, $payload];
		return ['form' => 'released'];
	}

	public function delete(string $register, string $schema, string $objectId, string $entityId): void {
		$this->received['delete'] = [$register, $schema, $objectId, $entityId];
	}
}
