<?php

/**
 * The object-source registry's listing: every registered provider, in
 * registration order, enabled or not, with a duplicate id kept once.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\ObjectSource
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ObjectSource;

use OCA\OpenRegister\Service\ObjectSource\ObjectSourceProvider;
use OCA\OpenRegister\Service\ObjectSource\ObjectSourceRegistry;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Listing tests for {@see ObjectSourceRegistry}.
 */
class ObjectSourceRegistryListTest extends TestCase {

	/**
	 * A provider with the given id and enabled state.
	 *
	 * @param string $id The id.
	 * @param bool $enabled Whether it is enabled.
	 *
	 * @return ObjectSourceProvider The provider.
	 */
	private function provider(string $id, bool $enabled): ObjectSourceProvider {
		$provider = $this->createMock(ObjectSourceProvider::class);
		$provider->method('getId')->willReturn($id);
		$provider->method('isEnabled')->willReturn($enabled);

		return $provider;
	}//end provider()

	/**
	 * Listing returns every provider in order, a disabled one included, and
	 * the first of two with the same id.
	 *
	 * @return void
	 */
	public function testListReturnsEveryProviderOnceInOrder(): void {
		$tables = $this->provider(id: 'tables', enabled: true);
		$deck = $this->provider(id: 'deck', enabled: false);
		$registry = new ObjectSourceRegistry(logger: new NullLogger());
		$registry->withProviders(providers: [$tables, $deck, $this->provider(id: 'tables', enabled: true)]);

		$this->assertSame([$tables, $deck], $registry->list());
		$this->assertSame([], (new ObjectSourceRegistry(logger: new NullLogger()))->list());
	}//end testListReturnsEveryProviderOnceInOrder()
}//end class
