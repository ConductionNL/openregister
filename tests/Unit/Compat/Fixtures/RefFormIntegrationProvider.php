<?php

/**
 * An integration provider written against the ObjectRef signatures, implementing only IntegrationProvider.
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

use OCA\OpenRegister\Kernel\Contracts\ObjectRef;
use OCA\OpenRegister\Service\Integration\IntegrationProvider;

class RefFormIntegrationProvider implements IntegrationProvider {
	use IntegrationProviderMetadata;

	/** @var array<string, list<mixed>> */
	public array $received = [];

	public function list(ObjectRef $ref, array $filters = []): array {
		$this->received['list'] = [$ref, $filters];
		return [['form' => 'ref']];
	}

	public function get(ObjectRef $ref, string $entityId): array {
		$this->received['get'] = [$ref, $entityId];
		return ['form' => 'ref'];
	}

	public function create(ObjectRef $ref, array $payload): array {
		$this->received['create'] = [$ref, $payload];
		return ['form' => 'ref'];
	}

	public function update(ObjectRef $ref, string $entityId, array $payload): array {
		$this->received['update'] = [$ref, $entityId, $payload];
		return ['form' => 'ref'];
	}

	public function delete(ObjectRef $ref, string $entityId): void {
		$this->received['delete'] = [$ref, $entityId];
	}
}
