<?php

declare(strict_types=1);

namespace Unit\Service\Aggregation;

use OCA\OpenRegister\Service\Aggregation\WidgetAnnotationValidator;
use PHPUnit\Framework\TestCase;

class WidgetAnnotationValidatorTest extends TestCase
{
    private WidgetAnnotationValidator $v;

    protected function setUp(): void
    {
        $this->v = new WidgetAnnotationValidator();
    }

    public function testNoAnnotationIsValid(): void
    {
        $this->assertSame([], $this->v->validate(['properties' => []]));
    }

    public function testEmptyArrayIsRejected(): void
    {
        $errors = $this->v->validate(['x-openregister-widgets' => []]);
        $this->assertSame('widgets-empty', $errors[0]['code']);
    }

    public function testValidAggregationWidget(): void
    {
        $errors = $this->v->validate([
            'x-openregister-widgets' => [
                [
                    'type'       => 'kpi',
                    'title'      => 'Open',
                    'dataSource' => [
                        'mode'        => 'aggregation',
                        'register'    => 'decidesk',
                        'schema'      => 'action-item',
                        'aggregation' => 'totalOpen',
                    ],
                ],
            ],
        ]);
        $this->assertSame([], $errors);
    }

    public function testUnknownTypeIsRejected(): void
    {
        $errors = $this->v->validate([
            'x-openregister-widgets' => [
                ['type' => 'pie-galaxy', 'title' => 't', 'dataSource' => ['mode' => 'aggregation', 'register' => 'r', 'schema' => 's', 'aggregation' => 'a']],
            ],
        ]);
        $this->assertSame('widget-bad-type', $errors[0]['code']);
    }

    public function testMissingTitleIsRejected(): void
    {
        $errors = $this->v->validate([
            'x-openregister-widgets' => [
                ['type' => 'kpi', 'dataSource' => ['mode' => 'aggregation', 'register' => 'r', 'schema' => 's', 'aggregation' => 'a']],
            ],
        ]);
        $codes = array_column($errors, 'code');
        $this->assertContains('widget-title-missing', $codes);
    }

    public function testMissingDataSourceIsRejected(): void
    {
        $errors = $this->v->validate([
            'x-openregister-widgets' => [['type' => 'kpi', 'title' => 't']],
        ]);
        $this->assertSame('widget-datasource-missing', $errors[0]['code']);
    }

    public function testBadModeIsRejected(): void
    {
        $errors = $this->v->validate([
            'x-openregister-widgets' => [
                ['type' => 'kpi', 'title' => 't', 'dataSource' => ['mode' => 'sql']],
            ],
        ]);
        $this->assertSame('widget-datasource-bad-mode', $errors[0]['code']);
    }

    public function testAggregationModeRequiresAllRefs(): void
    {
        $errors = $this->v->validate([
            'x-openregister-widgets' => [
                ['type' => 'kpi', 'title' => 't', 'dataSource' => ['mode' => 'aggregation', 'register' => 'r']],
            ],
        ]);
        $codes = array_column($errors, 'code');
        $this->assertContains('widget-datasource-aggregation-incomplete', $codes);
        // Both schema + aggregation are missing → expect two of these.
        $matches = array_filter($codes, static fn(string $c) => $c === 'widget-datasource-aggregation-incomplete');
        $this->assertCount(2, $matches);
    }

    public function testGraphqlModeRequiresQuery(): void
    {
        $errors = $this->v->validate([
            'x-openregister-widgets' => [
                ['type' => 'chart', 'title' => 't', 'dataSource' => ['mode' => 'graphql']],
            ],
        ]);
        $this->assertSame('widget-datasource-graphql-incomplete', $errors[0]['code']);
    }

    public function testGraphqlModeWithQueryIsValid(): void
    {
        $errors = $this->v->validate([
            'x-openregister-widgets' => [
                [
                    'type'       => 'table',
                    'title'      => 'Cross-register list',
                    'dataSource' => ['mode' => 'graphql', 'graphqlQuery' => '{ x }'],
                ],
            ],
        ]);
        $this->assertSame([], $errors);
    }

    public function testOptionsMustBeObject(): void
    {
        $errors = $this->v->validate([
            'x-openregister-widgets' => [
                [
                    'type'       => 'kpi',
                    'title'      => 't',
                    'dataSource' => ['mode' => 'aggregation', 'register' => 'r', 'schema' => 's', 'aggregation' => 'a'],
                    'options'    => 'not-an-object',
                ],
            ],
        ]);
        $codes = array_column($errors, 'code');
        $this->assertContains('widget-options-malformed', $codes);
    }

    public function testNonObjectWidgetIsRejected(): void
    {
        $errors = $this->v->validate(['x-openregister-widgets' => ['not-an-object']]);
        $this->assertSame('widget-malformed', $errors[0]['code']);
    }
}
