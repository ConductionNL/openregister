<?php

/**
 * A schema's declared uniqueness constraints survive a save.
 *
 * `configuration.uniqueConstraints` is what UniqueConstraintEvaluator,
 * UniqueConstraintListener and the code-list lifecycle read. The schema
 * entity's configuration allow-list did not carry it, so every save through
 * hydrate()/setConfiguration() dropped it without a log line, and the
 * declared constraints never reached the listener.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Schemas\UniqueConstraintEvaluator;
use PHPUnit\Framework\TestCase;

/**
 * Declared uniqueness constraints are kept by the schema entity.
 */
class SchemaUniqueConstraintsConfigTest extends TestCase {

	/**
	 * The constraints the e2e code-list spec declares, in the list form.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private const LISTED = [
		['name' => 'een-bezwaar', 'properties' => ['besluit', 'indiener'], 'action' => 'refuse'],
		['name' => 'dubbel-adres', 'properties' => ['email'], 'action' => 'report'],
	];

	/**
	 * A schema hydrated from the given payload, the way a create or update does.
	 *
	 * @param array<string, mixed> $payload The write payload.
	 *
	 * @return Schema
	 */
	private function schema(array $payload): Schema {
		$schema = new Schema();
		$schema->hydrate(object: array_merge(['title' => 'Bezwaar', 'properties' => ['email' => ['type' => 'string']]], $payload));

		return $schema;
	}//end schema()

	/**
	 * The list form survives hydrate() and is served back.
	 *
	 * @return void
	 */
	public function testListedConstraintsAreKept(): void {
		$schema = $this->schema(['configuration' => ['uniqueConstraints' => self::LISTED, 'allowFiles' => true]]);

		$this->assertSame(self::LISTED, ($schema->getConfiguration()['uniqueConstraints'] ?? null));
		$this->assertSame(self::LISTED, ($schema->jsonSerialize()['configuration']['uniqueConstraints'] ?? null));
	}//end testListedConstraintsAreKept()

	/**
	 * The keyed form survives setConfiguration().
	 *
	 * @return void
	 */
	public function testKeyedConstraintsAreKept(): void {
		$keyed  = ['zaaksleutel' => ['properties' => ['gemeentecode', 'zaaknummer'], 'action' => 'refuse']];
		$schema = new Schema();
		$schema->setConfiguration(['uniqueConstraints' => $keyed]);

		$this->assertSame($keyed, ($schema->getConfiguration()['uniqueConstraints'] ?? null));
	}//end testKeyedConstraintsAreKept()

	/**
	 * The evaluator the listener uses reads the declared constraints off a saved schema.
	 *
	 * @return void
	 */
	public function testTheEvaluatorSeesTheSavedConstraints(): void {
		$schema = $this->schema(['configuration' => ['uniqueConstraints' => self::LISTED]]);

		$constraints = (new UniqueConstraintEvaluator())->constraints(configuration: $schema->getConfiguration());

		$this->assertSame(
			[
				['name' => 'een-bezwaar', 'properties' => ['besluit', 'indiener'], 'action' => 'refuse'],
				['name' => 'dubbel-adres', 'properties' => ['email'], 'action' => 'report'],
			],
			$constraints
		);
	}//end testTheEvaluatorSeesTheSavedConstraints()

	/**
	 * The legacy `unique` key keeps working beside it.
	 *
	 * @return void
	 */
	public function testTheLegacyUniqueKeyIsStillKept(): void {
		$schema = $this->schema(['configuration' => ['unique' => ['email'], 'uniqueConstraints' => self::LISTED]]);

		$this->assertSame(['email'], ($schema->getConfiguration()['unique'] ?? null));
		$this->assertSame(self::LISTED, ($schema->getConfiguration()['uniqueConstraints'] ?? null));
	}//end testTheLegacyUniqueKeyIsStillKept()
}//end class
