<?php

declare(strict_types=1);

namespace Unit\Service\GraphQL;

use GraphQL\Error\Error;
use GraphQL\Language\Parser;
use OCA\OpenRegister\Service\GraphQL\QueryComplexityAnalyzer;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;

class QueryComplexityAnalyzerTest extends TestCase
{
    private QueryComplexityAnalyzer $analyzer;
    private IAppConfig&MockObject $appConfig;

    protected function setUp(): void
    {
        $this->appConfig = $this->createMock(IAppConfig::class);
        $this->appConfig->method('getValueString')
            ->willReturnCallback(function (string $app, string $key, string $default) {
                return match ($key) {
                    'graphql_max_depth' => '10',
                    'graphql_max_cost' => '10000',
                    default => $default,
                };
            });

        $this->analyzer = new QueryComplexityAnalyzer($this->appConfig);
    }

    public function testSimpleQueryPassesComplexity(): void
    {
        $document = Parser::parse('{ melding(id: "1") { title status } }');
        $result = $this->analyzer->analyze($document);

        $this->assertLessThanOrEqual(10, $result['depth']);
        $this->assertLessThanOrEqual(10000, $result['cost']);
        $this->assertSame(10, $result['maxDepth']);
        $this->assertSame(10000, $result['maxCost']);
    }

    public function testDepthLimitingRejectsDeepQuery(): void
    {
        // Create a query deeper than 10 levels.
        $this->appConfig = $this->createMock(IAppConfig::class);
        $this->appConfig->method('getValueString')
            ->willReturnCallback(function (string $app, string $key, string $default) {
                return match ($key) {
                    'graphql_max_depth' => '3',
                    'graphql_max_cost' => '10000',
                    default => $default,
                };
            });

        $analyzer = new QueryComplexityAnalyzer($this->appConfig);

        $document = Parser::parse('{ a { b { c { d { e } } } } }');

        $this->expectException(Error::class);
        $this->expectExceptionMessageMatches('/depth/i');
        $analyzer->analyze($document);
    }

    public function testCostBudgetRejectsExpensiveQuery(): void
    {
        $this->appConfig = $this->createMock(IAppConfig::class);
        $this->appConfig->method('getValueString')
            ->willReturnCallback(function (string $app, string $key, string $default) {
                return match ($key) {
                    'graphql_max_depth' => '100',
                    'graphql_max_cost' => '50',
                    default => $default,
                };
            });

        $analyzer = new QueryComplexityAnalyzer($this->appConfig);

        // Query with list multiplier: first: 100 × nested resolver costs.
        $document = Parser::parse('{ items(first: 100) { nested { name } } }');

        $this->expectException(Error::class);
        $this->expectExceptionMessageMatches('/cost/i');
        $analyzer->analyze($document);
    }

    public function testListMultiplierIncreasessCost(): void
    {
        $querySmall = Parser::parse('{ items(first: 5) { name } }');
        $queryLarge = Parser::parse('{ items(first: 50) { name } }');

        $resultSmall = $this->analyzer->analyze($querySmall);
        $resultLarge = $this->analyzer->analyze($queryLarge);

        $this->assertGreaterThan($resultSmall['cost'], $resultLarge['cost']);
    }

    public function testIntrospectionFieldsSkipped(): void
    {
        $document = Parser::parse('{ __schema { types { name } } }');
        $result = $this->analyzer->analyze($document);

        // Introspection fields should have 0 cost.
        $this->assertSame(0, $result['cost']);
    }

    public function testComplexityReturnedInResult(): void
    {
        $document = Parser::parse('{ melding(id: "1") { title } }');
        $result = $this->analyzer->analyze($document);

        $this->assertArrayHasKey('depth', $result);
        $this->assertArrayHasKey('cost', $result);
        $this->assertArrayHasKey('maxDepth', $result);
        $this->assertArrayHasKey('maxCost', $result);
    }

    public function testPerSchemaCostOverride(): void
    {
        $this->analyzer->setSchemaCosts(['expensiveField' => 50]);

        $document = Parser::parse('{ expensiveField { name } }');
        $result = $this->analyzer->analyze($document);

        // Cost should include the override cost of 50 instead of default 10.
        $this->assertGreaterThanOrEqual(50, $result['cost']);
    }
}
