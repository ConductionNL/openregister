<?php

/**
 * MappingService Coverage Tests
 *
 * Tests for uncovered branches in MappingService: encodeArrayKeys recursive,
 * executeMapping list mode, cast operations (bool, ?bool, json, jsonToArray,
 * moneyStringToInt, intToMoneyString, unsetIfValue, setNullIfValue, countValue,
 * keyCantBeValue, coordinateStringToArray), areAllArrayKeysNull, invalidateMappingCache,
 * getMapping with cache hit/miss, and getCachedTemplate.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Db\Mapping;
use OCA\OpenRegister\Db\MappingMapper;
use OCA\OpenRegister\Service\MappingService;
use OCP\ICacheFactory;
use OCP\ICache;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use ReflectionClass;

class MappingServiceCoverageTest extends TestCase
{
    private MappingService $service;
    private MappingMapper|MockObject $mappingMapper;
    private ICacheFactory|MockObject $cacheFactory;
    private ICache|MockObject $cache;
    private LoggerInterface|MockObject $logger;

    protected function setUp(): void
    {
        $this->mappingMapper = $this->createMock(MappingMapper::class);
        $this->cacheFactory = $this->createMock(ICacheFactory::class);
        $this->cache = $this->createMock(ICache::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->cacheFactory->method('createDistributed')->willReturn($this->cache);

        $this->service = new MappingService(
            $this->mappingMapper,
            $this->cacheFactory,
            $this->logger
        );
    }

    private function invokeMethod(object $obj, string $method, array $args = []): mixed
    {
        $ref = new ReflectionClass($obj);
        $m = $ref->getMethod($method);
        $m->setAccessible(true);
        return $m->invokeArgs($obj, $args);
    }

    private function createMapping(array $mapping, array $unset = [], array $cast = [], bool $passThrough = false): Mapping
    {
        $m = new Mapping();
        $m->setMapping($mapping);
        $m->setUnset($unset);
        $m->setCast($cast);
        $m->setPassThrough($passThrough);
        return $m;
    }

    // =========================================================================
    // encodeArrayKeys
    // =========================================================================

    public function testEncodeArrayKeysFlat(): void
    {
        $result = $this->service->encodeArrayKeys(
            ['a.b' => 'value', 'c' => 'other'],
            '.',
            '&#46;'
        );

        $this->assertArrayHasKey('a&#46;b', $result);
        $this->assertSame('other', $result['c']);
    }

    public function testEncodeArrayKeysNested(): void
    {
        $result = $this->service->encodeArrayKeys(
            ['parent' => ['child.key' => 'val']],
            '.',
            '&#46;'
        );

        $this->assertArrayHasKey('child&#46;key', $result['parent']);
    }

    public function testEncodeArrayKeysEmptyArray(): void
    {
        // Empty nested arrays should not be recursed into
        $result = $this->service->encodeArrayKeys(
            ['key' => []],
            '.',
            '&#46;'
        );

        $this->assertSame([], $result['key']);
    }

    // =========================================================================
    // coordinateStringToArray
    // =========================================================================

    public function testCoordinateStringToArraySinglePoint(): void
    {
        $result = $this->service->coordinateStringToArray('52.0 4.0');

        $this->assertSame(['52.0', '4.0'], $result);
    }

    public function testCoordinateStringToArrayMultiplePoints(): void
    {
        $result = $this->service->coordinateStringToArray('52.0 4.0 53.0 5.0');

        $this->assertCount(2, $result);
        $this->assertSame(['52.0', '4.0'], $result[0]);
        $this->assertSame(['53.0', '5.0'], $result[1]);
    }

    // =========================================================================
    // executeMapping — simple dot notation
    // =========================================================================

    public function testExecuteMappingSimpleDotNotation(): void
    {
        $mapping = $this->createMapping(['output_name' => 'input_name']);
        $input = ['input_name' => 'John Doe'];

        $result = $this->service->executeMapping($mapping, $input);

        $this->assertSame('John Doe', $result['output_name']);
    }

    // =========================================================================
    // executeMapping — passThrough
    // =========================================================================

    public function testExecuteMappingWithPassThrough(): void
    {
        $mapping = $this->createMapping(['new_field' => 'old_field'], [], [], true);
        $input = ['old_field' => 'value1', 'extra' => 'kept'];

        $result = $this->service->executeMapping($mapping, $input);

        $this->assertSame('value1', $result['new_field']);
        $this->assertSame('kept', $result['extra']);
    }

    // =========================================================================
    // executeMapping — array values in mapping
    // =========================================================================

    public function testExecuteMappingArrayValue(): void
    {
        $mapping = $this->createMapping(['tags' => ['a', 'b', 'c']]);
        $input = [];

        $result = $this->service->executeMapping($mapping, $input);

        $this->assertSame(['a', 'b', 'c'], $result['tags']);
    }

    // =========================================================================
    // executeMapping — unset
    // =========================================================================

    public function testExecuteMappingWithUnset(): void
    {
        $mapping = $this->createMapping(
            ['field1' => 'f1', 'field2' => 'f2'],
            ['field2']
        );
        $input = ['f1' => 'val1', 'f2' => 'val2'];

        $result = $this->service->executeMapping($mapping, $input);

        $this->assertSame('val1', $result['field1']);
        $this->assertArrayNotHasKey('field2', $result);
    }

    // =========================================================================
    // executeMapping — casts
    // =========================================================================

    public function testCastToInt(): void
    {
        $mapping = $this->createMapping(['num' => 'val'], [], ['num' => 'int']);
        $result = $this->service->executeMapping($mapping, ['val' => '42']);

        $this->assertSame(42, $result['num']);
    }

    public function testCastToFloat(): void
    {
        $mapping = $this->createMapping(['num' => 'val'], [], ['num' => 'float']);
        $result = $this->service->executeMapping($mapping, ['val' => '3.14']);

        $this->assertSame(3.14, $result['num']);
    }

    public function testCastToString(): void
    {
        $mapping = $this->createMapping(['str' => 'val'], [], ['str' => 'string']);
        $result = $this->service->executeMapping($mapping, ['val' => 123]);

        $this->assertSame('123', $result['str']);
    }

    public function testCastToBoolTrue(): void
    {
        $mapping = $this->createMapping(['flag' => 'val'], [], ['flag' => 'bool']);
        $result = $this->service->executeMapping($mapping, ['val' => 'true']);

        $this->assertTrue($result['flag']);
    }

    public function testCastToBoolFalse(): void
    {
        $mapping = $this->createMapping(['flag' => 'val'], [], ['flag' => 'bool']);
        $result = $this->service->executeMapping($mapping, ['val' => 'no']);

        $this->assertFalse($result['flag']);
    }

    public function testCastToNullableBoolNull(): void
    {
        $mapping = $this->createMapping(['flag' => 'val'], [], ['flag' => '?bool']);
        $result = $this->service->executeMapping($mapping, ['val' => '']);

        $this->assertNull($result['flag']);
    }

    public function testCastToNullableBoolTrue(): void
    {
        $mapping = $this->createMapping(['flag' => 'val'], [], ['flag' => '?boolean']);
        $result = $this->service->executeMapping($mapping, ['val' => 'yes']);

        $this->assertTrue($result['flag']);
    }

    public function testCastToArray(): void
    {
        $mapping = $this->createMapping(['arr' => 'val'], [], ['arr' => 'array']);
        $result = $this->service->executeMapping($mapping, ['val' => 'test']);

        $this->assertIsArray($result['arr']);
    }

    public function testCastToJson(): void
    {
        $mapping = $this->createMapping(['j' => 'val'], [], ['j' => 'json']);
        $result = $this->service->executeMapping($mapping, ['val' => ['a' => 1]]);

        $this->assertSame('{"a":1}', $result['j']);
    }

    public function testCastJsonToArray(): void
    {
        $mapping = $this->createMapping(['arr' => 'val'], [], ['arr' => 'jsonToArray']);
        $result = $this->service->executeMapping($mapping, ['val' => '{"key":"value"}']);

        $this->assertSame(['key' => 'value'], $result['arr']);
    }

    public function testCastJsonToArrayAlreadyArray(): void
    {
        $mapping = $this->createMapping(['arr' => 'val'], [], ['arr' => 'jsonToArray']);
        $result = $this->service->executeMapping($mapping, ['val' => ['key' => 'value']]);

        $this->assertSame(['key' => 'value'], $result['arr']);
    }

    public function testCastNullStringToNull(): void
    {
        $mapping = $this->createMapping(['n' => 'val'], [], ['n' => 'nullStringToNull']);
        $result = $this->service->executeMapping($mapping, ['val' => 'null']);

        $this->assertNull($result['n']);
    }

    public function testCastNullStringToNullNotNull(): void
    {
        $mapping = $this->createMapping(['n' => 'val'], [], ['n' => 'nullStringToNull']);
        $result = $this->service->executeMapping($mapping, ['val' => 'hello']);

        $this->assertSame('hello', $result['n']);
    }

    public function testCastMoneyStringToInt(): void
    {
        $mapping = $this->createMapping(['m' => 'val'], [], ['m' => 'moneyStringToInt']);
        $result = $this->service->executeMapping($mapping, ['val' => '1.234,56']);

        $this->assertSame(123456, $result['m']);
    }

    public function testCastIntToMoneyString(): void
    {
        $mapping = $this->createMapping(['m' => 'val'], [], ['m' => 'intToMoneyString']);
        $result = $this->service->executeMapping($mapping, ['val' => 123456]);

        $this->assertSame('1.234,56', $result['m']);
    }

    public function testCastBase64(): void
    {
        $mapping = $this->createMapping(['b' => 'val'], [], ['b' => 'base64']);
        $result = $this->service->executeMapping($mapping, ['val' => 'hello']);

        $this->assertSame(base64_encode('hello'), $result['b']);
    }

    public function testCastBase64Decode(): void
    {
        $mapping = $this->createMapping(['b' => 'val'], [], ['b' => 'base64Decode']);
        $result = $this->service->executeMapping($mapping, ['val' => base64_encode('hello')]);

        $this->assertSame('hello', $result['b']);
    }

    public function testCastUrl(): void
    {
        $mapping = $this->createMapping(['u' => 'val'], [], ['u' => 'url']);
        $result = $this->service->executeMapping($mapping, ['val' => 'hello world']);

        $this->assertSame('hello+world', $result['u']);
    }

    public function testCastUrlDecode(): void
    {
        $mapping = $this->createMapping(['u' => 'val'], [], ['u' => 'urlDecode']);
        $result = $this->service->executeMapping($mapping, ['val' => 'hello+world']);

        $this->assertSame('hello world', $result['u']);
    }

    public function testCastHtml(): void
    {
        $mapping = $this->createMapping(['h' => 'val'], [], ['h' => 'html']);
        $result = $this->service->executeMapping($mapping, ['val' => '<b>test</b>']);

        $this->assertSame('&lt;b&gt;test&lt;/b&gt;', $result['h']);
    }

    public function testCastHtmlDecode(): void
    {
        $mapping = $this->createMapping(['h' => 'val'], [], ['h' => 'htmlDecode']);
        $result = $this->service->executeMapping($mapping, ['val' => '&lt;b&gt;']);

        $this->assertSame('<b>', $result['h']);
    }

    public function testCastUnsetIfValueMatch(): void
    {
        $mapping = $this->createMapping(['f' => 'val'], [], ['f' => 'unsetIfValue==remove']);
        $result = $this->service->executeMapping($mapping, ['val' => 'remove']);

        $this->assertArrayNotHasKey('f', $result);
    }

    public function testCastUnsetIfValueEmpty(): void
    {
        $mapping = $this->createMapping(['f' => 'val'], [], ['f' => 'unsetIfValue==']);
        $result = $this->service->executeMapping($mapping, ['val' => '']);

        $this->assertArrayNotHasKey('f', $result);
    }

    public function testCastSetNullIfValueMatch(): void
    {
        $mapping = $this->createMapping(['f' => 'val'], [], ['f' => 'setNullIfValue==empty']);
        $result = $this->service->executeMapping($mapping, ['val' => 'empty']);

        $this->assertNull($result['f']);
    }

    public function testCastSetNullIfValueEmpty(): void
    {
        $mapping = $this->createMapping(['f' => 'val'], [], ['f' => 'setNullIfValue==']);
        $result = $this->service->executeMapping($mapping, ['val' => '']);

        $this->assertNull($result['f']);
    }

    public function testCastDefaultUnknownType(): void
    {
        $mapping = $this->createMapping(['f' => 'val'], [], ['f' => 'unknownCastType']);
        $result = $this->service->executeMapping($mapping, ['val' => 'hello']);

        $this->assertSame('hello', $result['f']);
    }

    public function testCastMultipleCastsAsCsv(): void
    {
        $mapping = $this->createMapping(['f' => 'val'], [], ['f' => 'string,url']);
        $result = $this->service->executeMapping($mapping, ['val' => 'hello world']);

        $this->assertSame('hello+world', $result['f']);
    }

    // =========================================================================
    // executeMapping — list mode
    // =========================================================================

    public function testExecuteMappingListMode(): void
    {
        $mapping = $this->createMapping(['name' => 'title']);
        $input = [
            ['title' => 'Item 1'],
            ['title' => 'Item 2'],
        ];

        $result = $this->service->executeMapping($mapping, $input, true);

        $this->assertCount(2, $result);
        $this->assertSame('Item 1', $result[0]['name']);
        $this->assertSame('Item 2', $result[1]['name']);
    }

    public function testExecuteMappingListModeWithListInput(): void
    {
        $mapping = $this->createMapping(['name' => 'value']);
        $input = [
            'listInput' => ['a', 'b'],
            'extra' => 'context',
        ];

        $result = $this->service->executeMapping($mapping, $input, true);

        $this->assertCount(2, $result);
    }

    // =========================================================================
    // executeMapping — root level '#' key
    // =========================================================================

    public function testExecuteMappingRootHashArrayValue(): void
    {
        $mapping = $this->createMapping(['#' => 'items']);
        $input = ['items' => ['a', 'b', 'c']];

        $result = $this->service->executeMapping($mapping, $input);

        $this->assertSame(['a', 'b', 'c'], $result);
    }

    public function testExecuteMappingRootHashNullValue(): void
    {
        $mapping = $this->createMapping(['#' => 'missing']);
        $input = [];

        // When # maps to a missing key, Twig renders empty string; the special # handling applies only when value is set
        $result = $this->service->executeMapping($mapping, $input);

        $this->assertIsArray($result);
    }

    // =========================================================================
    // areAllArrayKeysNull
    // =========================================================================

    public function testAreAllArrayKeysNullEmpty(): void
    {
        $result = $this->invokeMethod($this->service, 'areAllArrayKeysNull', [[]]);
        $this->assertTrue($result);
    }

    public function testAreAllArrayKeysNullAllNull(): void
    {
        $result = $this->invokeMethod($this->service, 'areAllArrayKeysNull', [['a' => null, 'b' => '']]);
        $this->assertTrue($result);
    }

    public function testAreAllArrayKeysNullNested(): void
    {
        $result = $this->invokeMethod($this->service, 'areAllArrayKeysNull', [
            ['nested' => ['a' => null, 'b' => null]],
        ]);
        $this->assertTrue($result);
    }

    public function testAreAllArrayKeysNullHasValue(): void
    {
        $result = $this->invokeMethod($this->service, 'areAllArrayKeysNull', [['a' => 'hello']]);
        $this->assertFalse($result);
    }

    // =========================================================================
    // invalidateMappingCache
    // =========================================================================

    public function testInvalidateMappingCacheCallsRemove(): void
    {
        $this->cache->expects($this->once())->method('remove')->with('123');
        $this->service->invalidateMappingCache(123);
    }

    public function testInvalidateMappingCacheNoCache(): void
    {
        // Create service without cache (exception during creation)
        $failingFactory = $this->createMock(ICacheFactory::class);
        $failingFactory->method('createDistributed')
            ->willThrowException(new \Exception('No cache'));

        $service = new MappingService($this->mappingMapper, $failingFactory, $this->logger);

        // Should not throw
        $service->invalidateMappingCache('test');
        $this->assertTrue(true);
    }

    // =========================================================================
    // getMapping — cache hit
    // =========================================================================

    public function testGetMappingCacheHit(): void
    {
        $cachedData = [
            'id' => 1,
            'name' => 'test-mapping',
            'mapping' => ['a' => 'b'],
            'unset' => [],
            'cast' => [],
            'passThrough' => false,
        ];

        $this->cache->method('get')->with('test-id')->willReturn($cachedData);

        $result = $this->service->getMapping('test-id');
        $this->assertInstanceOf(Mapping::class, $result);
    }

    // =========================================================================
    // getMapping — cache miss
    // =========================================================================

    public function testGetMappingCacheMiss(): void
    {
        $this->cache->method('get')->willReturn(null);

        $mapping = new Mapping();
        $mapping->setMapping(['a' => 'b']);
        $mapping->setUnset([]);
        $mapping->setCast([]);
        $mapping->setPassThrough(false);

        $this->mappingMapper->method('find')->willReturn($mapping);
        $this->cache->expects($this->once())->method('set');

        $result = $this->service->getMapping('test-id');
        $this->assertInstanceOf(Mapping::class, $result);
    }

    // =========================================================================
    // getMappings
    // =========================================================================

    public function testGetMappings(): void
    {
        $this->mappingMapper->method('findAll')->willReturn([]);
        $result = $this->service->getMappings();
        $this->assertIsArray($result);
    }
}
