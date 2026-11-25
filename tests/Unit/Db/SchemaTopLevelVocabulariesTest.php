<?php

/**
 * A schema body declaring `linkRoles` and `partyKinds` at the top level keeps them.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/party-model/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Db\Schema;
use PHPUnit\Framework\TestCase;

/**
 * The vocabularies are read from the configuration only; a top-level spelling, as the
 * parties documentation shows it, used to be dropped in silence on save.
 *
 * @covers \OCA\OpenRegister\Db\Schema
 */
final class SchemaTopLevelVocabulariesTest extends TestCase {

	private const KINDS = [['key' => 'person', 'label' => 'Persoon', 'roles' => ['aanvrager', 'gemachtigde']]];

	private const ROLES = [['key' => 'aanvrager', 'label' => 'Aanvrager'], ['key' => 'gemachtigde', 'label' => 'Gemachtigde']];

	/**
	 * A schema hydrated from the given payload.
	 *
	 * @param array $payload The body.
	 *
	 * @return Schema
	 */
	private function schema(array $payload): Schema {
		$schema = new Schema();
		$schema->hydrate(object: array_merge(['title' => 'Case', 'properties' => ['onderwerp' => ['type' => 'string']]], $payload));

		return $schema;
	}//end schema()

	/**
	 * Top-level kinds and roles land in the configuration and are read back.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/party-model/spec.md
	 */
	public function testTopLevelKindsAndRolesAreKept(): void {
		$schema = $this->schema(['partyKinds' => self::KINDS, 'linkRoles' => self::ROLES, 'configuration' => ['allowFiles' => true]]);

		$this->assertSame(['person'], array_column($schema->getPartyKinds(), 'key'));
		$this->assertSame(['aanvrager', 'gemachtigde'], array_column($schema->getLinkRoles(), 'key'));
		$this->assertSame(true, ($schema->getConfiguration()['allowFiles'] ?? null), 'the fold must not lose the rest of the configuration');
	}//end testTopLevelKindsAndRolesAreKept()

	/**
	 * An explicit configuration entry wins over a top-level one.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/party-model/spec.md
	 */
	public function testAConfigurationEntryWins(): void {
		$schema = $this->schema(
			[
				'partyKinds' => self::KINDS,
				'configuration' => ['partyKinds' => [['key' => 'organisation', 'label' => 'Organisatie']]],
			]
		);

		$this->assertSame(['organisation'], array_column($schema->getPartyKinds(), 'key'));
	}//end testAConfigurationEntryWins()
}//end class
