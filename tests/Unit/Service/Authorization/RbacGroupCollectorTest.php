<?php

/**
 * A declared group must be found wherever it hides — and nothing else may be
 * mistaken for one.
 *
 * The collector feeds group CREATION, so both directions of error are real.
 * Missing a group leaves an authorization block naming something that does not
 * exist, which denies everyone silently because
 * `PermissionHandler::hasGroupPermission()` decides by membership test alone.
 * Inventing one creates a permanent instance-global Nextcloud group out of
 * ordinary data — and a group is expensive to retract, since deleting it
 * destroys memberships and shares.
 *
 * The traps these tests pin down: `roles` is a MAP whose keys are role names,
 * not groups; a rule's `match` conditions carry field names and literals that
 * look exactly like group ids; and `public`/`admin` are principals that must
 * never be created as real groups.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Authorization
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/declared-group-provisioning/specs/rbac-scopes/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Authorization;

use OCA\OpenRegister\Service\Authorization\RbacGroupCollector;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for {@see RbacGroupCollector}.
 */
class RbacGroupCollectorTest extends TestCase {

	/**
	 * System under test.
	 *
	 * @var RbacGroupCollector
	 */
	private RbacGroupCollector $collector;

	/**
	 * Set up the collector.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->collector = new RbacGroupCollector();
	}//end setUp()

	/**
	 * Bare-string and object-form rules both name a group.
	 *
	 * @return void
	 */
	public function testCollectsBothRuleForms(): void {
		$groups = $this->collector->fromAuthorizationBlock(
			authorization: [
				'read' => ['behandelaars'],
				'update' => [['group' => 'juridisch-team']],
			]
		);

		$this->assertSame(['behandelaars', 'juridisch-team'], $groups);
	}//end testCollectsBothRuleForms()

	/**
	 * A rule's match conditions are data, not principals.
	 *
	 * This is the invention direction: `match` values are field names and
	 * literals. Recursing into them would create Nextcloud groups called
	 * `status` or `open`.
	 *
	 * @return void
	 */
	public function testMatchConditionsNeverBecomeGroups(): void {
		$groups = $this->collector->fromAuthorizationBlock(
			authorization: [
				'read' => [
					[
						'group' => 'behandelaars',
						'match' => [
							'status' => 'open',
							'assignee' => '$userId',
						],
					],
				],
			]
		);

		$this->assertSame(['behandelaars'], $groups);
	}//end testMatchConditionsNeverBecomeGroups()

	/**
	 * In a `roles` map the KEYS are role names and only the VALUES are groups.
	 *
	 * Both value shapes are accepted, mirroring OasService::expandRolesForOas()
	 * which casts each assignment with `(array)`.
	 *
	 * @return void
	 */
	public function testRolesMapKeysAreRoleNamesNotGroups(): void {
		$groups = $this->collector->fromAuthorizationBlock(
			authorization: [
				'roles' => [
					'behandelaar' => 'groep-a',
					'beheerder' => ['groep-b', 'groep-c'],
				],
			]
		);

		$this->assertSame(['groep-a', 'groep-b', 'groep-c'], $groups);
		$this->assertNotContains('behandelaar', $groups);
		$this->assertNotContains('beheerder', $groups);
	}//end testRolesMapKeysAreRoleNamesNotGroups()

	/**
	 * `public: true` is an anonymous-access flag, not an action list.
	 *
	 * @return void
	 */
	public function testPublicFlagIsNotAnActionList(): void {
		$groups = $this->collector->fromAuthorizationBlock(
			authorization: [
				'public' => true,
				'read' => ['behandelaars'],
			]
		);

		$this->assertSame(['behandelaars'], $groups);
	}//end testPublicFlagIsNotAnActionList()

	/**
	 * A group that gates a single field appears nowhere in the schema-level
	 * block, and must still be collected.
	 *
	 * @return void
	 */
	public function testCollectsPropertyLevelGroups(): void {
		$groups = $this->collector->fromSchemaDefinition(
			schemaDefinition: [
				'authorization' => ['read' => ['iedereen']],
				'properties' => [
					'bsn' => ['authorization' => ['read' => ['privacy-officers']]],
					'naam' => ['type' => 'string'],
				],
			]
		);

		$this->assertContains('privacy-officers', $groups);
		$this->assertContains('iedereen', $groups);
	}//end testCollectsPropertyLevelGroups()

	/**
	 * Reserved principals are never provisionable, and order/uniqueness hold.
	 *
	 * @return void
	 */
	public function testProvisionableDropsReservedPrincipals(): void {
		$groups = $this->collector->provisionable(
			groups: ['admin', 'behandelaars', 'public', 'behandelaars', '  ', 'juridisch-team']
		);

		$this->assertSame(['behandelaars', 'juridisch-team'], $groups);
	}//end testProvisionableDropsReservedPrincipals()

	/**
	 * The authored scope map contributes group ids the derived floor cannot —
	 * a group declared before any authorization block references it.
	 *
	 * @return void
	 */
	public function testDocumentUnionsAuthoredScopesWithDerivedFloor(): void {
		$document = [
			'components' => [
				'registers' => [
					'zaken' => ['authorization' => ['read' => ['medewerkers']]],
				],
				'schemas' => [
					'zaak' => ['authorization' => ['delete' => ['archivarissen']]],
				],
				'securitySchemes' => [
					'oauth2' => [
						'flows' => [
							'authorizationCode' => [
								'scopes' => [
									'admin' => 'Full administrative access',
									'nog-niet-gebruikt' => 'Declared ahead of use',
								],
							],
						],
					],
				],
			],
		];

		$groups = $this->collector->fromDocument(document: $document);

		$this->assertContains('medewerkers', $groups, 'derived from register authorization');
		$this->assertContains('archivarissen', $groups, 'derived from schema authorization');
		$this->assertContains('nog-niet-gebruikt', $groups, 'authored scope with no authorization block');
		$this->assertNotContains('admin', $groups, 'reserved principal');
	}//end testDocumentUnionsAuthoredScopesWithDerivedFloor()

	/**
	 * Positive control for the empty result.
	 *
	 * A collector that silently returned `[]` for everything would pass every
	 * "nothing was invented" assertion above. This pins that an empty result
	 * means the document genuinely declares nothing.
	 *
	 * @return void
	 */
	public function testEmptyResultIsEarnedNotDefault(): void {
		$this->assertSame([], $this->collector->fromDocument(document: []));
		$this->assertSame([], $this->collector->fromAuthorizationBlock(authorization: null));

		// The same call shape that returned [] above must return a group when
		// one is actually declared — otherwise the assertions are vacuous.
		$this->assertSame(
			['ergens-een-groep'],
			$this->collector->fromDocument(
				document: ['components' => ['schemas' => ['s' => ['authorization' => ['read' => ['ergens-een-groep']]]]]]
			)
		);
	}//end testEmptyResultIsEarnedNotDefault()

}//end class
