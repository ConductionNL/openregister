<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Mcp\BuiltIn;

use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Mcp\AttributeToolScanner;
use OCA\OpenRegister\Mcp\BuiltIn\AttributeToolProvider;
use OCA\OpenRegister\Mcp\BuiltIn\SchemaDerivedToolProvider;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Tests\Unit\Mcp\Fixtures\AttributeFixtureService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Mcp\BuiltIn\SchemaDerivedToolServices;

/**
 * Pins the exact audit record the attribute and schema-derived providers
 * write per invocation: the digest of the key-sorted canonical arguments
 * and the result summary.
 *
 * @covers \OCA\OpenRegister\Mcp\BuiltIn\AttributeToolProvider
 * @covers \OCA\OpenRegister\Mcp\BuiltIn\SchemaDerivedToolProvider
 */
class ToolInvocationAuditGoldenTest extends TestCase
{

    /**
     * The attribute provider audits the sorted, unescaped JSON digest and the id summary.
     *
     * @return void
     */
    public function testAttributeInvocationAuditRecord(): void
    {
        $logger  = $this->createMock(LoggerInterface::class);
        $audit   = $this->createMock(AuditTrailMapper::class);
        $entries = (new AttributeToolScanner())->scanClass(appId: 'pipelinq', className: AttributeFixtureService::class, logger: $logger);
        foreach ($entries as &$entry) {
            $entry['instance'] = new AttributeFixtureService();
        }

        unset($entry);

        $audit->expects($this->once())->method('createToolInvocationEntry')->with(
            'pipelinq.createLead',
            hash('sha256', '{"company":"Ü/co","email":"a@example.com","score":3}'),
            ['isError' => false, 'id' => 'lead-1'],
            null,
            null,
            null,
            'lead-1'
        )->willReturn($this->createMock(AuditTrail::class));

        $provider = new AttributeToolProvider(appId: 'pipelinq', entries: $entries, auditTrailMapper: $audit, logger: $logger);
        $provider->invokeTool('pipelinq.createLead', ['score' => 3, 'email' => 'a@example.com', 'company' => 'Ü/co']);
    }//end testAttributeInvocationAuditRecord()

    /**
     * The schema-derived provider sorts nested argument keys before digesting.
     *
     * @return void
     */
    public function testSchemaDerivedInvocationSortsNestedKeys(): void
    {
        $schema = new Schema();
        $schema->setId(1);
        $schema->setSlug('lead');
        $schema->setProperties(['status' => ['type' => 'string'], 'assignee' => ['type' => 'string'], 'name' => ['type' => 'string']]);
        $schema->setRequired(['name']);
        $schema->setConfiguration(['x-openregister-mcp' => ['enabled' => true, 'tools' => ['search' => ['filters' => ['status', 'assignee']]]]]);

        $objects = $this->createMock(ObjectService::class);
        $objects->method('findAll')->willReturn([]);
        $objects->method('count')->willReturn(0);

        $audit = $this->createMock(AuditTrailMapper::class);
        $audit->expects($this->once())->method('createToolInvocationEntry')->with(
            'pipelinq.lead.search',
            hash('sha256', '{"filters":{"assignee":"é/x","status":"open"}}'),
            $this->callback(static fn (array $summary): bool => $summary['isError'] === false && $summary['count'] === 0),
            null,
            1,
            null,
            null
        )->willReturn($this->createMock(AuditTrail::class));

        $provider = new SchemaDerivedToolProvider(
        	appId: 'pipelinq',
        	schemaEntries: [['schema' => $schema, 'register' => null]],
        	suppressedIds: [],
        	services: new SchemaDerivedToolServices(
        		objectService: $objects,
        		auditTrailMapper: $audit,
        		logger: $this->createMock(LoggerInterface::class),
        	),
        );
        $provider->invokeTool('pipelinq.lead.search', ['filters' => ['status' => 'open', 'assignee' => 'é/x']]);
    }//end testSchemaDerivedInvocationSortsNestedKeys()
}//end class
