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

    /**
     * createSlug's exact output is load-bearing, not cosmetic.
     *
     * Harvest flows persist the slug as an object identifier, so a change to
     * this transformation orphans every object written under the old rule. It
     * was ported byte-for-byte from OpenConnector for that reason; these cases
     * pin the behaviour that port preserved.
     *
     * @dataProvider slugProvider
     *
     * @param string $input    The text to slugify.
     * @param string $expected The expected slug.
     *
     * @return void
     */
    public function testCreateSlugIsStable(string $input, string $expected): void
    {
        $this->assertSame($expected, $this->runtime->createSlug($input));
    }

    /**
     * Slug cases, each a rule the transformation applies in order.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function slugProvider(): array
    {
        return [
            'lowercases'                 => ['Hello World', 'hello-world'],
            'spaces to hyphens'          => ['a b c', 'a-b-c'],
            'underscores to hyphens'     => ['a_b_c', 'a-b-c'],
            'strips punctuation'         => ['Hello, World!', 'hello-world'],
            'collapses repeat hyphens'   => ['a---b', 'a-b'],
            'trims leading and trailing' => ['-abc-', 'abc'],
            'keeps digits'               => ['Repo 2026', 'repo-2026'],
            'strips non-ascii'           => ['Ruben van der Linde', 'ruben-van-der-linde'],
            'empty stays empty'          => ['', ''],
            'punctuation only'           => ['!!!', ''],
        ];
    }

    /**
     * json_decode returns an associative array, under the snake_case name
     * OpenConnector's stored templates call.
     *
     * @return void
     */
    public function testJsonDecodeReturnsAnAssociativeArray(): void
    {
        $this->assertSame(['a' => 1, 'b' => ['c' => 2]], $this->runtime->json_decode('{"a":1,"b":{"c":2}}'));
    }

    /**
     * Malformed JSON yields an empty array rather than a fatal.
     *
     * A mapping template is authored data; a typo in it must not take the whole
     * run down with an uncatchable error.
     *
     * @return void
     */
    public function testJsonDecodeOnMalformedInputIsEmpty(): void
    {
        $this->assertSame([], $this->runtime->json_decode('{not json'));
    }

    /**
     * Both spellings decode identically — that is the whole point of keeping two.
     *
     * @return void
     */
    public function testBothJsonDecodeSpellingsAgree(): void
    {
        $json = '{"x":[1,2,3]}';
        $this->assertSame($this->runtime->json_decode($json), $this->runtime->jsonDecode($json));
    }

    /**
     * base64 round-trips.
     *
     * @return void
     */
    public function testBase64RoundTrips(): void
    {
        $this->assertSame('aGk=', $this->runtime->b64enc('hi'));
        $this->assertSame('hi', $this->runtime->b64dec('aGk='));
        $this->assertSame('some text', $this->runtime->b64dec($this->runtime->b64enc('some text')));
    }
}
