<?php

/**
 * Tests for the mapping node.
 *
 * The negative cases carry the weight here. A transformation node that fails
 * OPEN — passing its items through when it cannot map them — records a
 * completed step having done nothing, and the resulting error surfaces at some
 * later step reading the un-mapped shape, far from its cause.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Flow\Nodes
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow\Nodes;

use OCA\OpenRegister\Db\Mapping;
use OCA\OpenRegister\Db\MappingMapper;
use OCA\OpenRegister\Service\Flow\FlowItems;
use OCA\OpenRegister\Service\Flow\Nodes\MapNode;
use OCA\OpenRegister\Service\MappingService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IL10N;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use UnexpectedValueException;

/**
 * MapNode behaviour.
 */
class MapNodeTest extends TestCase
{

    /**
     * Evaluates mappings.
     *
     * @var MappingService|\PHPUnit\Framework\MockObject\MockObject
     */
    private $mappings;

    /**
     * Resolves mappings.
     *
     * @var MappingMapper|\PHPUnit\Framework\MockObject\MockObject
     */
    private $mapper;

    /**
     * The node under test.
     *
     * @var MapNode
     */
    private MapNode $node;

    /**
     * Build the node with mocked collaborators.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $l10n = $this->createMock(IL10N::class);
        $l10n->method('t')->willReturnArgument(0);

        $urls = $this->createMock(IURLGenerator::class);
        $urls->method('imagePath')->willReturn('/icon.svg');

        $this->mappings = $this->createMock(MappingService::class);
        $this->mapper   = $this->createMock(MappingMapper::class);

        $this->node = new MapNode(
            l10n: $l10n,
            urls: $urls,
            mappings: $this->mappings,
            mapper: $this->mapper
        );

    }//end setUp()

    /**
     * One item in, one reshaped item out.
     *
     * @return void
     */
    public function testItReshapesEveryItem(): void
    {
        $mapping = $this->createMock(Mapping::class);
        $this->mapper->method('find')->willReturn($mapping);

        $this->mappings->expects($this->once())
            ->method('executeMapping')
            ->willReturn(['b' => 'value']);

        $out = $this->node->execute(
            [FlowItems::item(json: ['a' => 'value'])],
            ['mapping' => '42'],
            []
        );

        $this->assertCount(1, $out);
        $this->assertSame(['b' => 'value'], $out[0][FlowItems::JSON], 'the item must carry the MAPPED shape');
        $this->assertArrayNotHasKey('a', $out[0][FlowItems::JSON], 'the input shape must not survive');

    }//end testItReshapesEveryItem()

    /**
     * The mapping runs once PER ITEM, not once for the list.
     *
     * @return void
     */
    public function testItMapsPerItemNotPerList(): void
    {
        $this->mapper->method('find')->willReturn($this->createMock(Mapping::class));
        $this->mappings->expects($this->exactly(3))
            ->method('executeMapping')
            ->willReturn(['ok' => true]);

        $out = $this->node->execute(
            [
                FlowItems::item(json: ['n' => 1]),
                FlowItems::item(json: ['n' => 2]),
                FlowItems::item(json: ['n' => 3]),
            ],
            ['mapping' => '42'],
            []
        );

        $this->assertCount(3, $out);

    }//end testItMapsPerItemNotPerList()

    /**
     * An unresolvable mapping FAILS. It must not forward the items untouched.
     *
     * @return void
     */
    public function testAnUnresolvableMappingFailsRatherThanPassingThrough(): void
    {
        $this->mapper->method('find')->willThrowException(
            new DoesNotExistException('no such mapping')
        );

        $this->mappings->expects($this->never())
            ->method('executeMapping');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/999/');

        $this->node->execute(
            [FlowItems::item(json: ['a' => 1])],
            ['mapping' => '999'],
            []
        );

    }//end testAnUnresolvableMappingFailsRatherThanPassingThrough()

    /**
     * A mapping that throws mid-list fails the step and names the item.
     *
     * @return void
     */
    public function testAFailingMappingNamesTheItemItFailedOn(): void
    {
        $this->mapper->method('find')->willReturn($this->createMock(Mapping::class));
        $this->mappings->method('executeMapping')->willThrowException(
            new \RuntimeException('bad template')
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/item 0/');

        $this->node->execute(
            [FlowItems::item(json: ['a' => 1])],
            ['mapping' => '42'],
            []
        );

    }//end testAFailingMappingNamesTheItemItFailedOn()

    /**
     * A reference-column name still resolves once find() has missed on it.
     *
     * `resolve()` asks find() first for any non-numeric string, because find()
     * is the only lookup that matches the uuid and slug columns. A name that
     * lives in the `reference` column alone therefore has to survive find()
     * MISSING before findByRef() gets its turn, and it is that fall-through the
     * exception below exercises — an exported flow that names its mapping by
     * reference breaks the moment find()'s throw stops being caught.
     *
     * @return void
     */
    public function testANonNumericReferenceResolvesByRef(): void
    {
        $mapping = $this->createMock(Mapping::class);
        $this->mapper->expects($this->once())
            ->method('find')
            ->with('person-to-contact')
            ->willThrowException(new DoesNotExistException('no such mapping'));
        $this->mapper->expects($this->once())
            ->method('findByRef')
            ->with('person-to-contact')
            ->willReturn([$mapping]);

        $this->mappings->method('executeMapping')->willReturn(['ok' => true]);

        $out = $this->node->execute(
            [FlowItems::item(json: [])],
            ['mapping' => 'person-to-contact'],
            []
        );

        $this->assertCount(1, $out);

    }//end testANonNumericReferenceResolvesByRef()

    /**
     * A uuid or a slug resolves through find(), and findByRef() is never reached.
     *
     * find() is the only lookup that consults the uuid and slug columns, so
     * asking findByRef() first left a flow naming its mapping by uuid or slug
     * dying on "No mapping matches ..." while the row sat there matching two of
     * the four identifiers. The `never()` on findByRef() is what pins the ORDER:
     * without it a resolve() that consulted the `reference` column first would
     * satisfy this test just as well.
     *
     * @return void
     */
    public function testAUuidReferenceResolvesThroughFind(): void
    {
        $mapping = $this->createMock(Mapping::class);
        $this->mapper->expects($this->once())
            ->method('find')
            ->with('018f2a1e-0c3d-7c2b-9f11-6f0c9a1b2c3d')
            ->willReturn($mapping);
        $this->mapper->expects($this->never())->method('findByRef');

        $this->mappings->method('executeMapping')->willReturn(['ok' => true]);

        $out = $this->node->execute(
            [FlowItems::item(json: [])],
            ['mapping' => '018f2a1e-0c3d-7c2b-9f11-6f0c9a1b2c3d'],
            []
        );

        $this->assertCount(1, $out);

    }//end testAUuidReferenceResolvesThroughFind()

    /**
     * A map step with no mapping is refused at validation.
     *
     * @return void
     */
    public function testAMapStepWithoutAMappingIsRefused(): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->node->validateConfig([]);

    }//end testAMapStepWithoutAMappingIsRefused()

    /**
     * A blank mapping is refused too — whitespace is not a mapping.
     *
     * @return void
     */
    public function testABlankMappingIsRefused(): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->node->validateConfig(['mapping' => '   ']);

    }//end testABlankMappingIsRefused()

    /**
     * The node reports the catalogue id the flow definitions reference.
     *
     * @return void
     */
    public function testItsCatalogueIdIsStable(): void
    {
        $this->assertSame('openregister.map', $this->node->getId());

    }//end testItsCatalogueIdIsStable()

}//end class
