<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace Unit\Service\Flow;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Flow\FlowItems;
use OCA\OpenRegister\Service\Flow\Nodes\ObjectWriteNode;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use OCP\WorkflowEngine\IManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use UnexpectedValueException;

/**
 * Unit coverage for the `openregister.object-write` node.
 */
class ObjectWriteNodeTest extends TestCase
{

    /** @var MockObject&ObjectService */
    private $objects;

    /** @var MockObject&IUserManager */
    private $userManager;

    /** @var MockObject&IAppConfig */
    private $appConfig;

    private ObjectWriteNode $node;

    private Register $register;

    private Schema $schema;

    /** @var array<int, string> */
    private array $registerContext = ['triggeredBy' => 'alice'];


    protected function setUp(): void
    {
        parent::setUp();

        $this->register = new Register();
        $this->register->setId(1);
        $this->register->setSlug('example-hydra-cache');

        $this->schema = new Schema();
        $this->schema->setId(2);
        $this->schema->setSlug('example-cache-entry');

        $this->objects = $this->createMock(ObjectService::class);
        // `runAs()` scopes the acting user around a read. The double must RUN
        // the callable, or every lookup silently returns null and the node
        // reports "matched nothing" for reasons that have nothing to do with
        // the test.
        $this->objects->method('runAs')->willReturnCallback(
            static fn (IUser $user, callable $operation) => $operation()
        );

        $registers = $this->createMock(RegisterMapper::class);
        $registers->method('find')->willReturn($this->register);

        $schemas = $this->createMock(SchemaMapper::class);
        $schemas->method('findBySlugInIds')->willReturn($this->schema);
        $schemas->method('find')->willReturn($this->schema);

        $this->userManager = $this->createMock(IUserManager::class);
        $this->userManager->method('get')->willReturnCallback(
            function (string $uid): ?IUser {
                if ($uid !== 'alice') {
                    return null;
                }

                $user = $this->createMock(IUser::class);
                $user->method('getUID')->willReturn('alice');

                return $user;
            }
        );

        $this->appConfig = $this->createMock(IAppConfig::class);
        $this->appConfig->method('getValueInt')->willReturnCallback(
            static fn (string $app, string $key, int $default=0): int => $default
        );

        $l10n = $this->createMock(IL10N::class);
        $l10n->method('t')->willReturnCallback(
            static function (string $text, array $parameters=[]): string {
                if ($parameters === []) {
                    return $text;
                }

                return vsprintf(str_replace(['%s', '%1$s', '%2$s'], ['%s', '%1$s', '%2$s'], $text), $parameters);
            }
        );

        $urls = $this->createMock(IURLGenerator::class);
        $urls->method('imagePath')->willReturnCallback(
            static fn (string $app, string $file): string => '/'.$app.'/img/'.$file
        );

        $this->node = new ObjectWriteNode(
            $this->objects,
            $registers,
            $schemas,
            $this->userManager,
            $this->appConfig,
            $l10n,
            $urls
        );

    }//end setUp()


    /**
     * @param array<int, array<string, mixed>> $records
     *
     * @return array<int, array<string, mixed>>
     */
    private function items(array $records): array
    {
        return array_map(static fn (array $r): array => FlowItems::item(json: $r), $records);

    }//end items()


    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function config(array $overrides=[]): array
    {
        return array_merge(
            [
                'register'  => 'example-hydra-cache',
                'schema'    => 'example-cache-entry',
                'operation' => ObjectWriteNode::OP_CREATE,
                'fields'    => ['title' => 'literal'],
            ],
            $overrides
        );

    }//end config()


    private function entity(string $uuid, array $data=[]): ObjectEntity
    {
        $entity = new ObjectEntity();
        $entity->setUuid($uuid);
        $entity->setObject($data);

        return $entity;

    }//end entity()


    // ── Palette metadata (REQ-OWN-001) ──────────────────────────────────


    public function testTheNodeIdentifiesItselfForThePalette(): void
    {
        $this->assertSame('openregister.object-write', $this->node->getId());
        $this->assertNotSame('', $this->node->getDisplayName());
        $this->assertNotSame('', $this->node->getDescription());
        $this->assertNotSame('', $this->node->getIcon());

    }//end testTheNodeIdentifiesItselfForThePalette()


    public function testTheNodeIsOfferedInBothScopes(): void
    {
        $this->assertTrue($this->node->isAvailableForScope(IManager::SCOPE_USER));
        $this->assertTrue($this->node->isAvailableForScope(IManager::SCOPE_ADMIN));

    }//end testTheNodeIsOfferedInBothScopes()


    // ── validateConfig (REQ-OWN-009, REQ-OWN-012, REQ-OWN-015) ──────────


    /**
     * @dataProvider unusableConfigurations
     *
     * @param array<string, mixed> $config
     */
    public function testAnUnusableConfigurationIsRefused(array $config, string $because): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->node->validateConfig($config);
        $this->fail($because);

    }//end testAnUnusableConfigurationIsRefused()


    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function unusableConfigurations(): array
    {
        $match = [['property' => 'sourceId', 'value' => '{{id}}']];

        return [
            'no register'           => [['schema' => 's', 'operation' => 'create', 'fields' => ['a' => 1]], 'register is mandatory'],
            'no schema'             => [['register' => 'r', 'operation' => 'create', 'fields' => ['a' => 1]], 'schema is mandatory'],
            'no operation'          => [['register' => 'r', 'schema' => 's', 'fields' => ['a' => 1]], 'operation is mandatory'],
            'unknown operation'     => [['register' => 'r', 'schema' => 's', 'operation' => 'purge', 'fields' => ['a' => 1]], 'purge is not an operation'],
            'update with no match'  => [['register' => 'r', 'schema' => 's', 'operation' => 'update', 'fields' => ['a' => 1]], 'an update needs a match'],
            'upsert with no match'  => [['register' => 'r', 'schema' => 's', 'operation' => 'upsert', 'fields' => ['a' => 1]], 'an upsert needs a match'],
            'delete with no match'  => [['register' => 'r', 'schema' => 's', 'operation' => 'delete', 'confirmDelete' => true], 'a delete needs a match'],
            'empty match block'     => [['register' => 'r', 'schema' => 's', 'operation' => 'delete', 'confirmDelete' => true, 'match' => []], 'an empty match is not a match'],
            'match without value'   => [['register' => 'r', 'schema' => 's', 'operation' => 'update', 'fields' => ['a' => 1], 'match' => [['property' => 'x']]], 'a pair needs a value'],
            'match without prop'    => [['register' => 'r', 'schema' => 's', 'operation' => 'update', 'fields' => ['a' => 1], 'match' => [['value' => 'x']]], 'a pair needs a property'],
            'no fields on create'   => [['register' => 'r', 'schema' => 's', 'operation' => 'create'], 'a write needs fields'],
            'empty fields'          => [['register' => 'r', 'schema' => 's', 'operation' => 'create', 'fields' => []], 'a write needs fields'],
            'fields on delete'      => [['register' => 'r', 'schema' => 's', 'operation' => 'delete', 'confirmDelete' => true, 'match' => $match, 'fields' => ['a' => 1]], 'fields mean nothing for a delete'],
            'replace on delete'     => [['register' => 'r', 'schema' => 's', 'operation' => 'delete', 'confirmDelete' => true, 'match' => $match, 'replace' => false], 'replace means nothing for a delete'],
            'replace on create'     => [['register' => 'r', 'schema' => 's', 'operation' => 'create', 'fields' => ['a' => 1], 'replace' => true], 'replace means nothing for a create'],
            'delete unconfirmed'    => [['register' => 'r', 'schema' => 's', 'operation' => 'delete', 'match' => $match], 'a delete needs confirmDelete'],
            'delete confirm false'  => [['register' => 'r', 'schema' => 's', 'operation' => 'delete', 'match' => $match, 'confirmDelete' => false], 'false is not an acknowledgement'],
            'delete confirm string' => [['register' => 'r', 'schema' => 's', 'operation' => 'delete', 'match' => $match, 'confirmDelete' => 'true'], 'the string "true" is not boolean true'],
            'delete confirm one'    => [['register' => 'r', 'schema' => 's', 'operation' => 'delete', 'match' => $match, 'confirmDelete' => 1], '1 is not boolean true'],
            'maxWrites zero'        => [['register' => 'r', 'schema' => 's', 'operation' => 'create', 'fields' => ['a' => 1], 'maxWrites' => 0], 'a cap of zero is not a cap'],
            'maxWrites negative'    => [['register' => 'r', 'schema' => 's', 'operation' => 'create', 'fields' => ['a' => 1], 'maxWrites' => -1], 'a negative cap is not a cap'],
            'maxWrites string'      => [['register' => 'r', 'schema' => 's', 'operation' => 'create', 'fields' => ['a' => 1], 'maxWrites' => '10'], 'a cap must be a whole number'],
            'bad onMissing'         => [['register' => 'r', 'schema' => 's', 'operation' => 'create', 'fields' => ['a' => 1], 'onMissing' => 'blank'], 'onMissing is omit or fail'],
            'bad onNoMatch'         => [['register' => 'r', 'schema' => 's', 'operation' => 'create', 'fields' => ['a' => 1], 'onNoMatch' => 'insert'], 'onNoMatch is error or skip'],
        ];

    }//end unusableConfigurations()


    public function testAWorkableConfigurationValidatesForEveryOperation(): void
    {
        $match = [['property' => 'sourceId', 'value' => '{{id}}']];

        $this->node->validateConfig($this->config());
        $this->node->validateConfig($this->config(['operation' => 'update', 'match' => $match]));
        $this->node->validateConfig($this->config(['operation' => 'upsert', 'match' => $match, 'maxWrites' => 5000]));
        $this->node->validateConfig(
            [
                'register'      => 'r',
                'schema'        => 's',
                'operation'     => 'delete',
                'confirmDelete' => true,
                'onNoMatch'     => 'skip',
                'match'         => $match,
            ]
        );

        $this->addToAssertionCount(4);

    }//end testAWorkableConfigurationValidatesForEveryOperation()


    // ── Fail closed on an ownerless run (REQ-OWN-004) ───────────────────


    public function testARunWithNoOwnerWritesNothing(): void
    {
        $this->objects->expects($this->never())->method('saveObject');
        $this->objects->expects($this->never())->method('patchObject');
        $this->objects->expects($this->never())->method('deleteObject');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/triggeredBy/');

        $this->node->execute($this->items([['id' => 'a']]), $this->config(), ['triggeredBy' => null]);

    }//end testARunWithNoOwnerWritesNothing()


    public function testAnOwnerNamedInTheConfigurationCannotSubstituteForTheRunOwner(): void
    {
        $this->objects->expects($this->never())->method('saveObject');

        $this->expectException(RuntimeException::class);

        $this->node->execute(
            $this->items([['id' => 'a']]),
            $this->config(['user' => 'alice', 'owner' => 'alice']),
            []
        );

    }//end testAnOwnerNamedInTheConfigurationCannotSubstituteForTheRunOwner()


    public function testAnUnknownOwnerAccountAlsoFailsClosed(): void
    {
        $this->objects->expects($this->never())->method('saveObject');

        $this->expectException(RuntimeException::class);

        $this->node->execute($this->items([['id' => 'a']]), $this->config(), ['triggeredBy' => 'nobody']);

    }//end testAnUnknownOwnerAccountAlsoFailsClosed()


    // ── Create, attribution and output shape (REQ-OWN-002/003/007) ──────


    public function testEachItemProducesOneAttributedCreate(): void
    {
        $seen = [];
        $this->objects->method('saveObject')->willReturnCallback(
            function (mixed $object, ?array $extend=[], mixed $register=null, mixed $schema=null, ?string $uuid=null, bool $_rbac=true, bool $_multitenancy=true, bool $silent=false, ?array $uploadedFiles=null, ?IUser $currentUser=null) use (&$seen): ObjectEntity {
                $seen[] = [
                    'object'      => $object,
                    'register'    => $register,
                    'schema'      => $schema,
                    'currentUser' => $currentUser,
                    'rbac'        => $_rbac,
                    'silent'      => $silent,
                ];

                return $this->entity('uuid-'.count($seen), $object);
            }
        );

        $out = $this->node->execute(
            $this->items([['name' => 'a'], ['name' => 'b'], ['name' => 'c']]),
            $this->config(['fields' => ['title' => '{{name}}']]),
            $this->registerContext
        );

        $this->assertCount(3, $seen, 'three items, three writes');
        $this->assertCount(3, $out, 'three items, three output items');

        foreach ($seen as $call) {
            $this->assertSame($this->register, $call['register']);
            $this->assertSame($this->schema, $call['schema']);
            $this->assertInstanceOf(IUser::class, $call['currentUser']);
            $this->assertSame('alice', $call['currentUser']->getUID());
            $this->assertTrue($call['rbac'], 'RBAC is never bypassed by the node');
            $this->assertFalse($call['silent'], 'the audit trail is never suppressed');
        }

        $this->assertSame(['a', 'b', 'c'], array_column(array_column($seen, 'object'), 'title'));

        // The output carries the saved object plus its identifiers.
        $this->assertSame('uuid-1', $out[0]['json']['uuid']);
        $this->assertSame('a', $out[0]['json']['title']);
        $this->assertSame('example-hydra-cache', $out[0]['json']['register']);
        $this->assertSame('example-cache-entry', $out[0]['json']['schema']);

        // Provenance survives.
        $this->assertSame(['item' => 0], $out[0]['pairedItem']);
        $this->assertSame(['item' => 2], $out[2]['pairedItem']);

    }//end testEachItemProducesOneAttributedCreate()


    public function testBypassKeysInTheConfigurationAreNeverForwarded(): void
    {
        $seen = null;
        $this->objects->method('saveObject')->willReturnCallback(
            function (mixed $object, ?array $extend=[], mixed $register=null, mixed $schema=null, ?string $uuid=null, bool $_rbac=true, bool $_multitenancy=true, bool $silent=false, ?array $uploadedFiles=null, ?IUser $currentUser=null) use (&$seen): ObjectEntity {
                $seen = ['rbac' => $_rbac, 'multitenancy' => $_multitenancy, 'silent' => $silent];

                return $this->entity('uuid-1', $object);
            }
        );

        $this->node->execute(
            $this->items([['name' => 'a']]),
            $this->config(
                [
                    '_rbac'           => false,
                    '_multitenancy'   => false,
                    'silent'          => true,
                    '_retentionSweep' => true,
                    'hardDelete'      => true,
                ]
            ),
            $this->registerContext
        );

        $this->assertSame(['rbac' => true, 'multitenancy' => true, 'silent' => false], $seen);

    }//end testBypassKeysInTheConfigurationAreNeverForwarded()


    public function testBinaryAttachmentsSurviveTheWrite(): void
    {
        $this->objects->method('saveObject')->willReturn($this->entity('uuid-1', ['title' => 'a']));

        $item = FlowItems::item(json: ['name' => 'a'], binary: ['file' => ['mimeType' => 'text/plain']]);
        $out  = $this->node->execute([$item], $this->config(), $this->registerContext);

        $this->assertSame(['file' => ['mimeType' => 'text/plain']], $out[0]['binary']);

    }//end testBinaryAttachmentsSurviveTheWrite()


    public function testNoItemsInMeansNoWrites(): void
    {
        $this->objects->expects($this->never())->method('saveObject');

        $this->assertSame([], $this->node->execute([], $this->config(), $this->registerContext));

    }//end testNoItemsInMeansNoWrites()


    // ── Templating (REQ-OWN-006, REQ-OWN-011) ───────────────────────────


    /**
     * @return array<string, mixed> The payload the node would have written.
     */
    private function payloadFor(array $fields, array $json, array $extraConfig=[]): array
    {
        $payload = null;
        $this->objects->method('saveObject')->willReturnCallback(
            function (mixed $object) use (&$payload): ObjectEntity {
                $payload = $object;

                return $this->entity('uuid-1', []);
            }
        );

        $this->node->execute(
            $this->items([$json]),
            $this->config(array_merge(['fields' => $fields], $extraConfig)),
            $this->registerContext
        );

        return (array) $payload;

    }//end payloadFor()


    public function testADottedPathIsSubstituted(): void
    {
        $payload = $this->payloadFor(['title' => '{{contact.name}}'], ['contact' => ['name' => 'Alpha']]);

        $this->assertSame('Alpha', $payload['title']);

    }//end testADottedPathIsSubstituted()


    public function testAWholeValueTemplateKeepsItsType(): void
    {
        $payload = $this->payloadFor(
            ['tags' => '{{tags}}', 'count' => '{{count}}', 'flag' => '{{flag}}'],
            ['tags' => ['a', 'b'], 'count' => 7, 'flag' => false]
        );

        $this->assertSame(['a', 'b'], $payload['tags'], 'an array stays an array');
        $this->assertSame(7, $payload['count'], 'a number stays a number');
        $this->assertFalse($payload['flag'], 'a boolean stays a boolean');

    }//end testAWholeValueTemplateKeepsItsType()


    public function testALiteralValueIsPassedThrough(): void
    {
        $payload = $this->payloadFor(
            ['source' => 'hydra-console', 'weight' => 3, 'nested' => ['a' => 1], 'cleared' => null],
            []
        );

        $this->assertSame('hydra-console', $payload['source']);
        $this->assertSame(3, $payload['weight']);
        $this->assertSame(['a' => 1], $payload['nested']);
        $this->assertArrayHasKey('cleared', $payload, 'an authored literal null is how a property is cleared');
        $this->assertNull($payload['cleared']);

    }//end testALiteralValueIsPassedThrough()


    public function testAnInlineTemplateStringifiesAroundItsLiteralText(): void
    {
        $payload = $this->payloadFor(['label' => 'run {{id}} of {{total}}'], ['id' => 4, 'total' => 9]);

        $this->assertSame('run 4 of 9', $payload['label']);

    }//end testAnInlineTemplateStringifiesAroundItsLiteralText()


    public function testAnUnresolvedPlaceholderOmitsTheKeyAndIsNeverAnEmptyString(): void
    {
        $payload = $this->payloadFor(['title' => '{{missing.path}}', 'kept' => 'yes'], ['other' => 1]);

        $this->assertArrayNotHasKey('title', $payload);
        $this->assertNotSame('', ($payload['title'] ?? null));
        $this->assertSame('yes', $payload['kept'], 'the rest of the mapping still writes');

    }//end testAnUnresolvedPlaceholderOmitsTheKeyAndIsNeverAnEmptyString()


    public function testAPathResolvingToNullOrAnEmptyArrayIsOmittedRatherThanSent(): void
    {
        $payload = $this->payloadFor(
            ['nested' => '{{nested}}', 'empty' => '{{empty}}'],
            ['nested' => null, 'empty' => []]
        );

        $this->assertArrayNotHasKey('nested', $payload, 'neither {} nor null is sent for a nested object property');
        $this->assertArrayNotHasKey('empty', $payload);

    }//end testAPathResolvingToNullOrAnEmptyArrayIsOmittedRatherThanSent()


    public function testOnMissingFailFailsTheItemInsteadOfOmitting(): void
    {
        $this->objects->expects($this->never())->method('saveObject');

        $this->expectException(RuntimeException::class);

        $this->node->execute(
            $this->items([['other' => 1]]),
            $this->config(['fields' => ['title' => '{{missing.path}}'], 'onMissing' => 'fail']),
            $this->registerContext
        );

    }//end testOnMissingFailFailsTheItemInsteadOfOmitting()


    // ── Composite matching (REQ-OWN-014) ────────────────────────────────


    /**
     * Wire `findAll()` to return whichever entities the filters select from a
     * small in-memory table, so the ANDing is exercised rather than asserted.
     *
     * @param array<int, array{uuid: string, data: array<string, mixed>}> $table
     */
    private function tableOf(array $table): void
    {
        $this->objects->method('findAll')->willReturnCallback(
            function (array $config=[]) use ($table): array {
                $filters = (array) ($config['filters'] ?? []);
                unset($filters['register'], $filters['schema']);

                $hits = [];
                foreach ($table as $row) {
                    foreach ($filters as $property => $value) {
                        if (($row['data'][$property] ?? null) !== $value) {
                            continue 2;
                        }
                    }

                    $hits[] = $this->entity($row['uuid'], $row['data']);
                }

                return $hits;
            }
        );

    }//end tableOf()


    public function testTwoPairsAreAndedAndNarrowToOneObject(): void
    {
        $this->tableOf(
            [
                ['uuid' => 'u-nl', 'data' => ['sourceId' => 's1', 'tenant' => 'nl']],
                ['uuid' => 'u-be', 'data' => ['sourceId' => 's1', 'tenant' => 'be']],
            ]
        );

        $patched = null;
        $this->objects->method('patchObject')->willReturnCallback(
            function (string $objectId) use (&$patched): ObjectEntity {
                $patched = $objectId;

                return $this->entity($objectId, []);
            }
        );

        $this->node->execute(
            $this->items([['tenant' => 'be']]),
            $this->config(
                [
                    'operation' => 'update',
                    'fields'    => ['status' => 'seen'],
                    'match'     => [
                        ['property' => 'sourceId', 'value' => 's1'],
                        ['property' => 'tenant', 'value' => '{{tenant}}'],
                    ],
                ]
            ),
            $this->registerContext
        );

        $this->assertSame('u-be', $patched, 'pairs are ANDed, never ORed');

    }//end testTwoPairsAreAndedAndNarrowToOneObject()


    public function testAnAmbiguousMatchFailsNamingTheCountAndWritesNothing(): void
    {
        $this->tableOf(
            [
                ['uuid' => 'u-1', 'data' => ['sourceId' => 's1']],
                ['uuid' => 'u-2', 'data' => ['sourceId' => 's1']],
                ['uuid' => 'u-3', 'data' => ['sourceId' => 's1']],
            ]
        );

        $this->objects->expects($this->never())->method('patchObject');
        $this->objects->expects($this->never())->method('saveObject');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/\b3\b/');

        $this->node->execute(
            $this->items([[]]),
            $this->config(
                [
                    'operation' => 'update',
                    'fields'    => ['status' => 'seen'],
                    'match'     => [['property' => 'sourceId', 'value' => 's1']],
                ]
            ),
            $this->registerContext
        );

    }//end testAnAmbiguousMatchFailsNamingTheCountAndWritesNothing()


    public function testAnUpdateThatMatchesNothingIsAnErrorNotASilentInsert(): void
    {
        $this->tableOf([]);

        $this->objects->expects($this->never())->method('saveObject');
        $this->objects->expects($this->never())->method('patchObject');

        $this->expectException(RuntimeException::class);

        $this->node->execute(
            $this->items([[]]),
            $this->config(
                [
                    'operation' => 'update',
                    'fields'    => ['status' => 'seen'],
                    'match'     => [['property' => 'sourceId', 'value' => 's1']],
                ]
            ),
            $this->registerContext
        );

    }//end testAnUpdateThatMatchesNothingIsAnErrorNotASilentInsert()


    public function testAnUnresolvedPlaceholderInAMatchValueAlwaysFailsTheItem(): void
    {
        $this->tableOf([['uuid' => 'u-1', 'data' => ['sourceId' => 's1']]]);

        $this->objects->expects($this->never())->method('patchObject');

        $this->expectException(RuntimeException::class);

        // `onMissing` is the default `omit`; a match key is still never omitted,
        // because omitting one widens the match rather than narrowing it.
        $this->node->execute(
            $this->items([['sourceId' => 's1']]),
            $this->config(
                [
                    'operation' => 'update',
                    'fields'    => ['status' => 'seen'],
                    'match'     => [
                        ['property' => 'sourceId', 'value' => '{{sourceId}}'],
                        ['property' => 'tenant', 'value' => '{{missing.tenant}}'],
                    ],
                ]
            ),
            $this->registerContext
        );

    }//end testAnUnresolvedPlaceholderInAMatchValueAlwaysFailsTheItem()


    // ── Upsert and replace (REQ-OWN-002, REQ-OWN-005) ───────────────────


    public function testUpsertInsertsWhenNothingMatches(): void
    {
        $this->tableOf([]);
        $this->objects->expects($this->never())->method('patchObject');
        $this->objects->expects($this->once())->method('saveObject')->willReturn($this->entity('new', []));

        $out = $this->node->execute(
            $this->items([['id' => 'x']]),
            $this->config(
                [
                    'operation' => 'upsert',
                    'fields'    => ['sourceId' => '{{id}}'],
                    'match'     => [['property' => 'sourceId', 'value' => '{{id}}']],
                ]
            ),
            $this->registerContext
        );

        $this->assertCount(1, $out);

    }//end testUpsertInsertsWhenNothingMatches()


    public function testUpsertPatchesWhenAMatchExists(): void
    {
        $this->tableOf([['uuid' => 'u-1', 'data' => ['sourceId' => 'x', 'title' => 'Alpha']]]);

        $this->objects->expects($this->never())->method('saveObject');
        $this->objects->expects($this->once())
            ->method('patchObject')
            ->willReturn($this->entity('u-1', ['sourceId' => 'x', 'title' => 'Alpha']));

        $this->node->execute(
            $this->items([['id' => 'x']]),
            $this->config(
                [
                    'operation' => 'upsert',
                    'fields'    => ['sourceId' => '{{id}}'],
                    'match'     => [['property' => 'sourceId', 'value' => '{{id}}']],
                ]
            ),
            $this->registerContext
        );

    }//end testUpsertPatchesWhenAMatchExists()


    public function testAnUpdateGoesThroughPatchObjectSoUnmappedFieldsSurvive(): void
    {
        $this->tableOf([['uuid' => 'u-1', 'data' => ['sourceId' => 'x', 'title' => 'Alpha']]]);

        $seen = null;
        $this->objects->expects($this->never())->method('saveObject');
        $this->objects->method('patchObject')->willReturnCallback(
            function (string $objectId, array $data, mixed $register=null, mixed $schema=null, bool $_rbac=true, bool $_multitenancy=true, ?IUser $currentUser=null) use (&$seen): ObjectEntity {
                $seen = compact('objectId', 'data', 'register', 'schema', '_rbac', '_multitenancy', 'currentUser');

                return $this->entity($objectId, ['sourceId' => 'x', 'title' => 'Alpha', 'status' => 'seen']);
            }
        );

        $out = $this->node->execute(
            $this->items([['id' => 'x']]),
            $this->config(
                [
                    'operation' => 'update',
                    'fields'    => ['status' => 'seen'],
                    'match'     => [['property' => 'sourceId', 'value' => '{{id}}']],
                ]
            ),
            $this->registerContext
        );

        $this->assertSame('u-1', $seen['objectId']);
        $this->assertSame(['status' => 'seen'], $seen['data'], 'only the mapped fields are sent; the merge is the service\'s job');
        $this->assertSame($this->register, $seen['register']);
        $this->assertSame($this->schema, $seen['schema']);
        $this->assertTrue($seen['_rbac']);
        $this->assertTrue($seen['_multitenancy']);
        $this->assertSame('alice', $seen['currentUser']->getUID());
        $this->assertSame('Alpha', $out[0]['json']['title'], 'the unmapped field is still there');

    }//end testAnUpdateGoesThroughPatchObjectSoUnmappedFieldsSurvive()


    public function testReplaceTrueBypassesPatchObjectAndGoesThroughSaveObject(): void
    {
        $this->tableOf([['uuid' => 'u-1', 'data' => ['sourceId' => 'x', 'title' => 'Alpha']]]);

        $seenUuid = null;
        $this->objects->expects($this->never())->method('patchObject');
        $this->objects->method('saveObject')->willReturnCallback(
            function (mixed $object, ?array $extend=[], mixed $register=null, mixed $schema=null, ?string $uuid=null) use (&$seenUuid): ObjectEntity {
                $seenUuid = $uuid;

                return $this->entity('u-1', $object);
            }
        );

        $this->node->execute(
            $this->items([['id' => 'x']]),
            $this->config(
                [
                    'operation' => 'update',
                    'replace'   => true,
                    'fields'    => ['status' => 'seen'],
                    'match'     => [['property' => 'sourceId', 'value' => '{{id}}']],
                ]
            ),
            $this->registerContext
        );

        $this->assertSame('u-1', $seenUuid);

    }//end testReplaceTrueBypassesPatchObjectAndGoesThroughSaveObject()


    // ── Delete and its guards (REQ-OWN-012, REQ-OWN-007) ────────────────


    /**
     * @return array<string, mixed>
     */
    private function deleteConfig(array $overrides=[]): array
    {
        return array_merge(
            [
                'register'      => 'example-hydra-cache',
                'schema'        => 'example-cache-entry',
                'operation'     => 'delete',
                'confirmDelete' => true,
                'match'         => [['property' => 'sourceId', 'value' => '{{retiredId}}']],
            ],
            $overrides
        );

    }//end deleteConfig()


    public function testADeleteRemovesExactlyTheMatchedObjectThroughTheOrdinaryPath(): void
    {
        $this->tableOf(
            [
                ['uuid' => 'u-gone', 'data' => ['sourceId' => 'r1']],
                ['uuid' => 'u-stays', 'data' => ['sourceId' => 'r2']],
            ]
        );

        $seen = null;
        $this->objects->method('deleteObject')->willReturnCallback(
            function (string $uuid, mixed $register=null, mixed $schema=null, bool $_rbac=true, bool $_multitenancy=true, bool $_retentionSweep=false, ?IUser $currentUser=null) use (&$seen): bool {
                $seen = compact('uuid', 'register', 'schema', '_rbac', '_multitenancy', '_retentionSweep', 'currentUser');

                return true;
            }
        );

        $out = $this->node->execute(
            $this->items([['retiredId' => 'r1']]),
            $this->deleteConfig(),
            $this->registerContext
        );

        $this->assertSame('u-gone', $seen['uuid']);
        $this->assertSame($this->register, $seen['register'], 'the delete is scoped to one magic table');
        $this->assertSame($this->schema, $seen['schema']);
        $this->assertTrue($seen['_rbac']);
        $this->assertTrue($seen['_multitenancy']);
        $this->assertFalse($seen['_retentionSweep'], 'a flow is not the retention cron');
        $this->assertSame('alice', $seen['currentUser']->getUID(), 'the delete is attributed to the run owner');

        $this->assertSame('u-gone', $out[0]['json']['uuid']);
        $this->assertTrue($out[0]['json']['deleted']);

    }//end testADeleteRemovesExactlyTheMatchedObjectThroughTheOrdinaryPath()


    public function testADeleteMatchingNothingErrorsByDefault(): void
    {
        $this->tableOf([]);
        $this->objects->expects($this->never())->method('deleteObject');

        $this->expectException(RuntimeException::class);

        $this->node->execute($this->items([['retiredId' => 'r1']]), $this->deleteConfig(), $this->registerContext);

    }//end testADeleteMatchingNothingErrorsByDefault()


    public function testASkippedDeleteIsDistinguishableFromAPerformedOne(): void
    {
        $this->tableOf([]);
        $this->objects->expects($this->never())->method('deleteObject');

        $out = $this->node->execute(
            $this->items([['retiredId' => 'r1']]),
            $this->deleteConfig(['onNoMatch' => 'skip']),
            $this->registerContext
        );

        $this->assertFalse($out[0]['json']['deleted']);
        $this->assertSame('r1', $out[0]['json']['retiredId'], 'the input record is carried through');

    }//end testASkippedDeleteIsDistinguishableFromAPerformedOne()


    /**
     * `config.output` on a delete used to be read by nothing.
     *
     * `outputJson()` is what honours the key, and it was only ever called on the
     * non-delete path — `executeDelete()` was never handed the config. So a
     * delete was the one operation that could not carry its incoming record
     * forward: everything the item was holding (the issue number, the repo, the
     * run id) was dropped the moment something was removed.
     *
     * @return void
     */
    public function testADeleteHonoursTheOutputKeyAndKeepsTheIncomingRecord(): void
    {
        $this->tableOf([['uuid' => 'u-gone', 'data' => ['sourceId' => 'r1']]]);
        $this->objects->method('deleteObject')->willReturn(true);

        $out = $this->node->execute(
            $this->items([['retiredId' => 'r1', 'issue' => 489]]),
            $this->deleteConfig(['output' => 'removed']),
            $this->registerContext
        );

        $json = $out[0]['json'];

        $this->assertSame(489, $json['issue'], 'the incoming record survives the delete');
        $this->assertSame('r1', $json['retiredId']);
        $this->assertSame('u-gone', $json['removed']['uuid'], 'the delete record lands under the output key');
        $this->assertTrue($json['removed']['deleted']);

    }//end testADeleteHonoursTheOutputKeyAndKeepsTheIncomingRecord()


    /**
     * POSITIVE CONTROL — with no `output`, the shape is exactly what it was.
     *
     * The fix must not change any flow that does not ask for one.
     *
     * @return void
     */
    public function testADeleteWithoutAnOutputKeyIsUnchanged(): void
    {
        $this->tableOf([['uuid' => 'u-gone', 'data' => ['sourceId' => 'r1']]]);
        $this->objects->method('deleteObject')->willReturn(true);

        $out = $this->node->execute(
            $this->items([['retiredId' => 'r1', 'issue' => 489]]),
            $this->deleteConfig(),
            $this->registerContext
        );

        $json = $out[0]['json'];

        $this->assertSame('u-gone', $json['uuid']);
        $this->assertTrue($json['deleted']);
        $this->assertArrayNotHasKey('issue', $json, 'unchanged: without output, the delete record IS the item');
        $this->assertArrayNotHasKey('removed', $json);

    }//end testADeleteWithoutAnOutputKeyIsUnchanged()


    /**
     * A SKIPPED delete gets the output key too.
     *
     * An output key that exists only on the branch that removed something makes
     * `{{removed.deleted}}` resolve on some items and not others — a downstream
     * null with no visible cause.
     *
     * @return void
     */
    public function testASkippedDeleteAlsoCarriesTheOutputKey(): void
    {
        $this->tableOf([]);
        $this->objects->expects($this->never())->method('deleteObject');

        $out = $this->node->execute(
            $this->items([['retiredId' => 'r1', 'issue' => 489]]),
            $this->deleteConfig(['onNoMatch' => 'skip', 'output' => 'removed']),
            $this->registerContext
        );

        $json = $out[0]['json'];

        $this->assertFalse($json['deleted'], 'the top-level flag stays where it was');
        $this->assertSame('r1', $json['retiredId']);
        $this->assertFalse($json['removed']['deleted']);

    }//end testASkippedDeleteAlsoCarriesTheOutputKey()


    public function testAnAmbiguousDeleteMatchFailsRatherThanChoosing(): void
    {
        $this->tableOf(
            [
                ['uuid' => 'u-1', 'data' => ['sourceId' => 'r1']],
                ['uuid' => 'u-2', 'data' => ['sourceId' => 'r1']],
            ]
        );

        $this->objects->expects($this->never())->method('deleteObject');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/\b2\b/');

        $this->node->execute($this->items([['retiredId' => 'r1']]), $this->deleteConfig(), $this->registerContext);

    }//end testAnAmbiguousDeleteMatchFailsRatherThanChoosing()


    public function testAServiceRefusalIsNeverSwallowedIntoAHollowSuccess(): void
    {
        $this->tableOf([['uuid' => 'u-1', 'data' => ['sourceId' => 'r1']]]);

        $this->objects->method('deleteObject')->willThrowException(
            new RuntimeException('APPEND_ONLY: schema refuses a delete')
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('APPEND_ONLY');

        $this->node->execute($this->items([['retiredId' => 'r1']]), $this->deleteConfig(), $this->registerContext);

    }//end testAServiceRefusalIsNeverSwallowedIntoAHollowSuccess()


    // ── The per-step write cap (REQ-OWN-015) ────────────────────────────


    public function testAStepUnderItsCapWritesNormally(): void
    {
        $this->objects->expects($this->exactly(3))->method('saveObject')->willReturn($this->entity('u', []));

        $out = $this->node->execute(
            $this->items([['n' => 1], ['n' => 2], ['n' => 3]]),
            $this->config(['maxWrites' => 10]),
            $this->registerContext
        );

        $this->assertCount(3, $out);

    }//end testAStepUnderItsCapWritesNormally()


    public function testExceedingTheCapFailsNamingTheCapAndTheWritesPerformed(): void
    {
        $writes = 0;
        $this->objects->method('saveObject')->willReturnCallback(
            function () use (&$writes): ObjectEntity {
                $writes++;

                return $this->entity('u-'.$writes, []);
            }
        );

        $records = [];
        for ($i = 0; $i < 50; $i++) {
            $records[] = ['n' => $i];
        }

        try {
            $this->node->execute($this->items($records), $this->config(['maxWrites' => 10]), $this->registerContext);
            $this->fail('The step should not have completed: it was asked for fifty writes under a cap of ten.');
        } catch (RuntimeException $e) {
            $this->assertMatchesRegularExpression('/\b10\b/', $e->getMessage(), 'the error names the cap');
            $this->assertSame(10, $writes, 'exactly the cap was written; the rest were not');
        }

    }//end testExceedingTheCapFailsNamingTheCapAndTheWritesPerformed()


    public function testTheCapIsNeverSilentlyTruncatedIntoASuccessfulItemList(): void
    {
        $this->objects->method('saveObject')->willReturn($this->entity('u', []));

        $records = [];
        for ($i = 0; $i < 12; $i++) {
            $records[] = ['n' => $i];
        }

        $result = null;
        try {
            $result = $this->node->execute($this->items($records), $this->config(['maxWrites' => 5]), $this->registerContext);
        } catch (RuntimeException $e) {
            $this->addToAssertionCount(1);
        }

        $this->assertNull($result, 'no item list is returned at all — a truncated list would read as success');

    }//end testTheCapIsNeverSilentlyTruncatedIntoASuccessfulItemList()


    public function testDeletesCountAgainstTheCapToo(): void
    {
        $this->tableOf([['uuid' => 'u-1', 'data' => ['sourceId' => 'r1']]]);

        $deletes = 0;
        $this->objects->method('deleteObject')->willReturnCallback(
            static function () use (&$deletes): bool {
                $deletes++;

                return true;
            }
        );

        $records = array_fill(0, 20, ['retiredId' => 'r1']);

        $this->expectException(RuntimeException::class);
        try {
            $this->node->execute($this->items($records), $this->deleteConfig(['maxWrites' => 5]), $this->registerContext);
        } finally {
            $this->assertSame(5, $deletes, 'the sixth delete is refused');
        }

    }//end testDeletesCountAgainstTheCapToo()


    public function testTheInstanceDefaultAppliesWhenNoCapIsConfigured(): void
    {
        $seenDefault = null;

        $appConfig = $this->createMock(IAppConfig::class);
        $appConfig->expects($this->once())
            ->method('getValueInt')
            ->willReturnCallback(
                function (string $app, string $key, int $default=0) use (&$seenDefault): int {
                    $seenDefault = $default;

                    return $default;
                }
            );

        $registers = $this->createMock(RegisterMapper::class);
        $registers->method('find')->willReturn($this->register);
        $schemas = $this->createMock(SchemaMapper::class);
        $schemas->method('findBySlugInIds')->willReturn($this->schema);

        $l10n = $this->createMock(IL10N::class);
        $l10n->method('t')->willReturnArgument(0);

        $this->objects->method('saveObject')->willReturn($this->entity('u', []));

        $node = new ObjectWriteNode(
            $this->objects,
            $registers,
            $schemas,
            $this->userManager,
            $appConfig,
            $l10n,
            $this->createMock(IURLGenerator::class)
        );

        $node->execute($this->items([['n' => 1]]), $this->config(), $this->registerContext);

        $this->assertSame(1000, $seenDefault, 'the shipped default cap is 1000 writes per step execution');

    }//end testTheInstanceDefaultAppliesWhenNoCapIsConfigured()


    /**
     * With `output` set, the item's record SURVIVES and the write lands beside it.
     *
     * Replacing the record is fine for a write that ends a branch and wrong for
     * one in the middle of a chain — a per-issue lock is exactly the second
     * shape, because the run still needs the repo and the issue to do the work
     * the lock protects. Measured while building hydra's sequencer: after the
     * lock write, `{{repo}}` rendered empty and the next call went to
     * `/repos//issues`.
     *
     * @return void
     */
    public function testAnOutputKeyPreservesTheIncomingRecord(): void
    {
        $this->objects->method('saveObject')->willReturnCallback(
            function (mixed $object, ?array $extend=[], mixed $register=null, mixed $schema=null, ?string $uuid=null, bool $_rbac=true, bool $_multitenancy=true, bool $silent=false, ?array $uploadedFiles=null, ?IUser $currentUser=null): ObjectEntity {
                return $this->entity('uuid-1', $object);
            }
        );

        $out = $this->node->execute(
            $this->items([['repo' => 'ConductionNL/hydra', 'issue' => 410]]),
            $this->config(['output' => 'lock', 'fields' => ['title' => '{{repo}}']]),
            $this->registerContext
        );

        // What the run was carrying is still there.
        $this->assertSame('ConductionNL/hydra', $out[0]['json']['repo']);
        $this->assertSame(410, $out[0]['json']['issue']);

        // And the written object is beside it, under the named key.
        $this->assertSame('uuid-1', $out[0]['json']['lock']['uuid']);
        $this->assertSame('ConductionNL/hydra', $out[0]['json']['lock']['title']);
    }

    /**
     * WITHOUT `output`, the written object still replaces the record.
     *
     * The historical behaviour stays the default: changing it silently would
     * rewrite what every existing flow sees downstream of a write.
     *
     * @return void
     */
    public function testWithoutAnOutputKeyTheWrittenObjectStillReplacesTheRecord(): void
    {
        $this->objects->method('saveObject')->willReturnCallback(
            function (mixed $object, ?array $extend=[], mixed $register=null, mixed $schema=null, ?string $uuid=null, bool $_rbac=true, bool $_multitenancy=true, bool $silent=false, ?array $uploadedFiles=null, ?IUser $currentUser=null): ObjectEntity {
                return $this->entity('uuid-1', $object);
            }
        );

        $out = $this->node->execute(
            $this->items([['repo' => 'ConductionNL/hydra']]),
            $this->config(['fields' => ['title' => 'literal']]),
            $this->registerContext
        );

        $this->assertArrayNotHasKey('repo', $out[0]['json']);
        $this->assertSame('uuid-1', $out[0]['json']['uuid']);
    }

}//end class
