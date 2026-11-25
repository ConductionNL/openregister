<?php

/**
 * The one reader of rule lists both permission planes ask.
 *
 * Absence must never read as a grant here, the fail-closed write set must be
 * exactly the one the object plane enforced, and a refusal must be recorded
 * once per kind, not once per row.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/consolidate-permission-handling/specs/consolidate-permission-handling/spec.md#requirement-one-evaluator-decides-anonymous-access-on-both-planes
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Rbac;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Service\Rbac\AuthorizationRuleEvaluator;
use OCA\OpenRegister\Db\Tenancy\EntityRbacTrait;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * @covers \OCA\OpenRegister\Service\Rbac\AuthorizationRuleEvaluator
 */
class AuthorizationRuleEvaluatorTest extends TestCase {

	private AuthorizationRuleEvaluator $evaluator;

	protected function setUp(): void {
		$this->evaluator = new AuthorizationRuleEvaluator();
		$this->evaluator->forgetRecordedRefusals();
	}

	protected function tearDown(): void {
		$this->evaluator->forgetRecordedRefusals();
	}

	public function testAnEntryNamesAGroupAsABareStringOrAGroupObject(): void {
		$this->assertTrue($this->evaluator->entryNamesGroup(entry: 'public', group: 'public'));
		$this->assertTrue($this->evaluator->entryNamesGroup(entry: ['group' => 'public', 'match' => ['x' => 1]], group: 'public'));
		$this->assertFalse($this->evaluator->entryNamesGroup(entry: 'editors', group: 'public'));
		$this->assertFalse($this->evaluator->entryNamesGroup(entry: ['user' => 'public'], group: 'public'));
		$this->assertFalse($this->evaluator->entryNamesGroup(entry: null, group: 'public'));
	}

	public function testAbsenceIsNeverAnExplicitGrant(): void {
		$this->assertFalse($this->evaluator->explicitlyGrants(authorization: null, action: 'read', group: 'public'));
		$this->assertFalse($this->evaluator->explicitlyGrants(authorization: [], action: 'read', group: 'public'));
		$this->assertFalse($this->evaluator->explicitlyGrants(authorization: ['read' => []], action: 'read', group: 'public'));
		$this->assertFalse($this->evaluator->explicitlyGrants(authorization: ['read' => 'public'], action: 'read', group: 'public'));
		$this->assertFalse($this->evaluator->explicitlyGrants(authorization: ['read' => ['editors']], action: 'read', group: 'public'));
		$this->assertTrue($this->evaluator->explicitlyGrants(authorization: ['read' => ['editors', 'public']], action: 'read', group: 'public'));
	}

	public function testTheFailClosedWriteSetIsExactlyTheObjectPlanesOne(): void {
		$this->assertSame(
			['create', 'update', 'delete', 'destroy', 'addFiles', 'deleteFiles'],
			AuthorizationRuleEvaluator::ANONYMOUS_FAIL_CLOSED_WRITE_ACTIONS
		);
	}

	public function testAnAnonymousWriteIsRefusedUnlessPublicIsGrantedThatVerb(): void {
		$readOnly = ['read' => ['public']];
		foreach (AuthorizationRuleEvaluator::ANONYMOUS_FAIL_CLOSED_WRITE_ACTIONS as $verb) {
			$this->assertTrue($this->evaluator->refusesAnonymousWrite(authorization: $readOnly, action: $verb), $verb . ' on a read-only public block');
			$this->assertTrue($this->evaluator->refusesAnonymousWrite(authorization: null, action: $verb), $verb . ' with no block');
			$this->assertFalse($this->evaluator->refusesAnonymousWrite(authorization: [$verb => [['group' => 'public']]], action: $verb), $verb . ' granted to public');
		}
	}

	public function testAReadIsNotDecidedByTheWriteRule(): void {
		$this->assertFalse($this->evaluator->refusesAnonymousWrite(authorization: null, action: 'read'));
		$this->assertFalse($this->evaluator->refusesAnonymousWrite(authorization: ['read' => ['editors']], action: 'read'));
	}

	public function testAnAnonymousCallerIsAdmittedOnlyByANamedPublic(): void {
		$this->assertTrue($this->evaluator->admitsAnonymous(rules: ['public']));
		$this->assertTrue($this->evaluator->admitsAnonymous(rules: ['editors', ['group' => 'public']]));
		$this->assertFalse($this->evaluator->admitsAnonymous(rules: null));
		$this->assertFalse($this->evaluator->admitsAnonymous(rules: []));
		$this->assertFalse($this->evaluator->admitsAnonymous(rules: ['*']), 'the wildcard means any of the caller\'s groups, and an anonymous caller has none');
		$this->assertFalse($this->evaluator->admitsAnonymous(rules: ['authenticated']));
	}

	public function testAGroupListMatchesACallerGroupOrTheWildcard(): void {
		$this->assertTrue($this->evaluator->anyGroupAllowed(userGroups: ['staff', 'editors'], allowedGroups: ['editors']));
		$this->assertTrue($this->evaluator->anyGroupAllowed(userGroups: [], allowedGroups: ['*']));
		$this->assertFalse($this->evaluator->anyGroupAllowed(userGroups: ['staff'], allowedGroups: ['editors']));
		$this->assertFalse($this->evaluator->anyGroupAllowed(userGroups: [], allowedGroups: ['editors']));
	}

	public function testAGroupListComparesLooselyAsTheEntityPlaneAlwaysHas(): void {
		$this->assertTrue($this->evaluator->anyGroupAllowed(userGroups: ['5'], allowedGroups: [5]));
	}

	public function testARefusalIsRecordedOncePerKindPerRequest(): void {
		$this->assertTrue($this->evaluator->firstRefusal(plane: 'entity', entityType: 'register', action: 'update', cause: 'no-rule'));
		$this->assertFalse($this->evaluator->firstRefusal(plane: 'entity', entityType: 'register', action: 'update', cause: 'no-rule'));
		$this->assertFalse((new AuthorizationRuleEvaluator())->firstRefusal(plane: 'entity', entityType: 'register', action: 'update', cause: 'no-rule'), 'a second evaluator in the same request shares the memory');

		$this->assertTrue($this->evaluator->firstRefusal(plane: 'entity', entityType: 'register', action: 'update', cause: 'rule-refused'));
		$this->assertTrue($this->evaluator->firstRefusal(plane: 'entity', entityType: 'register', action: 'read', cause: 'no-rule'));
		$this->assertTrue($this->evaluator->firstRefusal(plane: 'entity', entityType: 'schema', action: 'update', cause: 'no-rule'));
		$this->assertTrue($this->evaluator->firstRefusal(plane: 'object', entityType: 'register', action: 'update', cause: 'no-rule'));

		$this->evaluator->forgetRecordedRefusals();
		$this->assertTrue($this->evaluator->firstRefusal(plane: 'entity', entityType: 'register', action: 'update', cause: 'no-rule'));
	}

	/**
	 * Both planes ask this evaluator rather than keeping a reading of their own.
	 *
	 * A second implementation arriving on either plane is the drift this class
	 * exists to end, so the delegation is pinned by name.
	 */
	public function testBothPlanesAskThisEvaluator(): void {
		$this->assertStringContainsString('->refusesAnonymousWrite(', self::body(class: PermissionHandler::class, method: 'anonymousVerdict'));
		$this->assertStringContainsString('->anyGroupAllowed(', self::body(class: EntityRbacTrait::class, method: 'groupsGrant'));
		$this->assertStringContainsString('new AuthorizationRuleEvaluator()', self::body(class: PermissionHandler::class, method: 'ruleEvaluator'));
		$this->assertStringContainsString('new AuthorizationRuleEvaluator()', self::body(class: EntityRbacTrait::class, method: 'entityRuleEvaluator'));
		$this->assertFalse(method_exists(PermissionHandler::class, 'entryNamesGroup'), 'the object plane must not keep its own entry reader');
	}

	/**
	 * The source of one method.
	 *
	 * @param class-string $class  The class or trait.
	 * @param string       $method The method.
	 *
	 * @return string The method's source lines.
	 */
	private static function body(string $class, string $method): string {
		$reflection = new ReflectionMethod($class, $method);
		$lines = file((string)$reflection->getFileName());

		return implode('', array_slice($lines, $reflection->getStartLine() - 1, $reflection->getEndLine() - $reflection->getStartLine() + 1));
	}
}
