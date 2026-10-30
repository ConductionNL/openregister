<?php

/**
 * The search provider registrar registers both unified search providers.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Search;

use OCA\OpenRegister\Search\SearchProviderRegistrar;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use PHPUnit\Framework\TestCase;

class SearchProviderRegistrarTest extends TestCase {

	/**
	 * register() registers the objects provider and the timeline entries provider, and no service.
	 *
	 * @return void
	 */
	public function testRegistersBothSearchProviders(): void {
		$registered = [];
		$context    = $this->createMock(originalClassName: IRegistrationContext::class);
		$context->method('registerSearchProvider')->willReturnCallback(
			function (string $class) use (&$registered): void {
				$registered[] = $class;
			}
		);
		$context->expects($this->never())->method('registerService');

		SearchProviderRegistrar::register(context: $context);

		$this->assertSame(
			expected: [
				'OCA\OpenRegister\Search\ObjectsProvider',
				'OCA\OpenRegister\Search\TimelineEntriesProvider',
			],
			actual: $registered
		);
	}//end testRegistersBothSearchProviders()
}//end class
