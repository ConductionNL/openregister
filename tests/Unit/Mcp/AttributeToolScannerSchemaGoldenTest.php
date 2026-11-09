<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.
// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- fixture methods exist only to be reflected.

namespace OCA\OpenRegister\Tests\Unit\Mcp;

use Countable;
use OCA\OpenRegister\Mcp\Attribute\McpTool;
use OCA\OpenRegister\Mcp\Attribute\McpToolTitle;
use OCA\OpenRegister\Mcp\AttributeToolScanner;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Stringable;

/**
 * Methods whose types, docblocks and declarations exercise every branch of
 * the scanner's schema inference and declaration checks.
 */
class AttributeToolScannerSchemaFixture
{
    /**
     * Union and nullable parameters.
     *
     * @param int|string      $a An id.
     * @param int|string|null $b
     * @param string|null     $c Nullable text.
     *
     *
     */
    #[McpTool]
    public function unions(int|string $a, int|string|null $b, ?string $c, int|float|string $d = 1, mixed $e = null): ?int
    {
        return null;
    }

    #[McpTool(subject: '  ', action: 'read', reach: 'user', annotations: ['team' => 'sales'])]
    #[McpToolTitle('  Exotic  ')]
    public function exotic(Countable&Stringable $x, iterable $y, bool|array $z, self $w, mixed ...$rest): array
    {
        return [];
    }

    /**
     * @param float $f A float.
     */
    #[McpTool(readOnlyHint: true, idempotentHint: false, scope: 'read', subject: 'lead')]
    public function tagsOnly(float $f): float|int
    {
        return 0;
    }

    /** One-line summary. */
    #[McpTool(name: 'named', description: '')]
    public function described(): ?Stringable
    {
        return null;
    }

    #[McpTool]
    public function classUnions(Countable|Stringable $v, Countable|Stringable|null $n, Countable|int $o): Countable|Stringable
    {
        return $v;
    }

    #[McpTool]
    public function voidReturn(): void
    {
    }

    #[McpTool]
    public function stringReturn(): string
    {
        return '';
    }

    #[McpTool(annotations: ['ReadOnlyHint ' => true])]
    public function reservedKey(): void
    {
    }

    #[McpTool(annotations: ['nested' => ['a' => 1]])]
    public function nestedAnnotation(): void
    {
    }

    #[McpTool(annotations: [0 => 'x'])]
    public function intKey(): void
    {
    }

    #[McpTool(annotations: [' ' => 'x'])]
    public function blankKey(): void
    {
    }

    #[McpTool(scope: 'bogus', reach: 'galaxy')]
    public function badScopeAndReach(): void
    {
    }

    #[McpTool(reach: 'galaxy', annotations: [0 => 'x'])]
    public function badReachAndAnnotation(): void
    {
    }
}//end class

/**
 * Pins every descriptor and every warning the scanner produces for
 * AttributeToolScannerSchemaFixture; set UPDATE_GOLDEN=1 to rewrite it.
 *
 * @covers \OCA\OpenRegister\Mcp\AttributeToolScanner
 */
class AttributeToolScannerSchemaGoldenTest extends TestCase
{
    private const FIXTURE = __DIR__ . '/fixtures/attribute-tool-scanner-schema-golden.json';

    /**
     * The descriptors and warnings match the recording.
     *
     * @return void
     */
    public function testDescriptorsAndWarningsMatchGolden(): void
    {
        $logger = new class extends AbstractLogger {
            /** @var list<array{0: string, 1: string, 2: array<mixed>}> */
            public array $records = [];

            public function log($level, Stringable|string $message, array $context = []): void
            {
                $this->records[] = [(string) $level, (string) $message, $context];
            }
        };

        $descriptors = (new AttributeToolScanner())->scanClass('demo', AttributeToolScannerSchemaFixture::class, $logger);
        $actual      = json_decode((string) json_encode(['descriptors' => $descriptors, 'log' => $logger->records]), true);

        if (getenv('UPDATE_GOLDEN') === '1') {
            file_put_contents(self::FIXTURE, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
        }

        $this->assertSame(json_decode((string) file_get_contents(self::FIXTURE), true), $actual);
    }//end testDescriptorsAndWarningsMatchGolden()
}//end class
