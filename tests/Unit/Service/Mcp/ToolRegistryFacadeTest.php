<?php

/**
 * Unit tests for ToolRegistryFacade — the public read/invoke surface for
 * cross-app tool-loop consumers (ai-mcp REQ-006).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Mcp
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Mcp;

use OCA\OpenRegister\Service\Mcp\ToolRegistryFacade;
use OCA\OpenRegister\Service\ToolRegistry;
use OCA\OpenRegister\Tool\ToolInterface;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * ToolRegistryFacadeTest
 *
 * Exercises the facade against a real ToolRegistry populated with fake
 * ToolInterface mocks: list shape (flattening + whitelist), invoke
 * delegation (name and dotted-mcpId forms), unknown-id envelope, and
 * Throwable containment.
 */
class ToolRegistryFacadeTest extends TestCase
{

    /**
     * The real registry under the facade (mocked collaborators only).
     *
     * @var ToolRegistry
     */
    private ToolRegistry $registry;

    /**
     * Facade under test.
     *
     * @var ToolRegistryFacade
     */
    private ToolRegistryFacade $facade;

    /**
     * Mocked logger.
     *
     * @var LoggerInterface&MockObject
     */
    private LoggerInterface&MockObject $logger;

    /**
     * Set up a real ToolRegistry (event dispatcher mocked to a no-op) and
     * the facade wrapping it.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $eventDispatcher = $this->createMock(IEventDispatcher::class);
        $registryLogger  = $this->createMock(LoggerInterface::class);
        $this->logger    = $this->createMock(LoggerInterface::class);

        $this->registry = new ToolRegistry($eventDispatcher, $registryLogger);
        $this->facade   = new ToolRegistryFacade($this->registry, $this->logger);
    }//end setUp()

    /**
     * Build a ToolInterface mock returning the given function descriptors.
     *
     * @param array<int,array<string,mixed>> $functions Descriptors getFunctions() returns.
     *
     * @return ToolInterface&MockObject
     */
    private function createToolMock(array $functions): ToolInterface&MockObject
    {
        $tool = $this->createMock(ToolInterface::class);
        $tool->method('getFunctions')->willReturn($functions);

        return $tool;
    }//end createToolMock()

    /**
     * Default valid registry metadata.
     *
     * @param string $app Owning app id.
     *
     * @return array<string,string>
     */
    private function metadata(string $app='testapp'): array
    {
        return [
            'name'        => 'Test Tool',
            'description' => 'A test tool',
            'icon'        => 'icon-test',
            'app'         => $app,
        ];
    }//end metadata()

    // ── listTools ──

    /**
     * A single-function tool's descriptor is returned unchanged.
     *
     * @return void
     */
    public function testListToolsReturnsSingleFunctionDescriptorUnchanged(): void
    {
        $descriptor = [
            'name'        => 'testapp_doThing',
            'description' => 'Does the thing',
            'parameters'  => [
                'type'       => 'object',
                'properties' => ['limit' => ['type' => 'integer']],
            ],
        ];
        $this->registry->registerTool('testapp.doThing', $this->createToolMock([$descriptor]), $this->metadata());

        $result = $this->facade->listTools();

        $this->assertCount(1, $result);
        $this->assertSame($descriptor, $result[0]);
    }//end testListToolsReturnsSingleFunctionDescriptorUnchanged()

    /**
     * Registry-id-level tools exposing multiple functions are flattened.
     *
     * @return void
     */
    public function testListToolsFlattensMultiFunctionTool(): void
    {
        $builtinFunctions = [
            ['name' => 'list_registers', 'description' => 'List', 'parameters' => []],
            ['name' => 'get_register', 'description' => 'Get', 'parameters' => []],
            ['name' => 'create_register', 'description' => 'Create', 'parameters' => []],
        ];
        $this->registry->registerTool('openregister.register', $this->createToolMock($builtinFunctions), $this->metadata('openregister'));
        $this->registry->registerTool(
            'decidesk.listMeetings',
            $this->createToolMock([['name' => 'decidesk_listMeetings', 'description' => 'List meetings', 'parameters' => []]]),
            $this->metadata('decidesk')
        );

        $result = $this->facade->listTools();

        $this->assertCount(4, $result);
        $names = array_column($result, 'name');
        $this->assertContains('list_registers', $names);
        $this->assertContains('get_register', $names);
        $this->assertContains('create_register', $names);
        $this->assertContains('decidesk_listMeetings', $names);
    }//end testListToolsFlattensMultiFunctionTool()

    /**
     * A non-empty whitelist keeps only functions from matching registry ids.
     *
     * @return void
     */
    public function testListToolsNarrowsByWhitelist(): void
    {
        $this->registry->registerTool(
            'openregister.register',
            $this->createToolMock(
                [
                    ['name' => 'list_registers', 'description' => 'List', 'parameters' => []],
                    ['name' => 'get_register', 'description' => 'Get', 'parameters' => []],
                ]
            ),
            $this->metadata('openregister')
        );
        $this->registry->registerTool(
            'decidesk.listMeetings',
            $this->createToolMock([['name' => 'decidesk_listMeetings', 'description' => 'List meetings', 'parameters' => []]]),
            $this->metadata('decidesk')
        );

        $result = $this->facade->listTools(['decidesk.listMeetings']);

        $this->assertCount(1, $result);
        $this->assertSame('decidesk_listMeetings', $result[0]['name']);
    }//end testListToolsNarrowsByWhitelist()

    /**
     * A whitelist naming a FUNCTION resolves it — the id space consumers
     * actually store.
     *
     * Regression: the whitelist used to be intersected against registry ids, so
     * an entry naming a real function of a multi-function tool (`list_schemas`,
     * exactly what the agent tool-catalog offers) matched nothing and silently
     * resolved to ZERO tools — indistinguishable from a tool-less agent.
     *
     * @return void
     */
    public function testListToolsResolvesAWhitelistedFunctionName(): void
    {
        $this->registry->registerTool(
            'openregister.schema',
            $this->createToolMock(
                [
                    ['name' => 'list_schemas', 'description' => 'List', 'parameters' => []],
                    ['name' => 'delete_schema', 'description' => 'Delete', 'parameters' => []],
                ]
            ),
            $this->metadata('openregister')
        );

        $result = $this->facade->listTools(['list_schemas']);

        $this->assertCount(1, $result);
        $this->assertSame('list_schemas', $result[0]['name']);
    }//end testListToolsResolvesAWhitelistedFunctionName()

    /**
     * A whitelist naming one function does NOT drag in its tool's other
     * functions.
     *
     * Regression (the other direction of the same defect): matching per TOOL
     * meant a read-only intent silently handed over every function the tool
     * owns, `delete_schema` included.
     *
     * @return void
     */
    public function testListToolsDoesNotAdmitSiblingFunctionsOfAWhitelistedFunction(): void
    {
        $this->registry->registerTool(
            'openregister.schema',
            $this->createToolMock(
                [
                    ['name' => 'list_schemas', 'description' => 'List', 'parameters' => []],
                    ['name' => 'delete_schema', 'description' => 'Delete', 'parameters' => []],
                ]
            ),
            $this->metadata('openregister')
        );

        $names = array_column($this->facade->listTools(['list_schemas']), 'name');

        $this->assertNotContains('delete_schema', $names);
    }//end testListToolsDoesNotAdmitSiblingFunctionsOfAWhitelistedFunction()

    /**
     * A whitelist naming a tool's REGISTRY id still admits all of its functions
     * (the pre-existing coarse-grained grant stays valid).
     *
     * @return void
     */
    public function testListToolsRegistryIdStillAdmitsEveryFunctionOfThatTool(): void
    {
        $this->registry->registerTool(
            'openregister.schema',
            $this->createToolMock(
                [
                    ['name' => 'list_schemas', 'description' => 'List', 'parameters' => []],
                    ['name' => 'delete_schema', 'description' => 'Delete', 'parameters' => []],
                ]
            ),
            $this->metadata('openregister')
        );

        $names = array_column($this->facade->listTools(['openregister.schema']), 'name');

        $this->assertSame(['list_schemas', 'delete_schema'], $names);
    }//end testListToolsRegistryIdStillAdmitsEveryFunctionOfThatTool()

    /**
     * A whitelist naming a function's dotted `mcpId` resolves it — the third
     * accepted form, and the one an ADR-035 toolWhitelist stores for bridged
     * tools.
     *
     * @return void
     */
    public function testListToolsResolvesAWhitelistedMcpId(): void
    {
        $this->registry->registerTool(
            'openbuild.upsertSchema',
            $this->createToolMock(
                [
                    [
                        'name'        => 'openbuild_upsertSchema',
                        'mcpId'       => 'openbuild.upsertSchema',
                        'description' => 'Upsert',
                        'parameters'  => [],
                    ],
                ]
            ),
            $this->metadata('openbuild')
        );

        $result = $this->facade->listTools(['openbuild.upsertSchema']);

        $this->assertCount(1, $result);
        $this->assertSame('openbuild_upsertSchema', $result[0]['name']);
    }//end testListToolsResolvesAWhitelistedMcpId()

    /**
     * An unknown whitelist entry resolves to nothing — it must NOT fuzzy-match.
     *
     * `openregister.schemas` (plural) is not a real id; the tool is
     * `openregister.schema`. Resolving to zero is correct here; making that zero
     * VISIBLE is the consumer's job (Hermiq raises on it).
     *
     * @return void
     */
    public function testListToolsUnknownWhitelistEntryResolvesToNothing(): void
    {
        $this->registry->registerTool(
            'openregister.schema',
            $this->createToolMock([['name' => 'list_schemas', 'description' => 'List', 'parameters' => []]]),
            $this->metadata('openregister')
        );

        $this->assertSame([], $this->facade->listTools(['openregister.schemas']));
    }//end testListToolsUnknownWhitelistEntryResolvesToNothing()

    /**
     * An explicit empty whitelist means "all discovered tools allowed"
     * (hydra ADR-035 Decision 4 default semantics).
     *
     * @return void
     */
    public function testListToolsEmptyWhitelistReturnsEverything(): void
    {
        $this->registry->registerTool(
            'testapp.one',
            $this->createToolMock([['name' => 'testapp_one', 'description' => '1', 'parameters' => []]]),
            $this->metadata()
        );
        $this->registry->registerTool(
            'testapp.two',
            $this->createToolMock([['name' => 'testapp_two', 'description' => '2', 'parameters' => []]]),
            $this->metadata()
        );

        $result = $this->facade->listTools([]);

        $this->assertCount(2, $result);
    }//end testListToolsEmptyWhitelistReturnsEverything()

    /**
     * A bridge-shaped descriptor's mcpId field round-trips untouched.
     *
     * @return void
     */
    public function testListToolsPreservesBridgeMcpId(): void
    {
        $bridgeDescriptor = [
            'name'        => 'decidesk_listMeetings',
            'mcpId'       => 'decidesk.listMeetings',
            'description' => 'List meetings',
            'parameters'  => ['type' => 'object', 'properties' => []],
        ];
        $this->registry->registerTool('decidesk.listMeetings', $this->createToolMock([$bridgeDescriptor]), $this->metadata('decidesk'));

        $result = $this->facade->listTools();

        $this->assertCount(1, $result);
        $this->assertSame('decidesk.listMeetings', $result[0]['mcpId']);
    }//end testListToolsPreservesBridgeMcpId()

    // ── invokeTool ──

    /**
     * Invocation by function name delegates to the owning tool with the
     * given arguments and wraps the raw return in the success envelope.
     *
     * @return void
     */
    public function testInvokeToolDelegatesToOwningTool(): void
    {
        $tool = $this->createToolMock([['name' => 'decidesk_listMeetings', 'description' => 'List', 'parameters' => []]]);
        $tool->expects($this->once())
            ->method('executeFunction')
            ->with('decidesk_listMeetings', ['limit' => 5])
            ->willReturn(['meetings' => ['a', 'b']]);
        $this->registry->registerTool('decidesk.listMeetings', $tool, $this->metadata('decidesk'));

        $result = $this->facade->invokeTool('decidesk_listMeetings', ['limit' => 5]);

        $this->assertSame(
            [
                'result'  => ['meetings' => ['a', 'b']],
                'isError' => false,
            ],
            $result
        );
    }//end testInvokeToolDelegatesToOwningTool()

    /**
     * The dotted mcpId form resolves to the same owning tool and is
     * forwarded verbatim (the tool's own resolver accepts either form).
     *
     * @return void
     */
    public function testInvokeToolAcceptsDottedMcpIdForm(): void
    {
        $tool = $this->createToolMock(
            [
                [
                    'name'        => 'decidesk_listMeetings',
                    'mcpId'       => 'decidesk.listMeetings',
                    'description' => 'List',
                    'parameters'  => [],
                ],
            ]
        );
        $tool->expects($this->once())
            ->method('executeFunction')
            ->with('decidesk.listMeetings', ['limit' => 5])
            ->willReturn(['meetings' => []]);
        $this->registry->registerTool('decidesk.listMeetings', $tool, $this->metadata('decidesk'));

        $result = $this->facade->invokeTool('decidesk.listMeetings', ['limit' => 5]);

        $this->assertFalse($result['isError']);
        $this->assertSame(['meetings' => []], $result['result']);
    }//end testInvokeToolAcceptsDottedMcpIdForm()

    /**
     * The multi-function case routes to the tool owning the named function,
     * not another registered tool.
     *
     * @return void
     */
    public function testInvokeToolRoutesToCorrectToolAmongSeveral(): void
    {
        $wrongTool = $this->createToolMock([['name' => 'testapp_other', 'description' => 'Other', 'parameters' => []]]);
        $wrongTool->expects($this->never())->method('executeFunction');

        $rightTool = $this->createToolMock(
            [
                ['name' => 'list_registers', 'description' => 'List', 'parameters' => []],
                ['name' => 'get_register', 'description' => 'Get', 'parameters' => []],
            ]
        );
        $rightTool->expects($this->once())
            ->method('executeFunction')
            ->with('get_register', ['id' => '42'])
            ->willReturn(['register' => ['id' => '42']]);

        $this->registry->registerTool('testapp.other', $wrongTool, $this->metadata());
        $this->registry->registerTool('openregister.register', $rightTool, $this->metadata('openregister'));

        $result = $this->facade->invokeTool('get_register', ['id' => '42']);

        $this->assertFalse($result['isError']);
    }//end testInvokeToolRoutesToCorrectToolAmongSeveral()

    /**
     * An unknown id returns the not-found envelope without any delegation
     * and without throwing.
     *
     * @return void
     */
    public function testInvokeToolUnknownIdReturnsErrorEnvelope(): void
    {
        $tool = $this->createToolMock([['name' => 'testapp_known', 'description' => 'Known', 'parameters' => []]]);
        $tool->expects($this->never())->method('executeFunction');
        $this->registry->registerTool('testapp.known', $tool, $this->metadata());

        $result = $this->facade->invokeTool('ghost.tool', []);

        $this->assertSame(
            [
                'result'  => ['error' => 'Unknown tool: ghost.tool'],
                'isError' => true,
            ],
            $result
        );
    }//end testInvokeToolUnknownIdReturnsErrorEnvelope()

    /**
     * A Throwable from executeFunction() is caught, logged at error level,
     * and returned in the error envelope — never re-thrown.
     *
     * @return void
     */
    public function testInvokeToolCatchesThrowableFromTool(): void
    {
        $tool = $this->createToolMock([['name' => 'decidesk_listMeetings', 'description' => 'List', 'parameters' => []]]);
        $tool->method('executeFunction')->willThrowException(new RuntimeException('boom'));
        $this->registry->registerTool('decidesk.listMeetings', $tool, $this->metadata('decidesk'));

        $this->logger->expects($this->once())->method('error');

        $result = $this->facade->invokeTool('decidesk_listMeetings', []);

        $this->assertSame(
            [
                'result'  => ['error' => 'boom'],
                'isError' => true,
            ],
            $result
        );
    }//end testInvokeToolCatchesThrowableFromTool()

    // ── public contract ──

    /**
     * No impersonation: neither public method accepts a user, acting-user,
     * or agent parameter — the ambient session is the only identity.
     *
     * @return void
     */
    public function testPublicContractHasNoImpersonationParameter(): void
    {
        $invoke = new \ReflectionMethod(ToolRegistryFacade::class, 'invokeTool');
        $this->assertSame(
            ['toolId', 'arguments'],
            array_map(static fn($p) => $p->getName(), $invoke->getParameters())
        );

        $list = new \ReflectionMethod(ToolRegistryFacade::class, 'listTools');
        $this->assertSame(
            ['toolWhitelist'],
            array_map(static fn($p) => $p->getName(), $list->getParameters())
        );

        $publicMethods = array_filter(
            (new \ReflectionClass(ToolRegistryFacade::class))->getMethods(\ReflectionMethod::IS_PUBLIC),
            static fn($m) => $m->isConstructor() === false
        );
        // THREE now, deliberately. `describeTools()` was added for the agent
        // form's tool picker, which needs the contributing app and the
        // operation — neither of which an LLM function descriptor carries.
        // It is a SEPARATE method precisely so `listTools()` keeps returning
        // exactly what the model is sent: adding keys there would put `app`
        // and `right` into a tool-calling payload that strict provider APIs
        // reject.
        //
        // This list is the governed surface (gate-27 / ADR-022). Widening it
        // is a spec change, not an edit to this array — see ai-mcp REQ-006.
        $this->assertSame(
            ['listTools', 'describeTools', 'invokeTool'],
            array_values(array_map(static fn($m) => $m->getName(), $publicMethods)),
            'The facade must expose exactly three public methods'
        );
    }//end testPublicContractHasNoImpersonationParameter()
    /**
     * `describeTools()` adds what a PERSON needs; `listTools()` adds nothing.
     *
     * The split is the whole point. A tool loop hands `listTools()`' descriptors
     * to the model as function definitions, so an extra key there travels into a
     * tool-calling payload that strict provider APIs reject. The picker needs
     * the contributing app and the operation; the model must keep getting
     * exactly what it got.
     *
     * @return void
     *
     * @spec openspec/specs/ai-mcp/spec.md#requirement-the-facade-describes-tools-for-a-person-without-changing-what-the-model-is-sent
     */
    public function testDescribeToolsEnrichesWithoutChangingTheModelsDescriptors(): void
    {
        $this->registry->registerTool(
            'opencatalogi.cms',
            $this->createToolMock(
                [
                    ['name' => 'cms_create_page', 'description' => 'Create a page.', 'parameters' => []],
                    ['name' => 'cms_list_pages', 'description' => 'List pages.', 'parameters' => []],
                ]
            ),
            $this->metadata(app: 'opencatalogi')
        );

        // The model's view is untouched.
        foreach ($this->facade->listTools() as $descriptor) {
            $this->assertSame(
                ['name', 'description', 'parameters'],
                array_keys($descriptor),
                'a key leaked into the descriptors the model is sent'
            );
        }

        $described = $this->facade->describeTools();
        $this->assertCount(2, $described);

        $this->assertSame('opencatalogi', $described[0]['app'], 'the app must come from the registry id');
        $this->assertSame('cms_create_page', $described[0]['tool']);
        $this->assertSame('cms', $described[0]['group']);
        $this->assertSame('create', $described[0]['right']);
        $this->assertSame('read', $described[1]['right']);

    }//end testDescribeToolsEnrichesWithoutChangingTheModelsDescriptors()

    /**
     * Every described tool is distinguishable in a picker.
     *
     * Grouping collapsed `cms_create_page` and `cms_create_menu` into one label
     * — "opencatalogi | cms | create", twice — and two identical rows is a list
     * nobody can choose from.
     *
     * @return void
     *
     * @spec openspec/specs/ai-mcp/spec.md#requirement-the-facade-describes-tools-for-a-person-without-changing-what-the-model-is-sent
     */
    public function testEveryDescribedToolHasADistinctLabel(): void
    {
        $this->registry->registerTool(
            'opencatalogi.cms',
            $this->createToolMock(
                [
                    ['name' => 'cms_create_page', 'description' => '', 'parameters' => []],
                    ['name' => 'cms_create_menu', 'description' => '', 'parameters' => []],
                ]
            ),
            $this->metadata(app: 'opencatalogi')
        );

        $labels = array_map(
            static fn (array $entry): string => $entry['app'].' | '.$entry['tool'].' | '.$entry['right'],
            $this->facade->describeTools()
        );

        $this->assertCount(2, $labels);
        $this->assertSame($labels, array_unique($labels), 'two tools produced the same label');

    }//end testEveryDescribedToolHasADistinctLabel()

    /**
     * An unrecognised verb is `special`, never guessed into a CRUD bucket.
     *
     * @return void
     *
     * @spec openspec/specs/ai-mcp/spec.md#requirement-the-facade-describes-tools-for-a-person-without-changing-what-the-model-is-sent
     */
    public function testAnUnrecognisedVerbIsSpecial(): void
    {
        $this->registry->registerTool(
            'decidesk.actions',
            $this->createToolMock(
                [
                    // camelCase, so the verb hides inside one token — a split
                    // that missed it mislabelled 36 of 98 real tools.
                    ['name' => 'decidesk_listOpenActionItems', 'description' => '', 'parameters' => []],
                    ['name' => 'pipelinq_pipelineForecast', 'description' => '', 'parameters' => []],
                ]
            ),
            $this->metadata(app: 'decidesk')
        );

        $rights = array_column($this->facade->describeTools(), 'right', 'tool');

        $this->assertSame('read', $rights['decidesk_listOpenActionItems'], 'a camelCase verb was not recovered');
        $this->assertSame('special', $rights['pipelinq_pipelineForecast']);

    }//end testAnUnrecognisedVerbIsSpecial()
}//end class
