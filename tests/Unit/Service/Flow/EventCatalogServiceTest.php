<?php

/**
 * The event catalog answers for every trigger the listener actually fires.
 *
 * The catalog is what the builder's palette shows, and `FlowTriggerListener`
 * is what fires. A trigger present in one and not the other is either a
 * palette entry that can never start anything or an event no author can
 * subscribe to, so the seven object-lifecycle ids are asserted against the
 * listener's vocabulary by name. The legacy aliases are asserted too: an
 * alias that stops resolving orphans every flow authored before the catalog.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Flow
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-trigger-canonical-slugs/specs/flow-engine/spec.md
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace Unit\Service\Flow;

use OCA\OpenRegister\Service\Flow\EventCatalogService;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Flow\EventCatalogService
 */
class EventCatalogServiceTest extends TestCase {

	public function testTheCatalogCarriesEveryObjectEventTheListenerFires(): void {
		$ids = array_column((new EventCatalogService())->getCatalog(), 'id');

		foreach ([
			'object.created',
			'object.updated',
			'object.deleted',
			'object.locked',
			'object.unlocked',
			'object.reverted',
			'object.transitioned',
		] as $fired) {
			$this->assertContains($fired, $ids, 'the palette must offer every trigger the listener fires');
		}
	}

	public function testKnownTriggerIdsIncludeTheLegacyAliases(): void {
		$known = (new EventCatalogService())->knownTriggerIds();

		foreach (['object.created', 'created', 'object.updated', 'updated', 'object.deleted', 'deleted'] as $id) {
			$this->assertContains($id, $known, 'a legacy alias that stops being known orphans pre-catalog flows');
		}
	}

	public function testAliasesForBridgesCanonicalAndLegacyBothWays(): void {
		$catalog = new EventCatalogService();

		$this->assertSame(['object.created', 'created'], $catalog->aliasesFor(dispatched: 'object.created'));
		$this->assertSame(['object.created', 'created'], $catalog->aliasesFor(dispatched: 'created'));
	}

	public function testAnUnknownTriggerMatchesOnlyItself(): void {
		$this->assertSame(
			['myapp.custom'],
			(new EventCatalogService())->aliasesFor(dispatched: 'myapp.custom'),
			'an unknown id must round-trip rather than vanish or widen'
		);
	}

	public function testAnEventWithoutALegacyAliasAnswersJustItsCanonicalId(): void {
		$this->assertSame(
			['object.locked'],
			(new EventCatalogService())->aliasesFor(dispatched: 'object.locked'),
			'no phantom alias may be invented for a post-catalog event'
		);
	}
}
