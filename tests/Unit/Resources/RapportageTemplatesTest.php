<?php

declare(strict_types=1);

namespace Unit\Resources;

use OCA\OpenRegister\Service\Aggregation\WidgetAnnotationValidator;
use PHPUnit\Framework\TestCase;

/**
 * Structural tests for the Phase 3 dashboard templates shipped at
 * lib/Resources/RapportageSchemas/templates/.
 *
 * Each template is a configuration bundle with a single dashboard
 * object in components.objects. We assert:
 *
 *   - the JSON parses
 *   - it declares the `reports` register + `dashboard` schema as the
 *     target (so importing alongside report-bundle.json materialises
 *     the dashboard cleanly)
 *   - every widget inside the dashboard passes
 *     WidgetAnnotationValidator's shape checks (so the operator never
 *     hits a render-time surprise on an obviously-malformed widget).
 *   - the documented widget types are present (template name in the
 *     spec promises specific widgets — guard against silent drift).
 */
class RapportageTemplatesTest extends TestCase
{
    private const TEMPLATES = [
        'woo'         => __DIR__.'/../../../lib/Resources/RapportageSchemas/templates/woo.json',
        'audit-trail' => __DIR__.'/../../../lib/Resources/RapportageSchemas/templates/audit-trail.json',
    ];

    /**
     * @return array<string, array{0: string, 1: array<string, mixed>}>
     */
    public static function templateProvider(): array
    {
        $cases = [];
        foreach (self::TEMPLATES as $name => $path) {
            $raw = file_get_contents($path);
            if ($raw === false) {
                continue;
            }
            $cases[$name] = [$name, json_decode($raw, true)];
        }
        return $cases;
    }

    /**
     * @dataProvider templateProvider
     */
    public function testTemplateParsesAndCarriesOneDashboardObject(string $name, array $template): void
    {
        $this->assertIsArray($template, "$name JSON must decode to an array");
        $objects = $template['components']['objects'] ?? null;
        $this->assertIsArray($objects, "$name MUST declare components.objects");
        $this->assertCount(1, $objects, "$name MUST ship exactly one dashboard object");

        $dashboard = $objects[0];
        $this->assertSame('reports', $dashboard['@self']['register'] ?? null,
            "$name MUST target the `reports` register"
        );
        $this->assertSame('dashboard', $dashboard['@self']['schema'] ?? null,
            "$name MUST target the `dashboard` schema"
        );
        $this->assertNotEmpty($dashboard['@self']['slug'] ?? '',
            "$name MUST declare an object slug for upsert idempotency"
        );
    }

    /**
     * @dataProvider templateProvider
     */
    public function testEveryWidgetPassesShapeValidation(string $name, array $template): void
    {
        $widgets = $template['components']['objects'][0]['widgets'] ?? [];
        $this->assertNotEmpty($widgets, "$name MUST declare at least one widget");

        $errors = (new WidgetAnnotationValidator())->validate(
            ['x-openregister-widgets' => $widgets]
        );
        $this->assertSame([], $errors,
            "$name has malformed widgets: ".implode(', ', array_column($errors, 'message'))
        );
    }

    public function testWooTemplateCarriesTheDocumentedWidgets(): void
    {
        $widgets = json_decode(file_get_contents(self::TEMPLATES['woo']), true)
            ['components']['objects'][0]['widgets'];
        $aggNames = array_map(
            static fn(array $w): string => $w['dataSource']['aggregation'] ?? '',
            array_filter($widgets, static fn(array $w): bool => ($w['dataSource']['mode'] ?? '') === 'aggregation')
        );
        // The spec promises these three transparency-report metrics.
        $this->assertContains('publishedPerMonth', $aggNames);
        $this->assertContains('byPublicationCategory', $aggNames);
        $this->assertContains('withoutRecentActivity', $aggNames);
    }

    public function testAuditTrailTemplateMixesAggregationAndStatisticsModes(): void
    {
        $widgets = json_decode(file_get_contents(self::TEMPLATES['audit-trail']), true)
            ['components']['objects'][0]['widgets'];
        $modes = array_unique(array_map(
            static fn(array $w): string => $w['dataSource']['mode'] ?? '',
            $widgets
        ));
        // Per spec: composes the existing AuditTrailMapper endpoints
        // (statistics mode) plus per-register/per-schema breakdowns
        // (aggregation mode). Both modes MUST be exercised.
        $this->assertContains('aggregation', $modes);
        $this->assertContains('statistics', $modes);
    }

    public function testTemplatesDeclareTransparencyAndAuditCategories(): void
    {
        $woo = json_decode(file_get_contents(self::TEMPLATES['woo']), true);
        $audit = json_decode(file_get_contents(self::TEMPLATES['audit-trail']), true);
        $this->assertSame(
            'transparency',
            $woo['components']['objects'][0]['category'] ?? null,
            'WOO template MUST classify as `transparency` so operators can filter the dashboard list'
        );
        $this->assertSame(
            'audit',
            $audit['components']['objects'][0]['category'] ?? null,
            'audit-trail template MUST classify as `audit`'
        );
    }
}
