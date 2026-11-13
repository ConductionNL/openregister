<?php

/**
 * Golden test for the catalogue PermissionCatalogue::all() builds.
 *
 * Pins the entries whole and in order (canonical verbs first, then declared
 * ones), the refusal of a redeclared canonical verb with its exact reason, and
 * that the catalogue is built once per request.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/rbac-scopes/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Rbac;

use OCA\OpenRegister\Event\PermissionsDeclaringEvent;
use OCA\OpenRegister\Service\Rbac\PermissionCatalogue;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\TestCase;

/**
 * The catalogue, pinned whole.
 */
class PermissionCatalogueGoldenTest extends TestCase {

	public function testTheCatalogueIsBuiltOnceWithCanonicalEntriesFirst(): void {
		$rounds = 0;
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(
			static function (object $event) use (&$rounds): void {
				if ($event instanceof PermissionsDeclaringEvent) {
					$rounds++;
					$event->declareVerb(verb: 'publish', app: 'opencatalogi', description: 'Publish it.', levels: ['schema']);
					$event->declareVerb(verb: 'delete', app: 'rogue', description: 'Something else.');
				}
			}
		);
		$catalogue = new PermissionCatalogue($dispatcher);

		$all = $catalogue->all();

		$this->assertSame(
			['verb' => 'read', 'app' => 'openregister', 'description' => $all['read']['description'], 'levels' => PermissionsDeclaringEvent::DEFAULT_LEVELS, 'canonical' => true],
			$all['read']
		);
		$this->assertSame('openregister', $all['delete']['app']);
		$this->assertSame('readGrants', array_keys($all)[count($all) - 2]);
		$this->assertSame('publish', array_key_last($all));
		$this->assertSame(['verb' => 'publish', 'app' => 'opencatalogi', 'description' => 'Publish it.', 'levels' => ['schema'], 'canonical' => false], $all['publish']);
		$this->assertSame(
			['delete' => 'The app "rogue" declared "delete", which is a canonical verb and cannot be redefined.'],
			$catalogue->rejectedDeclarations()
		);
		$this->assertSame($all, $catalogue->all());
		$this->assertSame(1, $rounds);
	}//end testTheCatalogueIsBuiltOnceWithCanonicalEntriesFirst()
}//end class
