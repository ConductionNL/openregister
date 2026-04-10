<?php

/**
 * ObjectEntity TMLO Field Unit Tests
 *
 * Tests for the tmlo field on ObjectEntity including:
 * - Hydration from arrays
 * - Serialization to JSON
 * - Getter default behavior
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\ObjectEntity;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ObjectEntity TMLO field
 *
 * @covers \OCA\OpenRegister\Db\ObjectEntity
 */
class ObjectEntityTmloTest extends TestCase
{


    /**
     * Test tmlo getter returns empty array by default.
     *
     * @return void
     */
    public function testTmloGetterDefaultsToEmptyArray(): void
    {
        $entity = new ObjectEntity();
        $tmlo   = $entity->getTmlo();

        $this->assertIsArray($tmlo);
        $this->assertEmpty($tmlo);
    }//end testTmloGetterDefaultsToEmptyArray()


    /**
     * Test setTmlo and getTmlo round-trip.
     *
     * @return void
     */
    public function testSetAndGetTmlo(): void
    {
        $entity  = new ObjectEntity();
        $tmloData = [
            'classificatie'    => '1.1',
            'archiefnominatie' => 'blijvend_bewaren',
            'archiefstatus'    => 'actief',
            'bewaarTermijn'    => 'P7Y',
        ];

        $entity->setTmlo($tmloData);
        $result = $entity->getTmlo();

        $this->assertEquals('1.1', $result['classificatie']);
        $this->assertEquals('blijvend_bewaren', $result['archiefnominatie']);
        $this->assertEquals('actief', $result['archiefstatus']);
        $this->assertEquals('P7Y', $result['bewaarTermijn']);
    }//end testSetAndGetTmlo()


    /**
     * Test tmlo field appears in getObjectArray output.
     *
     * @return void
     */
    public function testTmloInGetObjectArray(): void
    {
        $entity = new ObjectEntity();
        $entity->setUuid('test-uuid');
        $entity->setTmlo([
            'archiefstatus' => 'actief',
        ]);

        $objectArray = $entity->getObjectArray();

        $this->assertArrayHasKey('tmlo', $objectArray);
        $this->assertEquals('actief', $objectArray['tmlo']['archiefstatus']);
    }//end testTmloInGetObjectArray()


    /**
     * Test tmlo field appears in jsonSerialize output under @self.
     *
     * @return void
     */
    public function testTmloInJsonSerialize(): void
    {
        $entity = new ObjectEntity();
        $entity->setUuid('test-uuid-json');
        $entity->setTmlo([
            'classificatie' => '2.1',
            'archiefstatus' => 'semi_statisch',
        ]);

        $json = $entity->jsonSerialize();

        $this->assertArrayHasKey('@self', $json);
        $this->assertArrayHasKey('tmlo', $json['@self']);
        $this->assertEquals('2.1', $json['@self']['tmlo']['classificatie']);
    }//end testTmloInJsonSerialize()


    /**
     * Test hydrate sets tmlo from array.
     *
     * @return void
     */
    public function testHydrateSetsTmlo(): void
    {
        $entity = new ObjectEntity();
        $entity->hydrate([
            'tmlo' => [
                'archiefstatus'    => 'actief',
                'classificatie'    => '3.1',
                'bewaarTermijn'    => 'P10Y',
                'archiefnominatie' => 'vernietigen',
            ],
        ]);

        $tmlo = $entity->getTmlo();

        $this->assertEquals('actief', $tmlo['archiefstatus']);
        $this->assertEquals('3.1', $tmlo['classificatie']);
        $this->assertEquals('P10Y', $tmlo['bewaarTermijn']);
    }//end testHydrateSetsTmlo()


    /**
     * Test setTmlo with null resets to null.
     *
     * @return void
     */
    public function testSetTmloNull(): void
    {
        $entity = new ObjectEntity();
        $entity->setTmlo(['archiefstatus' => 'actief']);
        $entity->setTmlo(null);

        // getTmlo returns empty array for null (via getter override).
        $tmlo = $entity->getTmlo();
        $this->assertIsArray($tmlo);
        $this->assertEmpty($tmlo);
    }//end testSetTmloNull()


    /**
     * Test tmlo field with all six TMLO fields.
     *
     * @return void
     */
    public function testFullTmloFieldSet(): void
    {
        $entity  = new ObjectEntity();
        $fullTmlo = [
            'classificatie'          => '1.1.2',
            'archiefnominatie'       => 'vernietigen',
            'archiefactiedatum'      => '2032-06-15',
            'archiefstatus'          => 'semi_statisch',
            'bewaarTermijn'          => 'P7Y',
            'vernietigingsCategorie' => 'cat-b2',
        ];

        $entity->setTmlo($fullTmlo);
        $result = $entity->getTmlo();

        $this->assertEquals($fullTmlo, $result);
    }//end testFullTmloFieldSet()


}//end class
