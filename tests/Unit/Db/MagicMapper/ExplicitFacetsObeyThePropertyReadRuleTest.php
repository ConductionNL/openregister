<?php

/**
 * A facet the caller names explicitly obeys the property read rule too.
 *
 * `callerMayFacet()` guarded only the auto-discovered `facetable` loop. A
 * caller who wrote `_facets[contactPerson][type]=terms` went straight to the
 * column and got its distinct values back. Found live on the Rotterdam stack:
 * an anonymous call to opencatalogi's applicatielandschap listed the contact
 * person uuid of a stackiq module whose `contactPerson` is ruled
 * `authorization.read: ["authenticated"]`.
 *
 * The handler gets the REAL PropertyRbacHandler through its container, and a
 * database that fails the test if any facet query is built for a withheld
 * property.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Db\MagicMapper
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/specs/row-field-level-security/spec.md#requirement-a-property-read-rule-holds-on-every-route-that-returns-its-value
 */

declare(strict_types=1);

namespace Unit\Db\MagicMapper;

use OCA\OpenRegister\Db\MagicMapper\MagicFacetHandler;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\ConditionMatcher;
use OCA\OpenRegister\Service\PropertyRbacHandler;
use OCA\OpenRegister\Tests\Support\BuildsStateFieldRuleResolver;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

class ExplicitFacetsObeyThePropertyReadRuleTest extends TestCase {
	use BuildsStateFieldRuleResolver;

	private function schema(): Schema {
		$schema = new Schema();
		$schema->setProperties(
			[
				'name' => ['type' => 'string'],
				'contactPerson' => ['type' => 'string', 'authorization' => ['read' => ['authenticated']]],
			]
		);

		return $schema;
	}

	/**
	 * An anonymous caller's handler, over a database that refuses to be queried.
	 */
	private function anonymousHandler(): MagicFacetHandler {
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn(null);
		$groupManager = $this->createMock(IGroupManager::class);

		$rbac = new PropertyRbacHandler(
			$userSession,
			$groupManager,
			$this->createMock(ConditionMatcher::class),
			new NullLogger(),
			self::stateFieldRuleResolver($userSession, $groupManager)
		);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($rbac);

		$db = $this->createMock(IDBConnection::class);
		// The column lookup is the first thing a facet touches, so it is the tripwire.
		$db->expects($this->never())->method('prepare');
		$db->expects($this->never())->method('getQueryBuilder');
		$db->expects($this->never())->method('executeQuery');

		return new MagicFacetHandler($db, new NullLogger(), null, null, null, $container, null);
	}

	public function testAnExplicitFacetOnAWithheldPropertyIsOmitted(): void {
		$facets = $this->anonymousHandler()->getSimpleFacets(
			tableName: 'openregister_table_21_119',
			query: ['_facets' => ['contactPerson' => ['type' => 'terms']]],
			register: new Register(),
			schema: $this->schema()
		);

		$this->assertArrayNotHasKey('contactPerson', $facets);
	}

	public function testAnExplicitFacetOnAWithheldPropertyIsOmittedFromAUnion(): void {
		$facets = $this->anonymousHandler()->getSimpleFacetsUnion(
			tableConfigs: [['tableName' => 'openregister_table_21_119', 'register' => new Register(), 'schema' => $this->schema()]],
			query: ['_facets' => ['contactPerson' => ['type' => 'terms']]]
		);

		$this->assertArrayNotHasKey('contactPerson', $facets);
	}

	/**
	 * The control: the rule admits a signed-in caller, so the guard asks and says yes.
	 */
	public function testTheRuleStillAdmitsACallerItNames(): void {
		$user = $this->createMock(\OCP\IUser::class);
		$user->method('getUID')->willReturn('alice');
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('getUserGroupIds')->willReturn([]);

		$rbac = new PropertyRbacHandler(
			$userSession,
			$groupManager,
			$this->createMock(ConditionMatcher::class),
			new NullLogger(),
			self::stateFieldRuleResolver($userSession, $groupManager)
		);

		$this->assertTrue($rbac->canReadProperty(schema: $this->schema(), property: 'contactPerson', object: []));
	}
}
