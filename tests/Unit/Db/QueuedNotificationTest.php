<?php

/**
 * Unit tests for the QueuedNotification entity.
 *
 * Verifies that every typed field round-trips through the accessors, that
 * `jsonSerialize()` returns the documented shape (including the decoded
 * `payload`), and that the `REASON_*` constants match the string values
 * the dispatcher/flush-job persist and compare against.
 *
 * @category Tests\Unit\Db
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/notification-delivery-windows/design.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use DateTime;
use OCA\OpenRegister\Db\QueuedNotification;
use PHPUnit\Framework\TestCase;

/**
 * Tests for QueuedNotification entity.
 */
class QueuedNotificationTest extends TestCase
{

    public function testAllFieldsRoundTrip(): void
    {
        $entity = new QueuedNotification();
        $now    = new DateTime('2026-07-12T20:15:00+02:00');

        $entity->setSchemaId(7);
        $entity->setRuleKey('grade_published');
        $entity->setRecipient('ouder-1');
        $entity->setReason(QueuedNotification::REASON_QUIET_HOURS);
        $entity->setObjectUuid('11111111-2222-3333-4444-555555555555');
        $entity->setPayload('{"subject":"Grade published"}');
        $entity->setDueAtHint($now);
        $entity->setCreatedAt($now);

        $this->assertSame(7, $entity->getSchemaId());
        $this->assertSame('grade_published', $entity->getRuleKey());
        $this->assertSame('ouder-1', $entity->getRecipient());
        $this->assertSame('quiet-hours', $entity->getReason());
        $this->assertSame('11111111-2222-3333-4444-555555555555', $entity->getObjectUuid());
        $this->assertSame('{"subject":"Grade published"}', $entity->getPayload());
        $this->assertSame($now, $entity->getDueAtHint());
        $this->assertSame($now, $entity->getCreatedAt());
    }//end testAllFieldsRoundTrip()

    public function testReasonConstantsMatchDesignedValues(): void
    {
        $this->assertSame('quiet-hours', QueuedNotification::REASON_QUIET_HOURS);
        $this->assertSame('digest-schedule', QueuedNotification::REASON_DIGEST_SCHEDULE);
        $this->assertSame('quiet-hours+digest-schedule', QueuedNotification::REASON_BOTH);
    }//end testReasonConstantsMatchDesignedValues()

    public function testGetDecodedPayloadDecodesJson(): void
    {
        $entity = new QueuedNotification();
        $entity->setPayload('{"subject":"Grade published","channels":["nc-notification","email"]}');

        $decoded = $entity->getDecodedPayload();

        $this->assertSame('Grade published', $decoded['subject']);
        $this->assertSame(['nc-notification', 'email'], $decoded['channels']);
    }//end testGetDecodedPayloadDecodesJson()

    public function testGetDecodedPayloadReturnsEmptyArrayForMalformedJson(): void
    {
        $entity = new QueuedNotification();
        $entity->setPayload('not-json');

        $this->assertSame([], $entity->getDecodedPayload());
    }//end testGetDecodedPayloadReturnsEmptyArrayForMalformedJson()

    public function testJsonSerializeReturnsDocumentedShapeWithDecodedPayload(): void
    {
        $entity = new QueuedNotification();
        $now    = new DateTime('2026-07-12T20:15:00+02:00');

        $entity->setSchemaId(7);
        $entity->setRuleKey('grade_published');
        $entity->setRecipient('ouder-1');
        $entity->setReason(QueuedNotification::REASON_DIGEST_SCHEDULE);
        $entity->setObjectUuid('11111111-2222-3333-4444-555555555555');
        $entity->setPayload('{"subject":"Grade published"}');
        $entity->setDueAtHint($now);
        $entity->setCreatedAt($now);

        $serialized = $entity->jsonSerialize();

        $this->assertSame(7, $serialized['schemaId']);
        $this->assertSame('grade_published', $serialized['ruleKey']);
        $this->assertSame('ouder-1', $serialized['recipient']);
        $this->assertSame('digest-schedule', $serialized['reason']);
        $this->assertSame('11111111-2222-3333-4444-555555555555', $serialized['objectUuid']);
        $this->assertSame(['subject' => 'Grade published'], $serialized['payload']);
        $this->assertSame('2026-07-12T20:15:00+02:00', $serialized['dueAtHint']);
        $this->assertSame('2026-07-12T20:15:00+02:00', $serialized['createdAt']);
    }//end testJsonSerializeReturnsDocumentedShapeWithDecodedPayload()

    public function testNullableFieldsBeforeAssignment(): void
    {
        $entity     = new QueuedNotification();
        $serialized = $entity->jsonSerialize();

        $this->assertArrayHasKey('schemaId', $serialized);
        $this->assertNull($serialized['ruleKey']);
        $this->assertNull($serialized['dueAtHint']);
        $this->assertNull($serialized['createdAt']);
        $this->assertSame([], $serialized['payload']);
    }//end testNullableFieldsBeforeAssignment()
}//end class
