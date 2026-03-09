<?php

namespace Unit\Twig;

use OCA\OpenRegister\Db\Mapping;
use OCA\OpenRegister\Db\MappingMapper;
use OCA\OpenRegister\Service\MappingService;
use OCA\OpenRegister\Twig\MappingRuntime;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\UuidV4;
use Twig\Extension\RuntimeExtensionInterface;

class MappingRuntimeTest extends TestCase
{
    private MappingService&MockObject $mappingService;
    private MappingMapper&MockObject $mappingMapper;
    private MappingRuntime $runtime;

    protected function setUp(): void
    {
        $this->mappingService = $this->createMock(MappingService::class);
        $this->mappingMapper = $this->createMock(MappingMapper::class);
        $this->runtime = new MappingRuntime($this->mappingService, $this->mappingMapper);
    }

    public function testImplementsRuntimeExtensionInterface(): void
    {
        $this->assertInstanceOf(RuntimeExtensionInterface::class, $this->runtime);
    }

    // --- b64enc() ---

    public function testB64enc(): void
    {
        $this->assertSame(base64_encode('hello world'), $this->runtime->b64enc('hello world'));
    }

    public function testB64encEmptyString(): void
    {
        $this->assertSame('', $this->runtime->b64enc(''));
    }

    public function testB64encSpecialChars(): void
    {
        $input = "line1\nline2\ttab";
        $this->assertSame(base64_encode($input), $this->runtime->b64enc($input));
    }

    // --- b64dec() ---

    public function testB64dec(): void
    {
        $encoded = base64_encode('hello world');
        $this->assertSame('hello world', $this->runtime->b64dec($encoded));
    }

    public function testB64decEmptyString(): void
    {
        $this->assertSame('', $this->runtime->b64dec(''));
    }

    public function testB64encAndB64decRoundTrip(): void
    {
        $input = 'roundtrip test 123!@#';
        $this->assertSame($input, $this->runtime->b64dec($this->runtime->b64enc($input)));
    }

    // --- jsonDecode() ---

    public function testJsonDecode(): void
    {
        $json = '{"key":"value","num":42}';
        $this->assertSame(['key' => 'value', 'num' => 42], $this->runtime->jsonDecode($json));
    }

    public function testJsonDecodeEmptyObject(): void
    {
        $this->assertSame([], $this->runtime->jsonDecode('{}'));
    }

    public function testJsonDecodeArray(): void
    {
        $this->assertSame([1, 2, 3], $this->runtime->jsonDecode('[1,2,3]'));
    }

    public function testJsonDecodeInvalidJsonReturnsEmptyArray(): void
    {
        $this->assertSame([], $this->runtime->jsonDecode('not valid json'));
    }

    public function testJsonDecodeEmptyStringReturnsEmptyArray(): void
    {
        $this->assertSame([], $this->runtime->jsonDecode(''));
    }

    // --- generateUuid() ---

    public function testGenerateUuidReturnsUuidV4(): void
    {
        $uuid = $this->runtime->generateUuid();
        $this->assertInstanceOf(UuidV4::class, $uuid);
    }

    public function testGenerateUuidReturnsDifferentValues(): void
    {
        $uuid1 = $this->runtime->generateUuid();
        $uuid2 = $this->runtime->generateUuid();
        $this->assertNotSame((string)$uuid1, (string)$uuid2);
    }

    // --- zgwEnum() ---

    public function testZgwEnumMapsValue(): void
    {
        $mappings = [
            'status' => [
                'open' => 'geopend',
                'closed' => 'gesloten',
            ],
        ];

        $this->assertSame('geopend', $this->runtime->zgwEnum('open', 'status', $mappings));
    }

    public function testZgwEnumReturnsOriginalWhenNoMapping(): void
    {
        $mappings = ['status' => ['open' => 'geopend']];
        $this->assertSame('unknown', $this->runtime->zgwEnum('unknown', 'status', $mappings));
    }

    public function testZgwEnumReturnsOriginalWhenFieldNotInMappings(): void
    {
        $mappings = ['status' => ['open' => 'geopend']];
        $this->assertSame('value', $this->runtime->zgwEnum('value', 'nonexistent', $mappings));
    }

    public function testZgwEnumEmptyMappings(): void
    {
        $this->assertSame('value', $this->runtime->zgwEnum('value', 'field', []));
    }

    public function testZgwEnumDefaultEmptyMappings(): void
    {
        $this->assertSame('value', $this->runtime->zgwEnum('value', 'field'));
    }

    // --- zgwEnumReverse() ---

    public function testZgwEnumReverseMapsDutchToEnglish(): void
    {
        $mappings = [
            'status' => [
                'open' => 'geopend',
                'closed' => 'gesloten',
            ],
        ];

        $this->assertSame('open', $this->runtime->zgwEnumReverse('geopend', 'status', $mappings));
        $this->assertSame('closed', $this->runtime->zgwEnumReverse('gesloten', 'status', $mappings));
    }

    public function testZgwEnumReverseReturnsOriginalWhenNoMapping(): void
    {
        $mappings = ['status' => ['open' => 'geopend']];
        $this->assertSame('unknown', $this->runtime->zgwEnumReverse('unknown', 'status', $mappings));
    }

    public function testZgwEnumReverseReturnsOriginalWhenFieldMissing(): void
    {
        $this->assertSame('val', $this->runtime->zgwEnumReverse('val', 'missing', ['other' => []]));
    }

    public function testZgwEnumReverseEmptyMappings(): void
    {
        $this->assertSame('val', $this->runtime->zgwEnumReverse('val', 'field', []));
    }

    public function testZgwEnumReverseDefaultEmptyMappings(): void
    {
        $this->assertSame('val', $this->runtime->zgwEnumReverse('val', 'field'));
    }

    // --- zgwExtractUuid() ---

    public function testZgwExtractUuidFromUrl(): void
    {
        $url = 'https://example.com/api/zgw/catalogi/v1/zaaktypen/uuid-123';
        $this->assertSame('uuid-123', $this->runtime->zgwExtractUuid($url));
    }

    public function testZgwExtractUuidStripsTrailingSlash(): void
    {
        $url = 'https://example.com/api/v1/items/my-uuid/';
        $this->assertSame('my-uuid', $this->runtime->zgwExtractUuid($url));
    }

    public function testZgwExtractUuidSimplePath(): void
    {
        $this->assertSame('abc', $this->runtime->zgwExtractUuid('/abc'));
    }

    public function testZgwExtractUuidJustUuid(): void
    {
        $this->assertSame('some-uuid', $this->runtime->zgwExtractUuid('some-uuid'));
    }

    public function testZgwExtractUuidNull(): void
    {
        $this->assertSame('', $this->runtime->zgwExtractUuid(null));
    }

    public function testZgwExtractUuidEmptyString(): void
    {
        $this->assertSame('', $this->runtime->zgwExtractUuid(''));
    }

    // --- executeMapping() with Mapping object ---

    public function testExecuteMappingWithMappingObject(): void
    {
        $mapping = $this->createMock(Mapping::class);
        $input = ['key' => 'value'];
        $expected = ['mapped' => 'data'];

        $this->mappingService->expects($this->once())
            ->method('executeMapping')
            ->with($mapping, $input, false)
            ->willReturn($expected);

        $result = $this->runtime->executeMapping($mapping, $input);
        $this->assertSame($expected, $result);
    }

    public function testExecuteMappingWithListFlag(): void
    {
        $mapping = $this->createMock(Mapping::class);

        $this->mappingService->expects($this->once())
            ->method('executeMapping')
            ->with($mapping, $this->anything(), true)
            ->willReturn([]);

        $this->runtime->executeMapping($mapping, [], true);
    }

    // --- executeMapping() with array ---

    public function testExecuteMappingWithArray(): void
    {
        $mappingArray = ['id' => 1, 'name' => 'test-mapping'];
        $input = ['key' => 'value'];

        $this->mappingService->expects($this->once())
            ->method('executeMapping')
            ->with($this->isInstanceOf(Mapping::class), $input, false)
            ->willReturn(['result' => 'ok']);

        $result = $this->runtime->executeMapping($mappingArray, $input);
        $this->assertSame(['result' => 'ok'], $result);
    }

    // --- executeMapping() with integer ID ---

    public function testExecuteMappingWithIntId(): void
    {
        $mapping = $this->createMock(Mapping::class);

        $this->mappingMapper->expects($this->once())
            ->method('find')
            ->with(42)
            ->willReturn($mapping);

        $this->mappingService->expects($this->once())
            ->method('executeMapping')
            ->with($mapping, ['in' => 'data'], false)
            ->willReturn(['out' => 'data']);

        $result = $this->runtime->executeMapping(42, ['in' => 'data']);
        $this->assertSame(['out' => 'data'], $result);
    }

    // --- executeMapping() with string ID ---

    public function testExecuteMappingWithStringId(): void
    {
        $mapping = $this->createMock(Mapping::class);

        $this->mappingMapper->expects($this->once())
            ->method('find')
            ->with('some-id')
            ->willReturn($mapping);

        $this->mappingService->expects($this->once())
            ->method('executeMapping')
            ->willReturn([]);

        $this->runtime->executeMapping('some-id', []);
    }

    // --- executeMapping() with URL reference ---

    public function testExecuteMappingWithUrlReference(): void
    {
        $mapping = $this->createMock(Mapping::class);

        $this->mappingMapper->expects($this->once())
            ->method('findByRef')
            ->with('https://example.com/mapping/1')
            ->willReturn([$mapping]);

        $this->mappingService->expects($this->once())
            ->method('executeMapping')
            ->with($mapping, ['x' => 1], false)
            ->willReturn(['y' => 2]);

        $result = $this->runtime->executeMapping('https://example.com/mapping/1', ['x' => 1]);
        $this->assertSame(['y' => 2], $result);
    }

    public function testExecuteMappingWithHttpUrlReference(): void
    {
        $mapping = $this->createMock(Mapping::class);

        $this->mappingMapper->expects($this->once())
            ->method('findByRef')
            ->with('http://local/mapping')
            ->willReturn([$mapping]);

        $this->mappingService->method('executeMapping')->willReturn([]);

        $this->runtime->executeMapping('http://local/mapping', []);
    }
}
