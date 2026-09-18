<?php

/**
 * The set of permissions that can be granted on this instance.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/permission-provenance-and-deny/specs/rbac-scopes/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Rbac;

use OCA\OpenRegister\Event\PermissionsDeclaringEvent;
use OCA\OpenRegister\Exception\AuthorizationBlockException;
use OCA\OpenRegister\Service\Rbac\PermissionCatalogue;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\TestCase;

/**
 * What can be granted, what cannot, and what happens to a verb nobody declared.
 */
class PermissionCatalogueTest extends TestCase {

	/**
	 * A catalogue whose declaration round runs the given listener.
	 *
	 * @param callable|null $listener Receives the declaring event, or null for
	 *                                an instance where no app declares anything.
	 *
	 * @return PermissionCatalogue The catalogue under test.
	 */
	private function catalogueWith(?callable $listener = null): PermissionCatalogue {
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(
			static function (object $event) use ($listener): void {
				if ($listener !== null && $event instanceof PermissionsDeclaringEvent) {
					$listener($event);
				}
			}
		);

		return new PermissionCatalogue($dispatcher);
	}//end catalogueWith()

	/**
	 * The seven canonical verbs are always offered, declared or not.
	 *
	 * @return void
	 */
	public function testTheCanonicalVerbsAreAlwaysInTheCatalogue(): void {
		$catalogue = $this->catalogueWith();

		$this->assertSame(
			['read', 'create', 'update', 'delete', 'destroy', 'list', 'export', 'manage', 'assign'],
			$catalogue->verbs()
		);
		foreach ($catalogue->all() as $entry) {
			$this->assertSame('openregister', $entry['app']);
			$this->assertTrue($entry['canonical']);
			$this->assertNotSame('', $entry['description']);
		}
	}//end testTheCanonicalVerbsAreAlwaysInTheCatalogue()

	/**
	 * A declared verb joins the set, naming the app that owns it.
	 *
	 * @return void
	 */
	public function testADeclaredVerbIsOfferedWithItsAppAndDescription(): void {
		$catalogue = $this->catalogueWith(
			static function (PermissionsDeclaringEvent $event): void {
				$event->declareVerb(
					verb: 'publish',
					app: 'opencatalogi',
					description: 'Publish an object to the open catalogue.',
					levels: ['schema', 'object']
				);
			}
		);

		$this->assertTrue($catalogue->isGrantable('publish'));
		$entry = $catalogue->declarationFor('publish');
		$this->assertSame('opencatalogi', $entry['app']);
		$this->assertSame('Publish an object to the open catalogue.', $entry['description']);
		$this->assertSame(['schema', 'object'], $entry['levels']);
		$this->assertFalse($entry['canonical']);
	}//end testADeclaredVerbIsOfferedWithItsAppAndDescription()

	/**
	 * 🔴 A verb nobody declared cannot be granted.
	 *
	 * This is the refusal the whole catalogue exists to make possible. Before
	 * it, an unknown verb matched nothing: it granted nothing, denied nothing,
	 * and the block read as correctly configured in every screen that showed it.
	 *
	 * @return void
	 */
	public function testAnUndeclaredVerbIsRefusedAndNamedInTheMessage(): void {
		$catalogue = $this->catalogueWith();

		$this->expectException(AuthorizationBlockException::class);
		$this->expectExceptionMessageMatches('/"approve"/');

		$catalogue->assertGrantable(
			['read' => ['behandelaars'], 'approve' => ['managers']],
			null,
			'the register "zaken"'
		);
	}//end testAnUndeclaredVerbIsRefusedAndNamedInTheMessage()

	/**
	 * A block using only declared verbs saves.
	 *
	 * The control for the case above: without it, the refusal could be a
	 * refusal of any block at all.
	 *
	 * @return void
	 */
	public function testABlockOfCanonicalVerbsIsStorable(): void {
		$catalogue = $this->catalogueWith();

		$catalogue->assertGrantable(
			[
				'read' => ['behandelaars'],
				'update' => ['behandelaars'],
				'roles' => ['waarnemer' => ['waarnemers']],
				'public' => true,
				'scope' => 'organisation',
				'deny' => ['update' => ['waarnemers']],
			],
			null,
			'the schema "zaak"'
		);

		$this->assertTrue(true, 'A block of declared verbs did not throw.');
	}//end testABlockOfCanonicalVerbsIsStorable()

	/**
	 * 🔴 The verb the delete window already enforces is grantable.
	 *
	 * `destroy` is canonical in PermissionHandler and resolved on every
	 * destruction by DestroyRightService, so a catalogue that omitted it refused
	 * a block naming a verb this instance enforces anyway. The two lists have to
	 * agree in this direction: a verb the engine decides and the catalogue does
	 * not know is a verb an administrator cannot write down (task 8.5, D10).
	 *
	 * @return void
	 */
	public function testTheDestroyVerbTheDeleteWindowEnforcesIsGrantable(): void {
		$catalogue = $this->catalogueWith();

		$this->assertTrue($catalogue->isGrantable('destroy'));
		$this->assertSame(
			PermissionCatalogue::CORE_APP,
			$catalogue->declarationFor('destroy')['app']
		);

		$catalogue->assertGrantable(
			[
				'delete' => ['behandelaars'],
				'destroy' => ['archivarissen'],
				'deny' => ['destroy' => ['behandelaars']],
			],
			[['name' => 'archivaris', 'actions' => ['read', 'delete', 'destroy']]],
			'the register "zaken"'
		);

		$this->assertSame(
			[],
			$catalogue->unknownActionsInRoles([['name' => 'archivaris', 'actions' => ['destroy']]])
		);
	}//end testTheDestroyVerbTheDeleteWindowEnforcesIsGrantable()

	/**
	 * A denied verb is checked too, because a deny uses the same grammar.
	 *
	 * @return void
	 */
	public function testAnUndeclaredVerbInTheDenyBlockIsRefused(): void {
		$catalogue = $this->catalogueWith();

		$this->expectException(AuthorizationBlockException::class);
		$this->expectExceptionMessageMatches('/"besluit_nemen"/');

		$catalogue->assertGrantable(
			['read' => ['behandelaars'], 'deny' => ['besluit_nemen' => ['waarnemers']]],
			null,
			'the schema "zaak"'
		);
	}//end testAnUndeclaredVerbInTheDenyBlockIsRefused()

	/**
	 * 🔴 A role's `actions` array is validated against the catalogue.
	 *
	 * The place a typo survived longest: an unknown action granted nothing, so
	 * the role looked configured and did less than its author thought for as
	 * long as nobody checked.
	 *
	 * @return void
	 */
	public function testAnUnknownActionInARoleIsRefusedNamingTheRole(): void {
		$catalogue = $this->catalogueWith();

		$this->expectException(AuthorizationBlockException::class);
		$this->expectExceptionMessageMatches('/"senior behandelaar".*"approve"/s');

		$catalogue->assertGrantable(
			null,
			[
				['name' => 'behandelaar', 'actions' => ['read', 'update']],
				['name' => 'senior behandelaar', 'actions' => ['read', 'update', 'approve']],
			],
			'the register "zaken"'
		);
	}//end testAnUnknownActionInARoleIsRefusedNamingTheRole()

	/**
	 * A role naming a DECLARED custom verb is storable.
	 *
	 * @return void
	 */
	public function testARoleMayNameADeclaredCustomVerb(): void {
		$catalogue = $this->catalogueWith(
			static function (PermissionsDeclaringEvent $event): void {
				$event->declareVerb(verb: 'approve', app: 'decidiq', description: 'Approve a decision.');
			}
		);

		$catalogue->assertGrantable(
			null,
			[['name' => 'senior behandelaar', 'actions' => ['read', 'approve']]],
			'the register "zaken"'
		);

		$this->assertTrue($catalogue->isGrantable('approve'));
	}//end testARoleMayNameADeclaredCustomVerb()

	/**
	 * 🔴 A canonical verb cannot be redefined by an app.
	 *
	 * An app shadowing `delete` with its own meaning would change what every
	 * existing grant does, without a single block being edited.
	 *
	 * @return void
	 */
	public function testAnAppCannotRedeclareACanonicalVerb(): void {
		$catalogue = $this->catalogueWith(
			static function (PermissionsDeclaringEvent $event): void {
				$event->declareVerb(verb: 'delete', app: 'rogue', description: 'Something else entirely.');
			}
		);

		$this->assertSame('openregister', $catalogue->declarationFor('delete')['app']);
		$this->assertArrayHasKey('delete', $catalogue->rejectedDeclarations());
	}//end testAnAppCannotRedeclareACanonicalVerb()

	/**
	 * Two apps claiming one verb is refused for both, not settled by order.
	 *
	 * @return void
	 */
	public function testAVerbClaimedByTwoAppsIsRefusedForTheSecond(): void {
		$catalogue = $this->catalogueWith(
			static function (PermissionsDeclaringEvent $event): void {
				$event->declareVerb(verb: 'publish', app: 'opencatalogi', description: 'One meaning.');
				$event->declareVerb(verb: 'publish', app: 'portaliq', description: 'Another meaning.');
			}
		);

		$this->assertSame('opencatalogi', $catalogue->declarationFor('publish')['app']);
		$this->assertArrayHasKey('publish', $catalogue->rejectedDeclarations());
		$this->assertStringContainsString('portaliq', $catalogue->rejectedDeclarations()['publish']);
	}//end testAVerbClaimedByTwoAppsIsRefusedForTheSecond()

	/**
	 * A verb the deny grammar cannot address is refused at declaration.
	 *
	 * A verb an administrator can grant and never take away is worse than a
	 * verb that does not exist.
	 *
	 * @return void
	 */
	public function testAVerbTheGrammarCannotAddressIsRefused(): void {
		$catalogue = $this->catalogueWith(
			static function (PermissionsDeclaringEvent $event): void {
				$event->declareVerb(verb: 'pub lish$', app: 'rogue', description: 'Not addressable.');
			}
		);

		$this->assertFalse($catalogue->isGrantable('pub lish$'));
		$this->assertNotSame([], $catalogue->rejectedDeclarations());
	}//end testAVerbTheGrammarCannotAddressIsRefused()

	/**
	 * A failed declaration round leaves the canonical verbs, not a guess.
	 *
	 * Fail-closed in the direction that is loud: a catalogue that is too small
	 * refuses a grant at save time, where somebody sees it. One that guessed at
	 * the missing half would accept a verb nothing evaluates.
	 *
	 * @return void
	 */
	public function testAFailedDeclarationRoundLeavesTheCanonicalVerbs(): void {
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willThrowException(new \RuntimeException('listener exploded'));
		$catalogue = new PermissionCatalogue($dispatcher);

		$this->assertSame(
			expected: ['read', 'create', 'update', 'delete', 'destroy', 'list', 'export', 'manage', 'assign'],
			actual: $catalogue->verbs()
		);
		$this->assertArrayHasKey('*', $catalogue->rejectedDeclarations());
	}//end testAFailedDeclarationRoundLeavesTheCanonicalVerbs()

	/**
	 * No dispatcher at all still answers the canonical set.
	 *
	 * @return void
	 */
	public function testWithoutADispatcherTheCanonicalSetIsStillPublished(): void {
		$catalogue = new PermissionCatalogue();

		$this->assertTrue($catalogue->isGrantable('manage'));
		$this->assertFalse($catalogue->isGrantable('publish'));
	}//end testWithoutADispatcherTheCanonicalSetIsStillPublished()
}//end class
