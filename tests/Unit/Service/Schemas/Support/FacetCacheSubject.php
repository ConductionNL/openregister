<?php

/**
 * Runs a schema change and the admin facet clear over a given cache factory.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Schemas\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Schemas\Support;

use OCA\OpenRegister\Service\Schemas\FacetResultCacheClearer;
use OCA\OpenRegister\Service\Schemas\SchemaCacheHandler;
use OCA\OpenRegister\Service\Settings\CacheSettingsHandler;
use OCA\OpenRegister\Service\Settings\SchemaCacheClearers;
use OCP\ICacheFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The facet cache paths of the schema and settings code, over one factory.
 */
class FacetCacheSubject {

	/**
	 * The facet result cache clearer under test.
	 *
	 * @var FacetResultCacheClearer
	 */
	private FacetResultCacheClearer $clearer;

	/**
	 * Build the subject.
	 *
	 * @param TestCase      $test    The test, for mocks.
	 * @param ICacheFactory $factory The recording cache factory.
	 */
	public function __construct(private readonly TestCase $test, private readonly ICacheFactory $factory) {
		$this->clearer = new FacetResultCacheClearer($factory, new NullLogger());
	}//end __construct()

	/**
	 * A schema is updated.
	 *
	 * @return null
	 */
	public function schemaChange(): mixed {
		$this->clearer->clearAll();
		return null;
	}//end schemaChange()

	/**
	 * The admin clears the facet cache.
	 *
	 * @return array<string, mixed> The response.
	 */
	public function adminClear(): array {
		$settings = new CacheSettingsHandler($this->factory, new SchemaCacheClearers($this->mock(SchemaCacheHandler::class), $this->clearer));
		return $settings->clearCache(type: 'facet');
	}//end adminClear()

	/**
	 * A mock of a class.
	 *
	 * @param class-string $class The class.
	 *
	 * @return object The mock.
	 */
	private function mock(string $class): object {
		return $this->test->getMockBuilder($class)->disableOriginalConstructor()->getMock();
	}//end mock()
}//end class
