<?php

/**
 * Unit tests for the NotificationDedupeState entity.
 *
 * Verifies that every typed field round-trips through the accessors and that
 * `jsonSerialize()` returns the documented shape.
 *
 * @category Tests\Unit\Db
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use DateTime;
use OCA\OpenRegister\Db\NotificationDedupeState;
use PHPUnit\Framework\TestCase;

/**
 * Tests for NotificationDedupeState entity.
 */
class NotificationDedupeStateTest extends TestCase {

	public function testAllFieldsRoundTrip(): void {
		$entity = new NotificationDedupeState();
		$now = new DateTime('2026-06-12T08:00:00+00:00');

		$entity->setSchemaId(42);
		$entity->setRuleKey('taskDueSoon');
		$entity->setObjectUuid('11111111-2222-3333-4444-555555555555');
		$entity->setFingerprint('abcdef0123456789abcdef0123456789abcdef01');
		$entity->setDispatchedAt($now);
		$entity->setSeenAt($now);

		$this->assertSame(42, $entity->getSchemaId());
		$this->assertSame('taskDueSoon', $entity->getRuleKey());
		$this->assertSame('11111111-2222-3333-4444-555555555555', $entity->getObjectUuid());
		$this->assertSame('abcdef0123456789abcdef0123456789abcdef01', $entity->getFingerprint());
		$this->assertSame($now, $entity->getDispatchedAt());
		$this->assertSame($now, $entity->getSeenAt());
	}//end testAllFieldsRoundTrip()

	public function testJsonSerializeReturnsDocumentedShape(): void {
		$entity = new NotificationDedupeState();
		$now = new DateTime('2026-06-12T08:00:00+00:00');

		$entity->setSchemaId(42);
		$entity->setRuleKey('taskDueSoon');
		$entity->setObjectUuid('11111111-2222-3333-4444-555555555555');
		$entity->setFingerprint('abcdef0123456789abcdef0123456789abcdef01');
		$entity->setDispatchedAt($now);
		$entity->setSeenAt($now);

		$serialized = $entity->jsonSerialize();

		$this->assertSame(42, $serialized['schemaId']);
		$this->assertSame('taskDueSoon', $serialized['ruleKey']);
		$this->assertSame('11111111-2222-3333-4444-555555555555', $serialized['objectUuid']);
		$this->assertSame('abcdef0123456789abcdef0123456789abcdef01', $serialized['fingerprint']);
		$this->assertSame('2026-06-12T08:00:00+00:00', $serialized['dispatchedAt']);
		$this->assertSame('2026-06-12T08:00:00+00:00', $serialized['seenAt']);
	}//end testJsonSerializeReturnsDocumentedShape()

	public function testNullableFieldsBeforeAssignment(): void {
		$entity = new NotificationDedupeState();
		$serialized = $entity->jsonSerialize();

		$this->assertArrayHasKey('schemaId', $serialized);
		$this->assertNull($serialized['fingerprint']);
		$this->assertNull($serialized['dispatchedAt']);
		$this->assertNull($serialized['seenAt']);
	}//end testNullableFieldsBeforeAssignment()
}//end class
