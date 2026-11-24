<?php

/**
 * PropertyRbacHandler::readOnlyProperties: the properties the current reader
 * may read but not change, the hint `@self.readOnlyProperties` carries.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/modelling-field-access-editor/specs/row-field-level-security/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\ConditionMatcher;
use OCA\OpenRegister\Service\Lifecycle\StateFieldRuleResolver;
use OCA\OpenRegister\Service\PropertyRbacCaller;
use OCA\OpenRegister\Service\PropertyRbacHandler;
use OCA\OpenRegister\Service\PropertyRbacRules;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenRegister\Service\PropertyRbacHandler
 * @uses \OCA\OpenRegister\Service\PropertyRbacCaller
 * @uses \OCA\OpenRegister\Service\PropertyRbacRules
 * @uses \OCA\OpenRegister\Service\PropertyAccessRequest
 * @uses \OCA\OpenRegister\Service\SchemaProperty
 * @uses \OCA\OpenRegister\Db\Schema
 * @uses \OCA\OpenRegister\Db\Schema\PropertyAuthorization
 */
final class PropertyRbacHandlerReadOnlyTest extends TestCase {

	/**
	 * A handler for a signed-in user of the given groups.
	 *
	 * @param string[] $groups  The caller's groups.
	 * @param bool     $matches Whether a rule's `match` condition is met.
	 *
	 * @return PropertyRbacHandler The handler.
	 */
	private function handlerFor(array $groups, bool $matches = true): PropertyRbacHandler {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('u1');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('getUserGroupIds')->willReturn($groups);

		$matcher = $this->createMock(ConditionMatcher::class);
		$matcher->method('objectMatchesConditions')->willReturn($matches);
		$matcher->method('filterOrganisationMatchForCreate')->willReturnArgument(0);

		return new PropertyRbacHandler(
			caller: new PropertyRbacCaller($session, $groupManager),
			rules: new PropertyRbacRules($matcher, $this->createMock(StateFieldRuleResolver::class)),
			logger: $this->createMock(LoggerInterface::class),
		);
	}//end handlerFor()

	/**
	 * A schema whose properties carry the authorization blocks given.
	 *
	 * @param array<string, array|null> $authByProperty Authorization block per property name.
	 *
	 * @return Schema The schema.
	 */
	private function schemaWith(array $authByProperty): Schema {
		$properties = [];
		foreach ($authByProperty as $name => $auth) {
			$properties[$name] = ['type' => 'string'];
			if ($auth !== null) {
				$properties[$name]['authorization'] = $auth;
			}
		}

		$schema = new Schema();
		$schema->setProperties($properties);

		return $schema;
	}//end schemaWith()

	/**
	 * A field a group may read but not change is read-only; one with no update
	 * rule, and one the reader may not read, are not.
	 *
	 * @return void
	 */
	public function testReadableButNotUpdatableFieldsAreReadOnly(): void {
		$schema = $this->schemaWith([
			// Readable by anyone, changeable only by teamleiders: read-only for a caseworker.
			'besluitdatum' => ['read' => [], 'update' => ['teamleiders']],
			// Readable and changeable only by hr: the caseworker cannot read it, so not listed.
			'salaris' => ['read' => ['hr'], 'update' => ['hr']],
			// Readable by anyone, no update rule: writable, so not read-only.
			'toelichting' => ['read' => ['authenticated'], 'update' => []],
			// No authorization block: not considered at all.
			'onderwerp' => null,
		]);

		$readOnly = $this->handlerFor(groups: ['caseworkers'])->readOnlyProperties(schema: $schema, object: []);

		$this->assertSame(['besluitdatum'], $readOnly);
	}//end testReadableButNotUpdatableFieldsAreReadOnly()

	/**
	 * A member of the group that may change the field sees nothing read-only.
	 *
	 * @return void
	 */
	public function testTheGroupThatMayChangeItSeesItWritable(): void {
		$schema = $this->schemaWith(['besluitdatum' => ['read' => [], 'update' => ['teamleiders']]]);

		$this->assertSame([], $this->handlerFor(groups: ['teamleiders'])->readOnlyProperties(schema: $schema, object: []));
	}//end testTheGroupThatMayChangeItSeesItWritable()

	/**
	 * An admin may change every field, so nothing is read-only for them.
	 *
	 * @return void
	 */
	public function testAdminHasNothingReadOnly(): void {
		$schema = $this->schemaWith(['salaris' => ['read' => ['hr'], 'update' => ['hr']]]);

		$this->assertSame([], $this->handlerFor(groups: ['admin'])->readOnlyProperties(schema: $schema, object: []));
	}//end testAdminHasNothingReadOnly()

	/**
	 * A schema without any property-level authorization has nothing read-only.
	 *
	 * @return void
	 */
	public function testASchemaWithoutPropertyAuthorizationHasNothingReadOnly(): void {
		$schema = $this->schemaWith(['onderwerp' => null]);

		$this->assertSame([], $this->handlerFor(groups: ['caseworkers'])->readOnlyProperties(schema: $schema, object: []));
	}//end testASchemaWithoutPropertyAuthorizationHasNothingReadOnly()

	/**
	 * An update rule's `match` condition is evaluated against the object, so a
	 * field the caller may change only on matching rows is read-only elsewhere.
	 *
	 * @return void
	 */
	public function testAnUpdateMatchConditionIsEvaluatedAgainstTheObject(): void {
		$schema = $this->schemaWith([
			'besluit' => ['read' => [], 'update' => [['group' => 'caseworkers', 'match' => ['status' => 'open']]]],
		]);

		$onOpen = $this->handlerFor(groups: ['caseworkers'], matches: true)->readOnlyProperties(schema: $schema, object: ['status' => 'open']);
		$onClosed = $this->handlerFor(groups: ['caseworkers'], matches: false)->readOnlyProperties(schema: $schema, object: ['status' => 'closed']);

		$this->assertSame([], $onOpen, 'changeable on an open case, so not read-only there');
		$this->assertSame(['besluit'], $onClosed, 'not changeable on a closed case, so read-only there');
	}//end testAnUpdateMatchConditionIsEvaluatedAgainstTheObject()
}//end class
