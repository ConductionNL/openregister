<?php

declare(strict_types=1);

namespace Unit\Service\Object;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\ViewMapper;
use OCA\OpenRegister\Service\Object\SearchQueryHandler;
use OCA\OpenRegister\Service\SearchTrailService;
use OCA\OpenRegister\Service\SettingsService;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Guards the object-source snake_case filter fix in SearchQueryHandler::buildSearchQuery().
 *
 * PHP mangles dots in query-param names to underscores, and STEP 1 rebuilds the
 * nested structure (`@self.register` from `@self_register`). For a schema backed
 * by an external object-source (DBAL virtual register) whose columns are flat
 * snake_case (`product_line`, `app_slug`), that reconstruction wrongly split the
 * column into a nested array and the filter was silently dropped. The fix skips
 * the reconstruction for object-source schemas' non-`@` keys, keeping them
 * literal, while leaving the magic-table (nested-JSON) behaviour unchanged.
 */
class SearchQueryHandlerObjectSourceFilterTest extends TestCase
{
    private SchemaMapper $schemaMapper;

    private function makeHandler(): SearchQueryHandler
    {
        return new SearchQueryHandler(
            $this->createMock(ViewMapper::class),
            $this->schemaMapper,
            $this->createMock(SettingsService::class),
            $this->createMock(LoggerInterface::class),
            $this->createMock(IRequest::class),
            $this->createMock(SearchTrailService::class)
        );
    }

    /**
     * A snake_case object-field filter on an object-source schema must survive
     * as a literal top-level key, not be split into a nested array.
     */
    public function testObjectSourceSchemaKeepsSnakeCaseColumnLiteral(): void
    {
        $schema = $this->createMock(Schema::class);
        $schema->method('getObjectSource')->willReturn(['provider' => 'dbal-source', 'config' => []]);
        $this->schemaMapper = $this->createMock(SchemaMapper::class);
        $this->schemaMapper->method('find')->willReturn($schema);

        $result = $this->makeHandler()->buildSearchQuery(
            ['product_line' => 'shillinq', '_limit' => '5'],
            null,
            777
        );

        $this->assertArrayHasKey('product_line', $result, 'snake_case column must stay a literal key');
        $this->assertSame('shillinq', $result['product_line']);
        $this->assertArrayNotHasKey('product', $result, 'snake_case column must not be split into a nested array');
    }

    /**
     * A magic-table (non-object-source) schema must keep the historical
     * dot-un-mangling: `person_address_street` reconstructs a nested `person`.
     */
    public function testMagicTableSchemaStillUnmanglesUnderscores(): void
    {
        $this->schemaMapper = $this->createMock(SchemaMapper::class);

        $result = $this->makeHandler()->buildSearchQuery(
            ['person_address_street' => 'Main St'],
            null,
            null
        );

        $this->assertArrayHasKey('person', $result, 'nested reconstruction must be preserved for magic-table schemas');
        $this->assertArrayNotHasKey('person_address_street', $result);
    }
}
