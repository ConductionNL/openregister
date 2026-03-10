<?php

namespace Unit\Service;

use OCA\OpenRegister\Db\Mapping;
use OCA\OpenRegister\Db\MappingMapper;
use OCA\OpenRegister\Service\MappingService;
use OCP\ICacheFactory;
use OCP\ICache;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class MappingServiceTest extends TestCase
{
    private MappingMapper&MockObject $mappingMapper;
    private ICacheFactory&MockObject $cacheFactory;
    private ICache&MockObject $cache;
    private LoggerInterface&MockObject $logger;
    private MappingService $service;

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

    private function createMapping(array $mapping = [], bool $passThrough = false, array $unset = [], array $cast = []): Mapping
    {
        $entity = new Mapping();
        // Use hydrate to set properties since Mapping extends Entity
        $entity->hydrate([
            'name' => 'TestMapping',
            'mapping' => json_encode($mapping),
            'passThrough' => $passThrough,
            'unset' => json_encode($unset),
            'cast' => json_encode($cast),
        ]);
        $ref = new \ReflectionClass($entity);
        $prop = $ref->getProperty('id');
        $prop->setAccessible(true);
        $prop->setValue($entity, 1);
        return $entity;
    }

    public function testEncodeArrayKeysSimple(): void
    {
        $input = ['key.with.dots' => 'value'];
        $result = $this->service->encodeArrayKeys($input, '.', '&#46;');

        $this->assertArrayHasKey('key&#46;with&#46;dots', $result);
        $this->assertSame('value', $result['key&#46;with&#46;dots']);
    }

    public function testEncodeArrayKeysNested(): void
    {
        $input = ['parent.key' => ['child.key' => 'value']];
        $result = $this->service->encodeArrayKeys($input, '.', '&#46;');

        $this->assertArrayHasKey('parent&#46;key', $result);
        $this->assertArrayHasKey('child&#46;key', $result['parent&#46;key']);
    }

    public function testEncodeArrayKeysEmptyArray(): void
    {
        $result = $this->service->encodeArrayKeys([], '.', '&#46;');

        $this->assertSame([], $result);
    }

    public function testEncodeArrayKeysNoMatchingCharacters(): void
    {
        $input = ['simple_key' => 'value'];
        $result = $this->service->encodeArrayKeys($input, '.', '&#46;');

        $this->assertArrayHasKey('simple_key', $result);
    }

    public function testCoordinateStringToArraySinglePoint(): void
    {
        $result = $this->service->coordinateStringToArray('52.123 4.567');

        $this->assertSame(['52.123', '4.567'], $result);
    }

    public function testCoordinateStringToArrayMultiplePoints(): void
    {
        $result = $this->service->coordinateStringToArray('52.1 4.5 52.2 4.6');

        $this->assertCount(2, $result);
        $this->assertSame(['52.1', '4.5'], $result[0]);
        $this->assertSame(['52.2', '4.6'], $result[1]);
    }

    public function testExecuteMappingSimpleDotNotation(): void
    {
        $mapping = $this->createMapping(['output_name' => 'input_name']);
        $input = ['input_name' => 'John'];

        $result = $this->service->executeMapping($mapping, $input);

        $this->assertSame('John', $result['output_name']);
    }

    public function testExecuteMappingWithPassThrough(): void
    {
        $entity = new Mapping();
        $entity->hydrate([
            'name' => 'TestMapping',
            'mapping' => json_encode(['new_field' => 'source_field']),
            'passThrough' => true,
            'unset' => json_encode([]),
            'cast' => json_encode([]),
        ]);

        $input = ['source_field' => 'value1', 'extra_field' => 'extra'];

        $result = $this->service->executeMapping($entity, $input);

        $this->assertSame('value1', $result['new_field']);
        $this->assertSame('extra', $result['extra_field']);
    }

    public function testExecuteMappingWithoutPassThrough(): void
    {
        $entity = new Mapping();
        $entity->hydrate([
            'name' => 'TestMapping',
            'mapping' => json_encode(['new_field' => 'source_field']),
            'passThrough' => false,
            'unset' => json_encode([]),
            'cast' => json_encode([]),
        ]);

        $input = ['source_field' => 'value1', 'extra_field' => 'extra'];

        $result = $this->service->executeMapping($entity, $input);

        $this->assertSame('value1', $result['new_field']);
        $this->assertArrayNotHasKey('extra_field', $result);
    }

    public function testExecuteMappingWithUnset(): void
    {
        $entity = new Mapping();
        $entity->hydrate([
            'name' => 'TestMapping',
            'mapping' => json_encode(['a' => 'x', 'b' => 'y']),
            'passThrough' => false,
            'unset' => json_encode(['b']),
            'cast' => json_encode([]),
        ]);

        $input = ['x' => '1', 'y' => '2'];

        $result = $this->service->executeMapping($entity, $input);

        $this->assertSame('1', $result['a']);
        $this->assertArrayNotHasKey('b', $result);
    }

    public function testExecuteMappingWithCastToInt(): void
    {
        $entity = new Mapping();
        $entity->hydrate([
            'name' => 'TestMapping',
            'mapping' => json_encode(['count' => 'input_count']),
            'passThrough' => false,
            'unset' => json_encode([]),
            'cast' => json_encode(['count' => 'int']),
        ]);

        $input = ['input_count' => '42'];

        $result = $this->service->executeMapping($entity, $input);

        $this->assertSame(42, $result['count']);
    }

    public function testExecuteMappingWithCastToBool(): void
    {
        $entity = new Mapping();
        $entity->hydrate([
            'name' => 'TestMapping',
            'mapping' => json_encode(['active' => 'input_active']),
            'passThrough' => false,
            'unset' => json_encode([]),
            'cast' => json_encode(['active' => 'bool']),
        ]);

        $input = ['input_active' => 'true'];

        $result = $this->service->executeMapping($entity, $input);

        $this->assertTrue($result['active']);
    }

    public function testExecuteMappingWithCastToString(): void
    {
        $entity = new Mapping();
        $entity->hydrate([
            'name' => 'TestMapping',
            'mapping' => json_encode(['val' => 'num']),
            'passThrough' => false,
            'unset' => json_encode([]),
            'cast' => json_encode(['val' => 'string']),
        ]);

        $input = ['num' => 123];

        $result = $this->service->executeMapping($entity, $input);

        $this->assertSame('123', $result['val']);
    }

    public function testExecuteMappingWithCastToFloat(): void
    {
        $entity = new Mapping();
        $entity->hydrate([
            'name' => 'TestMapping',
            'mapping' => json_encode(['price' => 'input_price']),
            'passThrough' => false,
            'unset' => json_encode([]),
            'cast' => json_encode(['price' => 'float']),
        ]);

        $input = ['input_price' => '19.99'];

        $result = $this->service->executeMapping($entity, $input);

        $this->assertSame(19.99, $result['price']);
    }

    public function testExecuteMappingListMode(): void
    {
        $entity = new Mapping();
        $entity->hydrate([
            'name' => 'TestMapping',
            'mapping' => json_encode(['name' => 'input_name']),
            'passThrough' => false,
            'unset' => json_encode([]),
            'cast' => json_encode([]),
        ]);

        $input = [
            ['input_name' => 'Alice'],
            ['input_name' => 'Bob'],
        ];

        $result = $this->service->executeMapping($entity, $input, true);

        $this->assertCount(2, $result);
        $this->assertSame('Alice', $result[0]['name']);
        $this->assertSame('Bob', $result[1]['name']);
    }

    public function testExecuteMappingRootLevelHash(): void
    {
        $entity = new Mapping();
        $entity->hydrate([
            'name' => 'TestMapping',
            'mapping' => json_encode(['#' => 'data']),
            'passThrough' => false,
            'unset' => json_encode([]),
            'cast' => json_encode([]),
        ]);

        $input = ['data' => ['key' => 'val']];

        $result = $this->service->executeMapping($entity, $input);

        $this->assertSame('val', $result['key']);
    }

    public function testExecuteMappingArrayValue(): void
    {
        $entity = new Mapping();
        $entity->hydrate([
            'name' => 'TestMapping',
            'mapping' => json_encode(['static_field' => ['hardcoded' => 'value']]),
            'passThrough' => false,
            'unset' => json_encode([]),
            'cast' => json_encode([]),
        ]);

        $input = [];

        $result = $this->service->executeMapping($entity, $input);

        $this->assertSame(['hardcoded' => 'value'], $result['static_field']);
    }

    public function testGetMappingFromCache(): void
    {
        $cachedData = [
            'id' => 5,
            'name' => 'CachedMapping',
            'mapping' => '{}',
            'passThrough' => false,
            'unset' => '[]',
            'cast' => '[]',
        ];

        $this->cache->method('get')->willReturn($cachedData);

        $result = $this->service->getMapping('5');

        $this->assertInstanceOf(Mapping::class, $result);
    }

    public function testGetMappingFromDatabase(): void
    {
        $mapping = new Mapping();
        $mapping->hydrate(['name' => 'DBMapping']);
        $ref = new \ReflectionClass($mapping);
        $prop = $ref->getProperty('id');
        $prop->setAccessible(true);
        $prop->setValue($mapping, 5);

        $this->cache->method('get')->willReturn(null);
        $this->mappingMapper->method('find')->willReturn($mapping);
        $this->cache->expects($this->once())->method('set');

        $result = $this->service->getMapping('5');

        $this->assertInstanceOf(Mapping::class, $result);
    }

    public function testGetMappings(): void
    {
        $this->mappingMapper->method('findAll')->willReturn([new Mapping(), new Mapping()]);

        $result = $this->service->getMappings();

        $this->assertCount(2, $result);
    }

    public function testInvalidateMappingCache(): void
    {
        $this->cache->expects($this->once())->method('remove')->with('123');

        $this->service->invalidateMappingCache(123);
    }

    public function testInvalidateMappingCacheWithStringId(): void
    {
        $this->cache->expects($this->once())->method('remove')->with('my-uuid');

        $this->service->invalidateMappingCache('my-uuid');
    }

    public function testExecuteMappingWithNullableBoolCastNull(): void
    {
        $entity = new Mapping();
        $entity->hydrate([
            'name' => 'TestMapping',
            'mapping' => json_encode(['flag' => 'input_flag']),
            'passThrough' => false,
            'unset' => json_encode([]),
            'cast' => json_encode(['flag' => '?bool']),
        ]);

        $input = ['input_flag' => null];

        $result = $this->service->executeMapping($entity, $input);

        $this->assertNull($result['flag']);
    }

    public function testExecuteMappingWithNullableBoolCastEmpty(): void
    {
        $entity = new Mapping();
        $entity->hydrate([
            'name' => 'TestMapping',
            'mapping' => json_encode(['flag' => 'input_flag']),
            'passThrough' => false,
            'unset' => json_encode([]),
            'cast' => json_encode(['flag' => '?boolean']),
        ]);

        $input = ['input_flag' => ''];

        $result = $this->service->executeMapping($entity, $input);

        $this->assertNull($result['flag']);
    }

    public function testExecuteMappingWithBase64Cast(): void
    {
        $entity = new Mapping();
        $entity->hydrate([
            'name' => 'TestMapping',
            'mapping' => json_encode(['encoded' => 'raw']),
            'passThrough' => false,
            'unset' => json_encode([]),
            'cast' => json_encode(['encoded' => 'base64']),
        ]);

        $input = ['raw' => 'hello'];

        $result = $this->service->executeMapping($entity, $input);

        $this->assertSame(base64_encode('hello'), $result['encoded']);
    }

    public function testExecuteMappingWithBase64DecodeCast(): void
    {
        $entity = new Mapping();
        $entity->hydrate([
            'name' => 'TestMapping',
            'mapping' => json_encode(['decoded' => 'encoded']),
            'passThrough' => false,
            'unset' => json_encode([]),
            'cast' => json_encode(['decoded' => 'base64Decode']),
        ]);

        $input = ['encoded' => base64_encode('hello')];

        $result = $this->service->executeMapping($entity, $input);

        $this->assertSame('hello', $result['decoded']);
    }

    public function testExecuteMappingWithJsonCast(): void
    {
        $entity = new Mapping();
        $entity->hydrate([
            'name' => 'TestMapping',
            'mapping' => json_encode(['json_val' => 'arr']),
            'passThrough' => false,
            'unset' => json_encode([]),
            'cast' => json_encode(['json_val' => 'json']),
        ]);

        $input = ['arr' => ['key' => 'val']];

        $result = $this->service->executeMapping($entity, $input);

        $this->assertSame('{"key":"val"}', $result['json_val']);
    }

    public function testExecuteMappingWithNullStringToNullCast(): void
    {
        $entity = new Mapping();
        $entity->hydrate([
            'name' => 'TestMapping',
            'mapping' => json_encode(['val' => 'input']),
            'passThrough' => false,
            'unset' => json_encode([]),
            'cast' => json_encode(['val' => 'nullStringToNull']),
        ]);

        $input = ['input' => 'null'];

        $result = $this->service->executeMapping($entity, $input);

        $this->assertNull($result['val']);
    }

    public function testExecuteMappingWithUrlEncodeCast(): void
    {
        $entity = new Mapping();
        $entity->hydrate([
            'name' => 'TestMapping',
            'mapping' => json_encode(['url_val' => 'input']),
            'passThrough' => false,
            'unset' => json_encode([]),
            'cast' => json_encode(['url_val' => 'url']),
        ]);

        $input = ['input' => 'hello world'];

        $result = $this->service->executeMapping($entity, $input);

        $this->assertSame('hello+world', $result['url_val']);
    }

    public function testConstructorHandlesCacheFactoryFailure(): void
    {
        $failingCacheFactory = $this->createMock(ICacheFactory::class);
        $failingCacheFactory->method('createDistributed')
            ->willThrowException(new \Exception('Cache unavailable'));

        $this->logger->expects($this->once())->method('warning');

        // Should not throw - gracefully falls back
        $service = new MappingService(
            $this->mappingMapper,
            $failingCacheFactory,
            $this->logger
        );

        $this->assertInstanceOf(MappingService::class, $service);
    }

    public function testExecuteMappingWithCastToArray(): void
    {
        $entity = new Mapping();
        $entity->hydrate([
            'name' => 'TestMapping',
            'mapping' => json_encode(['items' => 'input_items']),
            'passThrough' => false,
            'unset' => json_encode([]),
            'cast' => json_encode(['items' => 'array']),
        ]);

        $input = ['input_items' => 'single-value'];

        $result = $this->service->executeMapping($entity, $input);

        $this->assertIsArray($result['items']);
    }

    public function testExecuteMappingWithUrlDecodeCast(): void
    {
        $entity = new Mapping();
        $entity->hydrate([
            'name' => 'TestMapping',
            'mapping' => json_encode(['decoded' => 'input']),
            'passThrough' => false,
            'unset' => json_encode([]),
            'cast' => json_encode(['decoded' => 'urlDecode']),
        ]);

        $input = ['input' => 'hello+world'];

        $result = $this->service->executeMapping($entity, $input);

        $this->assertSame('hello world', $result['decoded']);
    }

    public function testExecuteMappingWithHtmlCast(): void
    {
        $entity = new Mapping();
        $entity->hydrate([
            'name' => 'TestMapping',
            'mapping' => json_encode(['escaped' => 'input']),
            'passThrough' => false,
            'unset' => json_encode([]),
            'cast' => json_encode(['escaped' => 'html']),
        ]);

        $input = ['input' => '<b>bold</b>'];

        $result = $this->service->executeMapping($entity, $input);

        $this->assertSame('&lt;b&gt;bold&lt;/b&gt;', $result['escaped']);
    }

    public function testExecuteMappingWithHtmlDecodeCast(): void
    {
        $entity = new Mapping();
        $entity->hydrate([
            'name' => 'TestMapping',
            'mapping' => json_encode(['decoded' => 'input']),
            'passThrough' => false,
            'unset' => json_encode([]),
            'cast' => json_encode(['decoded' => 'htmlDecode']),
        ]);

        $input = ['input' => '&lt;b&gt;bold&lt;/b&gt;'];

        $result = $this->service->executeMapping($entity, $input);

        $this->assertSame('<b>bold</b>', $result['decoded']);
    }

    public function testExecuteMappingWithJsonToArrayCast(): void
    {
        $entity = new Mapping();
        $entity->hydrate([
            'name' => 'TestMapping',
            'mapping' => json_encode(['parsed' => 'input']),
            'passThrough' => false,
            'unset' => json_encode([]),
            'cast' => json_encode(['parsed' => 'jsonToArray']),
        ]);

        $input = ['input' => '{"key":"val"}'];

        $result = $this->service->executeMapping($entity, $input);

        $this->assertIsArray($result['parsed']);
        $this->assertSame('val', $result['parsed']['key']);
    }

    public function testExecuteMappingWithJsonToArrayCastAlreadyArray(): void
    {
        $entity = new Mapping();
        $entity->hydrate([
            'name' => 'TestMapping',
            'mapping' => json_encode(['parsed' => 'input']),
            'passThrough' => false,
            'unset' => json_encode([]),
            'cast' => json_encode(['parsed' => 'jsonToArray']),
        ]);

        $input = ['input' => ['already' => 'array']];

        $result = $this->service->executeMapping($entity, $input);

        $this->assertSame(['already' => 'array'], $result['parsed']);
    }

    public function testExecuteMappingWithMoneyStringToIntCast(): void
    {
        $entity = new Mapping();
        $entity->hydrate([
            'name' => 'TestMapping',
            'mapping' => json_encode(['amount' => 'input']),
            'passThrough' => false,
            'unset' => json_encode([]),
            'cast' => json_encode(['amount' => 'moneyStringToInt']),
        ]);

        $input = ['input' => '1.234,56'];

        $result = $this->service->executeMapping($entity, $input);

        $this->assertSame(123456, $result['amount']);
    }

    public function testExecuteMappingWithIntToMoneyStringCast(): void
    {
        $entity = new Mapping();
        $entity->hydrate([
            'name' => 'TestMapping',
            'mapping' => json_encode(['display' => 'input']),
            'passThrough' => false,
            'unset' => json_encode([]),
            'cast' => json_encode(['display' => 'intToMoneyString']),
        ]);

        $input = ['input' => 12345];

        $result = $this->service->executeMapping($entity, $input);

        $this->assertSame('123,45', $result['display']);
    }

    public function testExecuteMappingWithNestedDotNotation(): void
    {
        $entity = new Mapping();
        $entity->hydrate([
            'name' => 'TestMapping',
            'mapping' => json_encode(['flat_city' => 'address.city']),
            'passThrough' => false,
            'unset' => json_encode([]),
            'cast' => json_encode([]),
        ]);

        $input = ['address' => ['city' => 'Amsterdam']];

        $result = $this->service->executeMapping($entity, $input);

        $this->assertSame('Amsterdam', $result['flat_city']);
    }

    public function testExecuteMappingWithNestedOutputDotNotation(): void
    {
        $entity = new Mapping();
        $entity->hydrate([
            'name' => 'TestMapping',
            'mapping' => json_encode(['output.nested' => 'input_field']),
            'passThrough' => false,
            'unset' => json_encode([]),
            'cast' => json_encode([]),
        ]);

        $input = ['input_field' => 'value'];

        $result = $this->service->executeMapping($entity, $input);

        $this->assertSame('value', $result['output']['nested']);
    }

    public function testExecuteMappingWithRootHashResolvesToScalar(): void
    {
        $entity = new Mapping();
        $entity->hydrate([
            'name' => 'TestMapping',
            'mapping' => json_encode(['#' => 'data']),
            'passThrough' => false,
            'unset' => json_encode([]),
            'cast' => json_encode([]),
        ]);

        // When # maps to a scalar, output wraps it in array.
        $input = ['data' => 'scalar-value'];

        $result = $this->service->executeMapping($entity, $input);

        $this->assertSame(['scalar-value'], $result);
    }

    public function testCoordinateStringToArraySingleNumber(): void
    {
        // Single coordinate with only one pair.
        $result = $this->service->coordinateStringToArray('52.123 4.567');

        $this->assertSame(['52.123', '4.567'], $result);
    }

    public function testEncodeArrayKeysDeepNesting(): void
    {
        $input = [
            'level.1' => [
                'level.2' => [
                    'level.3' => 'value',
                ],
            ],
        ];
        $result = $this->service->encodeArrayKeys($input, '.', '&#46;');

        $this->assertArrayHasKey('level&#46;1', $result);
        $this->assertArrayHasKey('level&#46;2', $result['level&#46;1']);
        $this->assertArrayHasKey('level&#46;3', $result['level&#46;1']['level&#46;2']);
    }

    public function testExecuteMappingWithUnsetOnNestedKey(): void
    {
        $entity = new Mapping();
        $entity->hydrate([
            'name' => 'TestMapping',
            'mapping' => json_encode(['a' => 'x', 'b.nested' => 'y']),
            'passThrough' => false,
            'unset' => json_encode(['b.nested']),
            'cast' => json_encode([]),
        ]);

        $input = ['x' => '1', 'y' => '2'];

        $result = $this->service->executeMapping($entity, $input);

        $this->assertSame('1', $result['a']);
        // b.nested was unset, but b key may still exist as empty.
        $this->assertArrayNotHasKey('nested', $result['b'] ?? []);
    }

    public function testExecuteMappingListModeWithExtraValues(): void
    {
        $entity = new Mapping();
        $entity->hydrate([
            'name' => 'TestMapping',
            'mapping' => json_encode(['name' => 'input_name', 'ctx' => 'context_val']),
            'passThrough' => false,
            'unset' => json_encode([]),
            'cast' => json_encode([]),
        ]);

        $input = [
            'listInput' => [
                ['input_name' => 'Alice'],
                ['input_name' => 'Bob'],
            ],
            'context_val' => 'shared-context',
        ];

        $result = $this->service->executeMapping($entity, $input, true);

        $this->assertCount(2, $result);
        $this->assertSame('shared-context', $result[0]['ctx']);
        $this->assertSame('shared-context', $result[1]['ctx']);
    }

    public function testExecuteMappingDefaultCastReturnsValue(): void
    {
        $entity = new Mapping();
        $entity->hydrate([
            'name' => 'TestMapping',
            'mapping' => json_encode(['val' => 'input']),
            'passThrough' => false,
            'unset' => json_encode([]),
            'cast' => json_encode(['val' => 'unknownCastType']),
        ]);

        $input = ['input' => 'original'];

        $result = $this->service->executeMapping($entity, $input);

        // Unknown cast type returns value unchanged.
        $this->assertSame('original', $result['val']);
    }

    public function testGetMappingCacheNull(): void
    {
        // Create service with no cache (cache factory throws).
        $failingCacheFactory = $this->createMock(ICacheFactory::class);
        $failingCacheFactory->method('createDistributed')
            ->willThrowException(new \Exception('Cache unavailable'));

        $service = new MappingService(
            $this->mappingMapper,
            $failingCacheFactory,
            $this->logger
        );

        $mapping = new Mapping();
        $mapping->hydrate(['name' => 'DBMapping']);
        $ref = new \ReflectionClass($mapping);
        $prop = $ref->getProperty('id');
        $prop->setAccessible(true);
        $prop->setValue($mapping, 5);

        $this->mappingMapper->method('find')->willReturn($mapping);

        $result = $service->getMapping('5');

        $this->assertInstanceOf(Mapping::class, $result);
    }

    // ── Additional edge-case tests ─────────────────────────────────────

    public function testExecuteMappingWithCastToDatetime(): void
    {
        $entity = new Mapping();
        $entity->hydrate([
            'name' => 'TestMapping',
            'mapping' => json_encode(['date' => 'input_date']),
            'passThrough' => false,
            'unset' => json_encode([]),
            'cast' => json_encode(['date' => 'datetime']),
        ]);

        $input = ['input_date' => '2025-01-15 10:30:00'];

        $result = $this->service->executeMapping($entity, $input);

        // datetime cast should produce a formatted date string
        $this->assertIsString($result['date']);
    }

    public function testExecuteMappingWithEmptyInput(): void
    {
        $entity = new Mapping();
        $entity->hydrate([
            'name' => 'TestMapping',
            'mapping' => json_encode(['output' => 'missing_key']),
            'passThrough' => false,
            'unset' => json_encode([]),
            'cast' => json_encode([]),
        ]);

        $input = [];

        $result = $this->service->executeMapping($entity, $input);

        // Missing key: the mapping uses the string value as-is when not found in input
        $this->assertArrayHasKey('output', $result);
    }

    public function testExecuteMappingWithMultipleCasts(): void
    {
        $entity = new Mapping();
        $entity->hydrate([
            'name' => 'TestMapping',
            'mapping' => json_encode([
                'count' => 'input_count',
                'active' => 'input_active',
                'price' => 'input_price',
            ]),
            'passThrough' => false,
            'unset' => json_encode([]),
            'cast' => json_encode([
                'count' => 'int',
                'active' => 'bool',
                'price' => 'float',
            ]),
        ]);

        $input = [
            'input_count' => '42',
            'input_active' => '1',
            'input_price' => '19.99',
        ];

        $result = $this->service->executeMapping($entity, $input);

        $this->assertSame(42, $result['count']);
        $this->assertTrue($result['active']);
        $this->assertSame(19.99, $result['price']);
    }

    public function testExecuteMappingWithMultipleUnsets(): void
    {
        $entity = new Mapping();
        $entity->hydrate([
            'name' => 'TestMapping',
            'mapping' => json_encode([
                'a' => 'x',
                'b' => 'y',
                'c' => 'z',
            ]),
            'passThrough' => false,
            'unset' => json_encode(['a', 'c']),
            'cast' => json_encode([]),
        ]);

        $input = ['x' => '1', 'y' => '2', 'z' => '3'];

        $result = $this->service->executeMapping($entity, $input);

        $this->assertArrayNotHasKey('a', $result);
        $this->assertSame('2', $result['b']);
        $this->assertArrayNotHasKey('c', $result);
    }

    public function testGetMappingsReturnsEmptyWhenNone(): void
    {
        $this->mappingMapper->method('findAll')->willReturn([]);

        $result = $this->service->getMappings();

        $this->assertCount(0, $result);
    }

    public function testExecuteMappingWithNullableCastNonNull(): void
    {
        $entity = new Mapping();
        $entity->hydrate([
            'name' => 'TestMapping',
            'mapping' => json_encode(['flag' => 'input_flag']),
            'passThrough' => false,
            'unset' => json_encode([]),
            'cast' => json_encode(['flag' => '?bool']),
        ]);

        $input = ['input_flag' => 'true'];

        $result = $this->service->executeMapping($entity, $input);

        $this->assertTrue($result['flag']);
    }

    public function testCoordinateStringToArrayEmptyString(): void
    {
        $result = $this->service->coordinateStringToArray('');

        $this->assertIsArray($result);
    }

}
