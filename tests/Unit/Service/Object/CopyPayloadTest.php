<?php

/**
 * The create body of a copy carries the source's fields, never its identity.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/records-copy-with-links/specs/objects-crud/spec.md#requirement-an-object-can-be-copied-with-the-links-the-caller-chooses
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Object\CopyPayload;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Object\CopyPayload
 * @uses   \OCA\OpenRegister\Db\Schema
 */
final class CopyPayloadTest extends TestCase {

	/**
	 * A schema `module` whose `zaaknummer` is minted by the platform.
	 *
	 * @return Schema
	 */
	private function schema(): Schema {
		$schema = new Schema();
		$schema->setProperties(
			[
				'naam' => ['type' => 'string'],
				'zaaknummer' => ['type' => 'string', 'x-openregister-generated' => 'sequence'],
				'tags' => ['type' => 'array'],
			]
		);

		return $schema;
	}//end schema()

	/**
	 * The source's fields carry over and the overrides win.
	 *
	 * @return void
	 */
	public function testTheFieldsCarryOverAndTheOverridesWin(): void {
		$body = (new CopyPayload())->of(
			source: ['naam' => 'Zaaksysteem A', 'tags' => ['a', 'b'], 'leverancier' => 'Conduction'],
			overrides: ['naam' => 'Zaaksysteem B'],
			schema: $this->schema()
		);

		$this->assertSame(expected: ['naam' => 'Zaaksysteem B', 'tags' => ['a', 'b'], 'leverancier' => 'Conduction'], actual: $body);
	}//end testTheFieldsCarryOverAndTheOverridesWin()

	/**
	 * The identity, the metadata, control keys and minted properties never carry over.
	 *
	 * @return void
	 */
	public function testTheIdentityIsNeverCopied(): void {
		$body = (new CopyPayload())->of(
			source: [
				'id' => 'u-1', 'uuid' => 'u-1', '@self' => ['owner' => 'anna'], '_rbac' => false,
				'zaaknummer' => 'Z-2026-1', 'naam' => 'Zaaksysteem A',
			],
			overrides: [],
			schema: $this->schema()
		);

		$this->assertSame(expected: ['naam' => 'Zaaksysteem A'], actual: $body);
	}//end testTheIdentityIsNeverCopied()

	/**
	 * An override cannot claim an identity or a minted value either.
	 *
	 * @return void
	 */
	public function testAnOverrideCannotSetTheIdentity(): void {
		$body = (new CopyPayload())->of(
			source: ['naam' => 'Zaaksysteem A'],
			overrides: ['uuid' => 'u-1', 'id' => 'u-1', '@self' => ['owner' => 'bob'], 'zaaknummer' => 'Z-1', '_upsertOn' => 'naam'],
			schema: $this->schema()
		);

		$this->assertSame(expected: ['naam' => 'Zaaksysteem A'], actual: $body);
	}//end testAnOverrideCannotSetTheIdentity()
}//end class
