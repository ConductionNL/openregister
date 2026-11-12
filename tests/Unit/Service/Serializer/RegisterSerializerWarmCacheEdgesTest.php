<?php

/**
 * Characterisation: the edges of RegisterSerializer's schema-cache warm-up.
 *
 * Supplements RegisterSerializerWarmCacheTest with the cases it leaves out: a
 * register without schemas, numeric-string ids, ids already cached, and a page
 * whose every id is cached or non-numeric, which must not query at all.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Serializer
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Serializer;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Serializer\RegisterSerializer;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use ReflectionProperty;

/**
 * Edges of RegisterSerializer::warmSchemaCache().
 */
class RegisterSerializerWarmCacheEdgesTest extends TestCase {

	/**
	 * A register holding the given schema ids.
	 *
	 * @param array|null $schemaIds The schema ids.
	 *
	 * @return Register The register.
	 */
	private function register(?array $schemaIds): Register {
		$register = new Register();
		if ($schemaIds !== null) {
			$register->setSchemas($schemaIds);
		}

		return $register;
	}//end register()

	/**
	 * Warm the cache of a serializer, starting from the given cache.
	 *
	 * @param SchemaMapper $mapper    The mapper.
	 * @param array        $cache     The cache before.
	 * @param Register[]   $registers The page.
	 * @param string[]     $extend    The extensions.
	 *
	 * @return array The cache after.
	 */
	private function warm(SchemaMapper $mapper, array $cache, array $registers, array $extend): array {
		$serializer = new RegisterSerializer($mapper, $this->createMock(LoggerInterface::class));
		$property   = new ReflectionProperty(RegisterSerializer::class, 'schemaCache');
		$property->setValue($serializer, $cache);
		(new ReflectionMethod(RegisterSerializer::class, 'warmSchemaCache'))->invoke($serializer, $registers, $extend);

		return $property->getValue($serializer);
	}//end warm()

	/**
	 * Only numeric, uncached ids are fetched, once each, as integers in first-seen order.
	 *
	 * @return void
	 */
	public function testOnlyNumericUncachedIdsAreFetched(): void {
		$cached = new Schema();
		$fresh  = new Schema();
		$mapper = $this->createMock(SchemaMapper::class);
		$mapper->expects($this->once())->method('findMultipleOptimized')->with([7, 3])->willReturn([7 => $fresh, 3 => $fresh]);

		$cache = $this->warm(
			mapper: $mapper,
			cache: ['5' => $cached],
			registers: [$this->register(null), $this->register(['7', 'slug', 5, 3]), $this->register([3, '7'])],
			extend: ['schemas']
		);

		$this->assertSame(['5', '7', '3'], array_map('strval', array_keys($cache)));
		$this->assertSame($cached, $cache['5']);
		$this->assertSame($fresh, $cache['7']);
	}//end testOnlyNumericUncachedIdsAreFetched()

	/**
	 * A page with nothing left to fetch makes no query.
	 *
	 * @return void
	 */
	public function testNothingToFetchMakesNoQuery(): void {
		$mapper = $this->createMock(SchemaMapper::class);
		$mapper->expects($this->never())->method('findMultipleOptimized');

		$cache = $this->warm(
			mapper: $mapper,
			cache: ['5' => new Schema()],
			registers: [$this->register(null), $this->register(['slug', 5]), $this->register([])],
			extend: ['schemas', 'stats']
		);

		$this->assertSame(['5'], array_map('strval', array_keys($cache)));
	}//end testNothingToFetchMakesNoQuery()

	/**
	 * Without the `schemas` extension nothing is fetched, whatever the page holds.
	 *
	 * @return void
	 */
	public function testWithoutTheSchemasExtensionNothingIsFetched(): void {
		$mapper = $this->createMock(SchemaMapper::class);
		$mapper->expects($this->never())->method('findMultipleOptimized');

		$this->assertSame([], $this->warm(mapper: $mapper, cache: [], registers: [$this->register([1])], extend: ['stats']));
	}//end testWithoutTheSchemasExtensionNothingIsFetched()
}//end class
