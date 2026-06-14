<?php

/**
 * Unit tests for {@see \OCA\OpenRegister\Db\CollectiveLink}.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/integration-collectives/tasks.md
 */

declare(strict_types=1);

namespace Unit\Db;

use DateTime;
use OCA\OpenRegister\Db\CollectiveLink;
use PHPUnit\Framework\TestCase;

/**
 * CollectiveLinkTest.
 */
class CollectiveLinkTest extends TestCase
{

    public function testJsonSerializeReturnsAllFields(): void
    {
        $link = new CollectiveLink();
        $link->setObjectUuid('abc-123');
        $link->setRegisterId(5);
        $link->setSchemaId(7);
        $link->setPageId(42);
        $link->setCollectiveId(10);
        $link->setCollectiveName('Engineering');
        $link->setPageTitle('Runbook');
        $link->setSlug('runbook');
        $link->setEmoji('📖');
        $link->setLastModified(new DateTime('2026-06-01T12:00:00+00:00'));
        $link->setUrl('/index.php/apps/collectives/?fileId=42');
        $link->setLinkedBy('alice');
        $link->setLinkedAt(new DateTime('2026-06-01T11:00:00+00:00'));

        $json = $link->jsonSerialize();

        $this->assertSame('abc-123', $json['objectUuid']);
        $this->assertSame(5, $json['registerId']);
        $this->assertSame(7, $json['schemaId']);
        $this->assertSame(42, $json['pageId']);
        $this->assertSame(10, $json['collectiveId']);
        $this->assertSame('Engineering', $json['collectiveName']);
        $this->assertSame('Runbook', $json['pageTitle']);
        $this->assertSame('runbook', $json['slug']);
        $this->assertSame('📖', $json['emoji']);
        $this->assertSame('/index.php/apps/collectives/?fileId=42', $json['url']);
        $this->assertSame('alice', $json['linkedBy']);
        $this->assertNotNull($json['lastModified']);
        $this->assertNotNull($json['linkedAt']);
    }//end testJsonSerializeReturnsAllFields()

    public function testJsonSerializeHandlesNulls(): void
    {
        $link = new CollectiveLink();

        $json = $link->jsonSerialize();

        $this->assertNull($json['objectUuid']);
        $this->assertNull($json['pageId']);
        $this->assertNull($json['collectiveId']);
        $this->assertNull($json['collectiveName']);
        $this->assertNull($json['pageTitle']);
        $this->assertNull($json['slug']);
        $this->assertNull($json['emoji']);
        $this->assertNull($json['lastModified']);
        $this->assertNull($json['url']);
        $this->assertNull($json['linkedBy']);
        $this->assertNull($json['linkedAt']);
    }//end testJsonSerializeHandlesNulls()

    public function testSettersAndGetters(): void
    {
        $link = new CollectiveLink();
        $link->setPageId(99);
        $link->setPageTitle('Handbook');
        $link->setCollectiveId(3);
        $link->setCollectiveName('Ops');

        $this->assertSame(99, $link->getPageId());
        $this->assertSame('Handbook', $link->getPageTitle());
        $this->assertSame(3, $link->getCollectiveId());
        $this->assertSame('Ops', $link->getCollectiveName());
    }//end testSettersAndGetters()
}//end class
