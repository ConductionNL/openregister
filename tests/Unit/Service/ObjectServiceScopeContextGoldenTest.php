<?php

/**
 * Pins what ObjectService forwards once it has set its register and schema context.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Service\Object\SaveObject\StreamingSave;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCA\OpenRegister\Service\Object\GetObject;
use OCA\OpenRegister\Service\Object\RenderObject;
use OCA\OpenRegister\Service\Object\SaveObject;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\RegisterScopedSchemaResolver;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;

/**
 * @covers \OCA\OpenRegister\Service\ObjectService
 * @uses   \OCA\OpenRegister\Service\Object\AccessFlags
 * @uses   \OCA\OpenRegister\Service\Object\ObjectSaveRun
 * @uses   \OCA\OpenRegister\Db\Register
 * @uses   \OCA\OpenRegister\Db\Schema
 */
class ObjectServiceScopeContextGoldenTest extends TestCase
{
    /**
     * Build a service without its constructor, holding only the given collaborators.
     *
     * @param array<string, mixed> $properties Property values by name.
     *
     * @return ObjectService The service.
     */
    private function serviceWith(array $properties): ObjectService
    {
        $service = (new ReflectionClass(ObjectService::class))->newInstanceWithoutConstructor();
        $this->inject($service, $properties);

        return $service;
    }

    /**
     * Set private properties of a service.
     *
     * @param ObjectService        $service    The service.
     * @param array<string, mixed> $properties Property values by name.
     *
     * @return void
     */
    private function inject(ObjectService $service, array $properties): void
    {
        foreach ($properties as $name => $value) {
            (new ReflectionProperty(ObjectService::class, $name))->setValue($service, $value);
        }
    }

    /**
     * A renderer that answers with a fixed list.
     *
     * @return RenderObject The renderer.
     */
    private function renderer(): RenderObject
    {
        $render = $this->createMock(RenderObject::class);
        $render->method('renderEntities')->willReturn(['rendered']);

        return $render;
    }

    public function testFindAllForwardsEverySelectionKeyByName(): void
    {
        $captured = [];
        $handler  = $this->createMock(GetObject::class);
        $handler->expects($this->once())->method('findAll')->willReturnCallback(
            function () use (&$captured): array {
                $names = array_map(
                    static fn ($parameter): string => $parameter->getName(),
                    (new ReflectionMethod(GetObject::class, 'findAll'))->getParameters()
                );
                $captured = array_combine(array_slice($names, 0, func_num_args()), func_get_args());
                return [];
            }
        );

        $service = $this->serviceWith(['getHandler' => $handler, 'renderHandler' => $this->renderer()]);
        $config  = [
            'limit'   => 5,
            'offset'  => 10,
            'filters' => ['status' => 'open'],
            'sort'    => ['title' => 'asc'],
            'search'  => 'term',
            'files'   => true,
            'uses'    => 'uses-id',
            'ids'     => ['a', 'b'],
        ];

        $this->assertSame(['rendered'], $service->findAll($config, false, false));

        $request   = $captured['request'];
        $forwarded = [
            'limit'   => $request->query->limit,
            'offset'  => $request->query->offset,
            'filters' => $request->query->filters,
            'sort'    => $request->query->sort,
            'search'  => $request->query->search,
            'files'   => $request->files,
            'uses'    => $request->selection->uses,
            'ids'     => $request->selection->ids,
        ];
        foreach ($config as $key => $value) {
            $this->assertSame($value, $forwarded[$key], $key);
        }

        $this->assertFalse($captured['_rbac']);
        $this->assertFalse($captured['_multitenancy']);
    }

    /**
     * @dataProvider namesNothingProvider
     */
    public function testFindAllSetsNoContextFromAFilterThatNamesNothing(mixed $value): void
    {
        $registers = $this->createMock(RegisterMapper::class);
        $registers->expects($this->never())->method('find');
        $schemas = $this->createMock(SchemaMapper::class);
        $schemas->expects($this->never())->method('find');

        $service = $this->serviceWith(
            [
                'getHandler'     => $this->createMock(GetObject::class),
                'renderHandler'  => $this->renderer(),
                'registerMapper' => $registers,
                'schemaMapper'   => $schemas,
            ]
        );

        $this->assertSame(['rendered'], $service->findAll(['filters' => ['register' => $value, 'schema' => $value]]));
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function namesNothingProvider(): array
    {
        return ['list' => [['1', '2']], 'empty string' => [''], 'zero' => [0], 'empty list' => [[]]];
    }

    public function testSlugSearchTargetWinsOverACallerSelfBlock(): void
    {
        $register = new Register();
        $register->setId(7);
        $register->setSchemas([9]);
        $schema = new Schema();
        $schema->setId(9);

        $registers = $this->createMock(RegisterMapper::class);
        $registers->method('find')->willReturn($register);
        $schemas = $this->createMock(SchemaMapper::class);
        $schemas->method('findBySlugInIds')->willReturn($schema);

        $service = $this->getMockBuilder(ObjectService::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['searchObjects'])
            ->getMock();
        $this->inject($service, ['registerMapper' => $registers, 'schemaMapper' => $schemas]);

        $service->expects($this->once())->method('searchObjects')
            ->with(
                $this->callback(
                    function (array $query): bool {
                        $this->assertSame(
                            ['register' => 7, 'schema' => 9, 'owner' => 'kept'],
                            [
                                'register' => $query['@self']['register'],
                                'schema'   => $query['@self']['schema'],
                                'owner'    => $query['@self']['owner'],
                            ]
                        );
                        return true;
                    }
                )
            )
            ->willReturn([]);

        $service->searchObjectsBySlug('r', 's', ['@self' => ['register' => 1, 'schema' => 2, 'owner' => 'kept']]);
    }

    public function testFindSilentDropsAPendingRefBeforeItSetsItsRegister(): void
    {
        $registers = $this->createMock(RegisterMapper::class);
        $registers->method('find')->willReturn(new Register());
        $resolver = $this->createMock(RegisterScopedSchemaResolver::class);
        $resolver->expects($this->never())->method('resolveSchemaWithin');
        $handler = $this->createMock(GetObject::class);
        $handler->method('findSilent')->willReturn($this->createMock(\OCA\OpenRegister\Db\ObjectEntity::class));

        $service = $this->serviceWith(
            [
                'getHandler'           => $handler,
                'registerMapper'       => $registers,
                'scopedSchemaResolver' => $resolver,
                'currentRegister'      => null,
                'currentSchema'        => null,
                'currentSchemaRef'     => 'left-behind',
            ]
        );

        $service->findSilent('id', options: new ObjectFindOptions(register: 'named'));
    }

    public function testStreamingSaveClearsTheReferenceCacheBeforeItStreams(): void
    {
        $calls = [];
        $save  = $this->createMock(SaveObject::class);
        $save->expects($this->once())->method('clearReferenceValidationCache')->willReturnCallback(
            function () use (&$calls): void {
                $calls[] = 'clear';
            }
        );
        $status = (new ReflectionMethod(SaveObject::class, 'saveObjectsStreaming'))->getReturnType()->getName();
        $save->expects($this->once())->method('saveObjectsStreaming')->willReturnCallback(
            function () use (&$calls, $status) {
                $calls[] = 'stream';
                return $this->createMock($status);
            }
        );

        $service = $this->serviceWith(
            ['saveHandler' => $save, 'currentRegister' => null, 'currentSchema' => null, 'currentSchemaRef' => null]
        );

        $service->saveObjectsStreaming(new StreamingSave(null, null, [['title' => 'row']]));

        $this->assertSame(['clear', 'stream'], $calls);
    }
}
