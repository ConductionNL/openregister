<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\Import;

use OCA\OpenRegister\Service\Import\BundleObjectRouting;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Which schema each object of a register bundle is saved into, and which schemas the register links.
 *
 * @spec openspec/changes/capability-rows-closing-round/specs/data-import-export/spec.md#requirement-a-register-groups-record-types-and-moves-as-one-unit
 */
#[CoversClass(BundleObjectRouting::class)]
class BundleObjectRoutingTest extends TestCase {

	private static function record(string $name, mixed $schema): array {
		return ['naam' => $name, '@self' => ['register' => 'meldingen', 'schema' => $schema]];
	}

	public function testEachObjectGoesToTheSchemaItNamesInFileOrder(): void {
		$objects = [
			self::record('m-1', 'melding'),
			self::record('p-1', 'melder'),
			self::record('m-2', 'melding'),
		];

		$grouped = BundleObjectRouting::group(objects: $objects, slugs: ['melding', 'melder']);

		$this->assertSame(['melding', 'melder'], array_keys($grouped['bySchema']));
		$this->assertSame(['m-1', 'm-2'], array_column($grouped['bySchema']['melding'], 'naam'));
		$this->assertSame(['p-1'], array_column($grouped['bySchema']['melder'], 'naam'));
		$this->assertSame([], $grouped['unassigned']);
	}

	public function testAnObjectNamingNoSchemaOfTheBundleIsNotGuessedIntoOne(): void {
		$objects = [
			self::record('m-1', 'melding'),
			self::record('x-1', 'elsewhere'),
			['naam' => 'no self'],
			'not an object',
			self::record('n-1', 12),
		];

		$grouped = BundleObjectRouting::group(objects: $objects, slugs: ['melding', 'melder']);

		$this->assertSame(['melding'], array_keys($grouped['bySchema']));
		$this->assertSame(
			[
				['index' => 1, 'schema' => 'elsewhere'],
				['index' => 2, 'schema' => null],
				['index' => 3, 'schema' => null],
				['index' => 4, 'schema' => 12],
			],
			$grouped['unassigned']
		);
	}

	public function testTheUnassignedSummaryReportsEveryObjectAsAnErrorNamingTheBundlesSchemas(): void {
		$summary = BundleObjectRouting::unassignedSummary(
			unassigned: [['index' => 1, 'schema' => 'elsewhere'], ['index' => 2, 'schema' => null]],
			slugs: ['melding', 'melder']
		);

		$this->assertSame(2, $summary['found']);
		$this->assertSame([], $summary['created']);
		$this->assertSame([], $summary['updated']);
		$this->assertSame(
			[
				[
					'object' => 2,
					'error' => 'The object names schema "elsewhere", which is not in this register bundle (melding, melder).',
					'type' => 'UnassignedSchema',
				],
				[
					'object' => 3,
					'error' => 'The object names schema "none", which is not in this register bundle (melding, melder).',
					'type' => 'UnassignedSchema',
				],
			],
			$summary['errors']
		);
	}

	public function testTheRegisterLinksTheIdsHereOfTheSlugsItLists(): void {
		$this->assertSame(
			[58, 59],
			BundleObjectRouting::registerSchemaIds(listed: ['melder', 'melding', 'melder'], idsBySlug: ['melding' => 59, 'melder' => 58])
		);
	}

	public function testAnEntryThatIsNoSchemaOfTheBundleIsNotLinked(): void {
		// 54 is an id on the exporting instance; here it may be any schema.
		$this->assertSame(
			[59],
			BundleObjectRouting::registerSchemaIds(listed: [54, 'melding', 'unknown'], idsBySlug: ['melding' => 59, 'melder' => 58])
		);
	}

	public function testARegisterThatListsNoSchemasGetsEveryBundleSchema(): void {
		$this->assertSame([59, 58], BundleObjectRouting::registerSchemaIds(listed: null, idsBySlug: ['melding' => 59, 'melder' => 58]));
		$this->assertSame([59, 58], BundleObjectRouting::registerSchemaIds(listed: [], idsBySlug: ['melding' => 59, 'melder' => 58]));
	}
}
