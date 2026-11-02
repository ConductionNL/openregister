<?php

declare(strict_types=1);

/**
 * ValidateObject planned users Unit Tests
 *
 * The `user` format asks Nextcloud whether an account exists. An example
 * set's check validates objects that name accounts the set writes before
 * those objects, so it passes those ids along; this proves the real
 * validator accepts exactly those ids, for that one call only.
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
 * @link https://www.OpenRegister.nl
 */

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Object\ValidateObject;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenRegister\Service\Object\ValidateObject
 * @covers \OCA\OpenRegister\Formats\UserFormat
 */
class ValidateObjectPlannedUsersTest extends TestCase {
	private ValidateObject $handler;

	protected function setUp(): void {
		parent::setUp();

		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('getBaseUrl')->willReturn('http://localhost:8080');
		$users = $this->createMock(IUserManager::class);
		$users->method('userExists')->willReturnCallback(static fn (string $uid): bool => $uid === 'admin');

		$this->handler = new ValidateObject(
			$this->createMock(IAppConfig::class),
			$this->createMock(MagicMapper::class),
			$this->createMock(SchemaMapper::class),
			$urlGenerator,
			$this->createMock(LoggerInterface::class),
			$users
		);
	}

	/**
	 * A ticket schema with an `assignee` of format `user`.
	 *
	 * @return array{0: Schema, 1: object}
	 */
	private function ticket(): array {
		$schema = new Schema();
		$schema->setSlug('ticket');
		$schema->setTitle('Ticket');

		return [$schema, json_decode('{"type":"object","properties":{"assignee":{"type":"string","format":"user"}}}')];
	}

	/**
	 * @spec openspec/specs/example-sets/spec.md#requirement-check-validates-every-object-against-its-real-schema-without-writing-req-exs-004
	 */
	public function testAPlannedAccountPassesOnlyForThatCall(): void {
		[$schema, $object] = $this->ticket();

		$planned = $this->handler->validateObjectWithPlannedUsers(['assignee' => 'daan.verbeek'], $schema, $object, ['daan.verbeek']);
		$this->assertTrue($planned->isValid(), 'a declared account passes');

		$after = $this->handler->validateObject(['assignee' => 'daan.verbeek'], $schema, $object);
		$this->assertFalse($after->isValid(), 'the declaration ends with the call');
	}

	/**
	 * @spec openspec/specs/example-sets/spec.md#requirement-check-validates-every-object-against-its-real-schema-without-writing-req-exs-004
	 */
	public function testAnUndeclaredAccountIsStillRefusedAndAnExistingOneStillPasses(): void {
		[$schema, $object] = $this->ticket();

		$undeclared = $this->handler->validateObjectWithPlannedUsers(['assignee' => 'nobody.here'], $schema, $object, ['daan.verbeek']);
		$this->assertFalse($undeclared->isValid());
		$this->assertStringContainsString("format 'user'", $this->handler->generateErrorMessage(result: $undeclared));

		$existing = $this->handler->validateObjectWithPlannedUsers(['assignee' => 'admin'], $schema, $object, ['daan.verbeek']);
		$this->assertTrue($existing->isValid());
	}
}
