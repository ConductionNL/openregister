<?php

/**
 * StoredEntity hands back the stored row and refuses anything else.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use InvalidArgumentException;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\StoredEntity;
use OCA\OpenRegister\Db\Webhook;
use OCA\OpenRegister\Kernel\Contracts\ObjectRowInterface;
use OCA\OpenRegister\Kernel\Contracts\RegisterInterface;
use OCA\OpenRegister\Kernel\Contracts\SchemaInterface;
use OCA\OpenRegister\Kernel\Contracts\WebhookInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(StoredEntity::class)]
class StoredEntityTest extends TestCase {

	/**
	 * A stored Schema row comes back as the same object.
	 *
	 * @return void
	 */
	public function testAStoredSchemaIsReturnedAsIs(): void {
		$schema = new Schema();

		$this->assertSame(expected: $schema, actual: StoredEntity::schema(schema: $schema));
	}//end testAStoredSchemaIsReturnedAsIs()

	/**
	 * A stored Register row comes back as the same object.
	 *
	 * @return void
	 */
	public function testAStoredRegisterIsReturnedAsIs(): void {
		$register = new Register();

		$this->assertSame(expected: $register, actual: StoredEntity::register(register: $register));
	}//end testAStoredRegisterIsReturnedAsIs()

	/**
	 * Null stays null, so optional register and schema arguments keep working.
	 *
	 * @return void
	 */
	public function testNullStaysNull(): void {
		$this->assertNull(StoredEntity::schema(schema: null));
		$this->assertNull(StoredEntity::register(register: null));
		$this->assertNull(StoredEntity::object(object: null));
		$this->assertNull(StoredEntity::webhook(webhook: null));
	}//end testNullStaysNull()

	/**
	 * Another SchemaInterface implementation is refused.
	 *
	 * @return void
	 */
	public function testAForeignSchemaIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);

		StoredEntity::schema(schema: $this->createMock(SchemaInterface::class));
	}//end testAForeignSchemaIsRefused()

	/**
	 * Another RegisterInterface implementation is refused.
	 *
	 * @return void
	 */
	public function testAForeignRegisterIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);

		StoredEntity::register(register: $this->createMock(RegisterInterface::class));
	}//end testAForeignRegisterIsRefused()

	/**
	 * Stored ObjectEntity and Webhook rows come back as the same objects.
	 *
	 * @return void
	 */
	public function testAStoredObjectAndWebhookAreReturnedAsIs(): void {
		$object  = new ObjectEntity();
		$webhook = new Webhook();

		$this->assertSame(expected: $object, actual: StoredEntity::object(object: $object));
		$this->assertSame(expected: $webhook, actual: StoredEntity::webhook(webhook: $webhook));
	}//end testAStoredObjectAndWebhookAreReturnedAsIs()

	/**
	 * Another ObjectRowInterface implementation is refused.
	 *
	 * @return void
	 */
	public function testAForeignObjectIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);

		StoredEntity::object(object: $this->createMock(ObjectRowInterface::class));
	}//end testAForeignObjectIsRefused()

	/**
	 * Another WebhookInterface implementation is refused.
	 *
	 * @return void
	 */
	public function testAForeignWebhookIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);

		StoredEntity::webhook(webhook: $this->createMock(WebhookInterface::class));
	}//end testAForeignWebhookIsRefused()
}//end class
