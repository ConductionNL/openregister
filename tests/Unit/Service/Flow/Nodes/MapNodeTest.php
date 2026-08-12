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
     * A slug resolves, so an exported flow survives an id that differs per instance.
     *
     * @return void
     */
    public function testANonNumericReferenceFallsBackToRefWhenItIsNotAUuidOrSlug(): void
    {
        $mapping = $this->createMock(Mapping::class);

        // find() IS consulted first now, and is expected to miss here: it is what
        // resolves a uuid or a slug, and this reference is neither. Asserting it
        // was never called pinned the old behaviour, where resolve() went straight
        // to findByRef() and a mapping named by uuid or slug could not be found at
        // all — the `reference` column was the only one ever consulted.
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

    }//end testANonNumericReferenceFallsBackToRefWhenItIsNotAUuidOrSlug()


    /**
     * A mapping named by UUID resolves, and never reaches the reference lookup.
     *
     * The case this exists for: a flow definition is portable between instances
     * where the numeric id differs, so authors name mappings by uuid. resolve()
     * used to send every non-numeric reference straight to findByRef(), which
     * consults the `reference` column alone — so a uuid matched nothing and the
     * step failed with "No mapping matches ... by id, uuid, slug or reference"
     * while the row sat there matching two of the four named identifiers.
     *
     * @return void
     *
     * @spec openspec/changes/flow-parity-mapping-and-webhooks/specs/flow-mapping/spec.md
     */
    public function testAUuidResolvesThroughFindAndNeverReachesTheRefLookup(): void
    {
        $mapping = $this->createMock(Mapping::class);
        $uuid    = '7427bd97-103a-4965-a47a-5e3357afc479';

        $this->mapper->expects($this->once())
            ->method('find')
            ->with($uuid)
            ->willReturn($mapping);
        $this->mapper->expects($this->never())->method('findByRef');

        $this->mappings->method('executeMapping')->willReturn(['ok' => true]);

        $out = $this->node->execute(
            [FlowItems::item(json: [])],
            ['mapping' => $uuid],
            []
        );

        $this->assertCount(1, $out);

    }//end testAUuidResolvesThroughFindAndNeverReachesTheRefLookup()

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
