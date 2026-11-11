<?php

/**
 * A schema keeps its `exportable` flag and serves it back (or#4103).
 *
 * nextcloud-vue shows the native Export menu on an index page only for a
 * schema flagged `exportable: true`. Open Register kept the flag in neither
 * place an app could put it: `configuration.exportable` was not on the
 * configuration allowlist and was dropped without a log line, and a top-level
 * `exportable` hit a `setExportable()` the entity does not have, whose
 * exception hydrate() swallows. So `allowExport: true` on any app page was a
 * no-op.
 *
 * The flag is stored in one place, `configuration.exportable`. A top-level
 * `exportable` on a write folds into it (an explicit configuration value
 * wins, as for `x-schema-org`), and the serialised schema carries it in both
 * places so a reader of either sees it.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/data-import-export/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\Schema;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Db\Schema
 */
class SchemaExportableTest extends TestCase {

	/**
	 * A schema hydrated from the given payload.
	 *
	 * @param array $payload The write payload.
	 *
	 * @return Schema
	 */
	private function schema(array $payload): Schema {
		$schema = new Schema();
		$schema->hydrate(object: array_merge(['title' => 'Case', 'properties' => ['name' => ['type' => 'string']]], $payload));

		return $schema;

	}//end schema()

	/**
	 * `configuration.exportable` survives a save and is served back.
	 *
	 * @return void
	 */
	public function testConfigurationExportableIsKept(): void {
		$schema = $this->schema(['configuration' => ['exportable' => true, 'allowFiles' => true]]);

		$this->assertSame(true, ($schema->getConfiguration()['exportable'] ?? null));
		$this->assertSame(true, ($schema->jsonSerialize()['configuration']['exportable'] ?? null));

	}//end testConfigurationExportableIsKept()

	/**
	 * A top-level `exportable` is kept in the configuration, not dropped.
	 *
	 * @return void
	 */
	public function testATopLevelExportableIsKept(): void {
		$schema = $this->schema(['exportable' => true, 'configuration' => ['allowFiles' => true]]);

		$this->assertSame(true, ($schema->getConfiguration()['exportable'] ?? null));
		$this->assertSame(true, ($schema->getConfiguration()['allowFiles'] ?? null), 'the fold must not lose the rest of the configuration');

	}//end testATopLevelExportableIsKept()

	/**
	 * The serialised schema carries the flag at the top level too, which is
	 * where the index page reads it today.
	 *
	 * @return void
	 */
	public function testTheFlagIsServedAtTheTopLevel(): void {
		$flagged   = $this->schema(['configuration' => ['exportable' => true]]);
		$unflagged = $this->schema(['configuration' => ['allowFiles' => true]]);

		$this->assertSame(true, ($flagged->jsonSerialize()['exportable'] ?? null));
		$this->assertSame(false, ($unflagged->jsonSerialize()['exportable'] ?? null));

	}//end testTheFlagIsServedAtTheTopLevel()

	/**
	 * An explicit configuration value wins over the top-level convenience form.
	 *
	 * The serialised schema carries both, so a client that reads a schema and
	 * saves it back sends both; the stored value must not flip on that round trip.
	 *
	 * @return void
	 */
	public function testAnExplicitConfigurationValueWins(): void {
		$schema = $this->schema(['exportable' => false, 'configuration' => ['exportable' => true]]);

		$this->assertSame(true, ($schema->getConfiguration()['exportable'] ?? null));

	}//end testAnExplicitConfigurationValueWins()

	/**
	 * A round trip of an unflagged schema adds nothing to its configuration.
	 *
	 * @return void
	 */
	public function testAnUnflaggedRoundTripAddsNoKey(): void {
		$first  = $this->schema(['configuration' => ['allowFiles' => true]]);
		$second = $this->schema($first->jsonSerialize());

		$this->assertArrayNotHasKey('exportable', ($second->getConfiguration() ?? []));

	}//end testAnUnflaggedRoundTripAddsNoKey()

	/**
	 * A non-boolean flag is refused (dropped), like every other boolean key.
	 *
	 * @return void
	 */
	public function testANonBooleanFlagIsDropped(): void {
		$schema = $this->schema(['configuration' => ['exportable' => 'yes', 'allowFiles' => true]]);

		$this->assertArrayNotHasKey('exportable', ($schema->getConfiguration() ?? []));
		$this->assertSame(true, ($schema->getConfiguration()['allowFiles'] ?? null));

	}//end testANonBooleanFlagIsDropped()

}//end class
