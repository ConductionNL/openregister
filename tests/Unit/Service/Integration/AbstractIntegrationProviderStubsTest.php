<?php

/**
 * Characterisation test for the AbstractIntegrationProvider CRUD stubs: a
 * provider that does not override get/create/update/delete answers each with
 * a NotImplementedException naming the provider and the operation.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Integration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Integration;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers take positional args by convention.

use OCA\OpenRegister\Exception\NotImplementedException;
use OCA\OpenRegister\Service\Integration\AbstractIntegrationProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pins the messages of the default CRUD stubs.
 */
class AbstractIntegrationProviderStubsTest extends TestCase {

	/**
	 * A list-only provider with the id `probe`.
	 *
	 * @return AbstractIntegrationProvider
	 */
	private function provider(): AbstractIntegrationProvider {
		return new class extends AbstractIntegrationProvider {
			public function getId(): string {
				return 'probe';
			}

			public function getLabel(): string {
				return 'Probe';
			}

			public function getIcon(): string {
				return 'Probe';
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
				return [];
			}
		};
	}//end provider()

	/**
	 * The message each stub answers with, or '' when it answered nothing.
	 *
	 * @param callable $call The stub call.
	 *
	 * @return string
	 */
	private function messageOf(callable $call): string {
		try {
			$call();
		} catch (NotImplementedException $e) {
			return $e->getMessage();
		}

		return '';
	}//end messageOf()

	public function testEveryStubNamesTheProviderAndTheOperation(): void {
		$provider = $this->provider();

		$this->assertSame(
			[
				'Provider probe does not support get()',
				'Provider probe does not support create()',
				'Provider probe does not support update()',
				'Provider probe does not support delete()',
			],
			[
				$this->messageOf(call: static fn () => $provider->get('r', 's', 'o', 'e')),
				$this->messageOf(call: static fn () => $provider->create('r', 's', 'o', [])),
				$this->messageOf(call: static fn () => $provider->update('r', 's', 'o', 'e', [])),
				$this->messageOf(call: static fn () => $provider->delete('r', 's', 'o', 'e')),
			]
		);
	}//end testEveryStubNamesTheProviderAndTheOperation()
}//end class
