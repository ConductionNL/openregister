<?php

namespace Unit\Twig;

use OCA\OpenRegister\Twig\MappingExtension;
use OCA\OpenRegister\Twig\MappingRuntime;
use PHPUnit\Framework\TestCase;
use Twig\TwigFilter;
use Twig\TwigFunction;

class MappingExtensionTest extends TestCase
{
    private MappingExtension $extension;

    protected function setUp(): void
    {
        $this->extension = new MappingExtension();
    }

    // --- getFilters() ---

    public function testGetFiltersReturnsArray(): void
    {
        $filters = $this->extension->getFilters();
        $this->assertIsArray($filters);
        $this->assertCount(6, $filters);
    }

    public function testGetFiltersAreTwigFilters(): void
    {
        foreach ($this->extension->getFilters() as $filter) {
            $this->assertInstanceOf(TwigFilter::class, $filter);
        }
    }

    public function testGetFiltersNames(): void
    {
        $names = array_map(fn(TwigFilter $f) => $f->getName(), $this->extension->getFilters());
        $this->assertContains('b64enc', $names);
        $this->assertContains('b64dec', $names);
        $this->assertContains('json_decode', $names);
        $this->assertContains('zgw_enum', $names);
        $this->assertContains('zgw_enum_reverse', $names);
        $this->assertContains('zgw_extract_uuid', $names);
    }

    public function testFiltersPointToMappingRuntime(): void
    {
        foreach ($this->extension->getFilters() as $filter) {
            $callable = $filter->getCallable();
            $this->assertIsArray($callable);
            $this->assertSame(MappingRuntime::class, $callable[0]);
        }
    }

    public function testFilterCallableMethods(): void
    {
        $filters = $this->extension->getFilters();
        $expected = ['b64enc', 'b64dec', 'jsonDecode', 'zgwEnum', 'zgwEnumReverse', 'zgwExtractUuid'];
        foreach ($filters as $i => $filter) {
            $this->assertSame($expected[$i], $filter->getCallable()[1]);
        }
    }

    // --- getFunctions() ---

    public function testGetFunctionsReturnsArray(): void
    {
        $functions = $this->extension->getFunctions();
        $this->assertIsArray($functions);

        // Asserted BY NAME, not by count. The count assertion that used to be
        // here broke the moment mapping consolidated — which told us a number
        // had changed, not whether anything was missing. These names are the
        // contract: a stored mapping calls them, so losing one breaks authored
        // data at evaluation time with nothing at author time to warn you.
        $names = array_map(static fn ($fn): string => $fn->getName(), $functions);

        foreach (['executeMapping', 'generateUuid', 'json_decode', 'jsonDecode', 'createSlug', 'b64enc', 'b64dec'] as $expected) {
            $this->assertContains($expected, $names, sprintf('mapping templates call %s()', $expected));
        }
    }

    /**
     * `json_decode` must be reachable as BOTH a function and a filter.
     *
     * OpenConnector's templates call `json_decode(x)`; OpenRegister's own use
     * `x|json_decode`. Consolidation kept both rather than picking one, because
     * a mapping is authored data and dropping either form breaks stored
     * templates silently.
     */
    public function testJsonDecodeIsReachableAsFunctionAndFilter(): void
    {
        $functionNames = array_map(static fn ($fn): string => $fn->getName(), $this->extension->getFunctions());
        $filterNames   = array_map(static fn ($f): string => $f->getName(), $this->extension->getFilters());

        $this->assertContains('json_decode', $functionNames, 'OpenConnector templates call json_decode()');
        $this->assertContains('json_decode', $filterNames, 'OpenRegister templates use |json_decode');
    }

    public function testGetFunctionsAreTwigFunctions(): void
    {
        foreach ($this->extension->getFunctions() as $fn) {
            $this->assertInstanceOf(TwigFunction::class, $fn);
        }
    }

    public function testGetFunctionsNames(): void
    {
        $names = array_map(fn(TwigFunction $f) => $f->getName(), $this->extension->getFunctions());
        $this->assertContains('executeMapping', $names);
        $this->assertContains('generateUuid', $names);
    }

    public function testFunctionsPointToMappingRuntime(): void
    {
        foreach ($this->extension->getFunctions() as $fn) {
            $callable = $fn->getCallable();
            $this->assertIsArray($callable);
            $this->assertSame(MappingRuntime::class, $callable[0]);
        }
    }

    public function testExecuteMappingCallable(): void
    {
        $functions = $this->extension->getFunctions();
        $this->assertSame('executeMapping', $functions[0]->getCallable()[1]);
    }

    public function testGenerateUuidCallable(): void
    {
        $functions = $this->extension->getFunctions();
        $this->assertSame('generateUuid', $functions[1]->getCallable()[1]);
    }
}
