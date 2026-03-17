<?php

namespace Unit\Dto;

use OCA\OpenRegister\Dto\DeepLinkRegistration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DeepLinkRegistrationTest extends TestCase
{
    // --- Constructor ---

    public function testConstructorWithAllParameters(): void
    {
        $reg = new DeepLinkRegistration('procest', 'main', 'zaak', '/app/{uuid}', 'icon-zaak');

        $this->assertSame('procest', $reg->appId);
        $this->assertSame('main', $reg->registerSlug);
        $this->assertSame('zaak', $reg->schemaSlug);
        $this->assertSame('/app/{uuid}', $reg->urlTemplate);
        $this->assertSame('icon-zaak', $reg->icon);
    }

    public function testConstructorDefaultIcon(): void
    {
        $reg = new DeepLinkRegistration('myapp', 'reg', 'schema', '/url');
        $this->assertSame('', $reg->icon);
    }

    public function testPropertiesAreReadonly(): void
    {
        $reg = new DeepLinkRegistration('app', 'r', 's', '/t');
        $this->assertSame('app', $reg->appId);
    }

    // --- resolveUrl() ---

    public function testResolveUrlReplacesUuid(): void
    {
        $reg = new DeepLinkRegistration('app', 'r', 's', '/items/{uuid}');
        $result = $reg->resolveUrl(['uuid' => 'abc-123']);
        $this->assertSame('/items/abc-123', $result);
    }

    public function testResolveUrlReplacesId(): void
    {
        $reg = new DeepLinkRegistration('app', 'r', 's', '/items/{id}');
        $result = $reg->resolveUrl(['id' => 42]);
        $this->assertSame('/items/42', $result);
    }

    public function testResolveUrlReplacesRegisterAndSchema(): void
    {
        $reg = new DeepLinkRegistration('app', 'r', 's', '/{register}/{schema}/{uuid}');
        $result = $reg->resolveUrl(['uuid' => 'u1', 'register' => 'reg1', 'schema' => 'sch1']);
        $this->assertSame('/reg1/sch1/u1', $result);
    }

    public function testResolveUrlReplacesCustomTopLevelKeys(): void
    {
        $reg = new DeepLinkRegistration('app', 'r', 's', '/cases/{caseNumber}/view');
        $result = $reg->resolveUrl(['caseNumber' => 'CASE-001', 'uuid' => 'u1']);
        $this->assertSame('/cases/CASE-001/view', $result);
    }

    public function testResolveUrlIgnoresNonScalarValues(): void
    {
        $reg = new DeepLinkRegistration('app', 'r', 's', '/items/{uuid}/{nested}');
        $result = $reg->resolveUrl([
            'uuid' => 'abc',
            'nested' => ['not' => 'scalar'],
        ]);
        $this->assertSame('/items/abc/{nested}', $result);
    }

    public function testResolveUrlMissingPlaceholderLeavesEmpty(): void
    {
        $reg = new DeepLinkRegistration('app', 'r', 's', '/items/{uuid}');
        $result = $reg->resolveUrl([]);
        $this->assertSame('/items/', $result);
    }

    public function testResolveUrlNoPlaceholders(): void
    {
        $reg = new DeepLinkRegistration('app', 'r', 's', '/static/page');
        $result = $reg->resolveUrl(['uuid' => 'ignored']);
        $this->assertSame('/static/page', $result);
    }

    public function testResolveUrlMultiplePlaceholders(): void
    {
        $reg = new DeepLinkRegistration('app', 'r', 's', '/{register}/{schema}/{id}/{uuid}');
        $result = $reg->resolveUrl([
            'uuid' => 'u-1',
            'id' => 99,
            'register' => 5,
            'schema' => 10,
        ]);
        $this->assertSame('/5/10/99/u-1', $result);
    }

    public function testResolveUrlCastsIntToString(): void
    {
        $reg = new DeepLinkRegistration('app', 'r', 's', '/view/{id}');
        $result = $reg->resolveUrl(['id' => 0]);
        $this->assertSame('/view/0', $result);
    }

    public function testResolveUrlBooleanScalar(): void
    {
        $reg = new DeepLinkRegistration('app', 'r', 's', '/view/{active}');
        $result = $reg->resolveUrl(['active' => true]);
        $this->assertSame('/view/1', $result);
    }

    public function testResolveUrlEmptyObjectData(): void
    {
        $reg = new DeepLinkRegistration('app', 'r', 's', '/items/{uuid}');
        $result = $reg->resolveUrl([]);
        // {uuid} is replaced with empty string from default replacements
        $this->assertSame('/items/', $result);
    }

    #[DataProvider('resolveUrlProvider')]
    public function testResolveUrlVariousCombinations(
        string $template,
        array $data,
        string $expected
    ): void {
        $reg = new DeepLinkRegistration('app', 'r', 's', $template);
        $this->assertSame($expected, $reg->resolveUrl($data));
    }

    public static function resolveUrlProvider(): array
    {
        return [
            'simple uuid' => ['/item/{uuid}', ['uuid' => 'x'], '/item/x'],
            'integer id'  => ['/item/{id}', ['id' => 5], '/item/5'],
            'no placeholders' => ['/static', ['uuid' => 'x'], '/static'],
            'custom key' => ['/by/{slug}', ['slug' => 'my-slug'], '/by/my-slug'],
        ];
    }
}
