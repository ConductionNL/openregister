<?php

/**
 * OpenRegister lifecycle auto-seed-on-create tests
 *
 * Covers SaveObject::seedLifecycleFieldOnCreate added by the
 * fk-graph-lifecycle-transitions change: seeds the lifecycle field from the
 * parent when empty, never overwrites a client-supplied value, and fail-soft
 * no-ops when the parent ref is missing, the parent cannot be loaded, or the
 * parent's initial value is empty. The seed dispatches no event (the method
 * has no dispatcher dependency at all).
 *
 * SaveObject has a large constructor; the seed logic only touches
 * `unifiedObjectMapper` + `logger`, so the SUT is built via
 * newInstanceWithoutConstructor with just those two dependencies injected by
 * reflection.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace Unit\Service\Object;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Object\SaveObject;
use OCP\AppFramework\Db\DoesNotExistException;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * @coversDefaultClass \OCA\OpenRegister\Service\Object\SaveObject
 */
class LifecycleSeedOnCreateTest extends TestCase
{
    private const PARENT = '00000000-0000-0000-0000-000000000000';

    private const S1 = '00000000-0000-0000-0000-000000000001';

    private const S2 = '00000000-0000-0000-0000-000000000002';

    private MagicMapper&MockObject $mapper;

    private SaveObject $sut;

    protected function setUp(): void
    {
        $this->mapper = $this->createMock(MagicMapper::class);
        $logger       = $this->createMock(LoggerInterface::class);

        $reflection = new ReflectionClass(SaveObject::class);
        $this->sut  = $reflection->newInstanceWithoutConstructor();

        $mapperProp = $reflection->getProperty('unifiedObjectMapper');
        $mapperProp->setValue($this->sut, $this->mapper);

        $loggerProp = $reflection->getProperty('logger');
        $loggerProp->setValue($this->sut, $logger);
    }//end setUp()

    /**
     * Schema mock whose annotation declares the object-form initial + graph.
     */
    private function schema(): Schema&MockObject
    {
        $schema = $this->createMock(Schema::class);
        $schema->method('getConfiguration')->willReturn(
            [
                'x-openregister-lifecycle' => [
                    'field'   => 'status',
                    'initial' => ['from' => 'caseType', 'field' => 'initialStatus'],
                    'graph'   => [
                        'schema'       => 'statustype',
                        'parentField'  => 'caseType',
                        'parentFrom'   => 'caseType',
                        'orderField'   => 'order',
                        'finalField'   => 'isFinal',
                        'allowedMoves' => 'forward',
                    ],
                ],
            ]
        );
        return $schema;
    }//end schema()

    private function parent(string $initialStatus): ObjectEntity
    {
        $entity = new ObjectEntity();
        $entity->setUuid(self::PARENT);
        $entity->setObject(['initialStatus' => $initialStatus]);
        return $entity;
    }//end parent()

    public function testSeedsWhenFieldEmpty(): void
    {
        $this->mapper->expects($this->once())
            ->method('find')
            ->with(identifier: self::PARENT)
            ->willReturn($this->parent(self::S1));

        $result = $this->sut->seedLifecycleFieldOnCreate(
            $this->schema(),
            ['caseType' => self::PARENT]
        );

        $this->assertSame(self::S1, $result['status']);
    }//end testSeedsWhenFieldEmpty()

    public function testDoesNotOverwriteProvidedValue(): void
    {
        // A client-supplied status wins; the parent is never even loaded.
        $this->mapper->expects($this->never())->method('find');

        $result = $this->sut->seedLifecycleFieldOnCreate(
            $this->schema(),
            ['caseType' => self::PARENT, 'status' => self::S2]
        );

        $this->assertSame(self::S2, $result['status']);
    }//end testDoesNotOverwriteProvidedValue()

    public function testNoOpWhenParentRefMissing(): void
    {
        $this->mapper->expects($this->never())->method('find');

        $result = $this->sut->seedLifecycleFieldOnCreate(
            $this->schema(),
            ['caseType' => '']
        );

        $this->assertArrayNotHasKey('status', $result);
    }//end testNoOpWhenParentRefMissing()

    public function testNoOpWhenParentCannotBeLoaded(): void
    {
        $this->mapper->method('find')->willThrowException(new DoesNotExistException('nope'));

        $result = $this->sut->seedLifecycleFieldOnCreate(
            $this->schema(),
            ['caseType' => self::PARENT]
        );

        $this->assertArrayNotHasKey('status', $result);
    }//end testNoOpWhenParentCannotBeLoaded()

    public function testNoOpWhenParentInitialValueEmpty(): void
    {
        $this->mapper->method('find')->willReturn($this->parent(''));

        $result = $this->sut->seedLifecycleFieldOnCreate(
            $this->schema(),
            ['caseType' => self::PARENT]
        );

        $this->assertArrayNotHasKey('status', $result);
    }//end testNoOpWhenParentInitialValueEmpty()

    /**
     * The seed is driven ONLY by the object-form `initial`; a legacy
     * literal-string `initial` (static mode) is never auto-seeded.
     */
    public function testLiteralStringInitialIsNotSeeded(): void
    {
        $this->mapper->expects($this->never())->method('find');

        $schema = $this->createMock(Schema::class);
        $schema->method('getConfiguration')->willReturn(
            [
                'x-openregister-lifecycle' => [
                    'field'       => 'status',
                    'initial'     => 'draft',
                    'transitions' => ['open' => ['from' => ['draft'], 'to' => 'open']],
                ],
            ]
        );

        $result = $this->sut->seedLifecycleFieldOnCreate($schema, ['caseType' => self::PARENT]);

        $this->assertArrayNotHasKey('status', $result);
    }//end testLiteralStringInitialIsNotSeeded()
}//end class
