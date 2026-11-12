<?php

/**
 * Tests for ApplicationRegister: an object is held only when both its register and schema match, and a lookup failure holds nothing.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\AppHost\Scheduling
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\AppHost\Scheduling;

use OCA\OpenRegister\AppHost\Scheduling\ApplicationRegister;
use OCA\OpenRegister\Contract\RegisterSlugResolverInterface;
use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaCatalog;
use OCA\OpenRegister\Db\SchemaCatalogInterface;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\TestCase;

/**
 * ApplicationRegister over mocked mappers and slug resolver.
 */
class ApplicationRegisterTest extends TestCase {

	/**
	 * An object in register 7, schema 9.
	 *
	 * @return ObjectEntity
	 */
	private function object(): ObjectEntity {
		$object = new ObjectEntity();
		$object->setRegister('7');
		$object->setSchema('9');
		return $object;
	}

	/**
	 * The class under test, with the register and schema the mappers return.
	 *
	 * @param string $registerSlug The slug of register 7.
	 * @param string $schemaSlug   The slug of schema 9.
	 *
	 * @return ApplicationRegister
	 */
	private function subject(string $registerSlug, string $schemaSlug): ApplicationRegister {
		$register = new Register();
		$register->setSlug($registerSlug);
		$schema = new Schema();
		$schema->setSlug($schemaSlug);

		$registers = $this->createMock(RegisterMapperInterface::class);
		$registers->expects($this->once())->method('find')->with('7', false, false)->willReturn($register);
		$schemas = $this->createMock(SchemaMapperInterface::class);
		$schemas->expects($this->once())->method('find')->with('9', [], false, false)->willReturn($schema);
		$slugs = $this->createMock(RegisterSlugResolverInterface::class);
		$slugs->method('slugOrNull')->with('buildiq')->willReturn('buildiq-acme');

		return new ApplicationRegister(new SchemaCatalog($registers, $schemas, $this->createMock(MagicMapperInterface::class)), $slugs);
	}

	public function testAnObjectOfTheSchemaInTheApplicationsRegisterIsHeld(): void {
		$this->assertTrue($this->subject('buildiq-acme', 'application')->holds($this->object(), 'application'));
	}

	public function testASameNamedSchemaInAnotherRegisterIsNotHeld(): void {
		$this->assertFalse($this->subject('elsewhere', 'application')->holds($this->object(), 'application'));
	}

	public function testAnotherSchemaInTheApplicationsRegisterIsNotHeld(): void {
		$this->assertFalse($this->subject('buildiq-acme', 'release')->holds($this->object(), 'application'));
	}

	public function testALookupFailureHoldsNothing(): void {
		$registers = $this->createMock(RegisterMapperInterface::class);
		$registers->expects($this->once())->method('find')->willThrowException(new DoesNotExistException('gone'));
		$schemas = $this->createMock(SchemaMapperInterface::class);
		$schemas->expects($this->never())->method('find');
		$slugs = $this->createMock(RegisterSlugResolverInterface::class);
		$slugs->expects($this->never())->method('slugOrNull');

		$subject = new ApplicationRegister(new SchemaCatalog($registers, $schemas, $this->createMock(MagicMapperInterface::class)), $slugs);

		$this->assertFalse($subject->holds($this->object(), 'application'));
	}

	public function testSlugIsWhatTheResolverFindsForTheCanonicalName(): void {
		$slugs = $this->createMock(RegisterSlugResolverInterface::class);
		$slugs->expects($this->once())->method('slugOrNull')->with('buildiq')->willReturn(null);
		$subject = new ApplicationRegister(
			$this->createMock(SchemaCatalogInterface::class),
			$slugs
		);

		$this->assertNull($subject->slug());
	}
}
