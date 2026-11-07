<?php

/**
 * SchemaAuthorizationGoldenTest
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Rbac\PermissionCatalogue;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * Pins every allowed and refused outcome of the entity's authorization methods.
 *
 * The fixture was written from the entity before its authorization rules
 * moved into collaborators: which blocks validateAuthorization() accepts and
 * with which message it refuses the others, what hasPermission() answers for
 * each actor and object, and what the property-level readers return. A move
 * that changes any one of them fails here, naming the case.
 *
 * Regenerate only on purpose: UPDATE_GOLDEN=1 vendor/bin/phpunit <this file>.
 */
class SchemaAuthorizationGoldenTest extends TestCase {
	private const FIXTURE = __DIR__.'/fixtures/schema-authorization-golden.json';

	public function testAuthorizationOutcomesMatchGolden(): void {
		$actual = [];
		foreach ($this->blocks() as $name => [$authorization, $properties, $configuration]) {
			$actual['validate '.$name] = $this->validation($authorization, $properties, $configuration);
		}

		foreach ($this->permissionCases() as $name => [$authorization, $arguments]) {
			$schema = new Schema();
			$schema->setAuthorization($authorization);
			$actual['permission '.$name] = $schema->hasPermission(...$arguments);
		}

		foreach ($this->propertyCases() as $name => $properties) {
			$actual['property '.$name] = $this->propertyReads($properties);
		}

		foreach ($this->declaredActionCases() as $name => $configuration) {
			$schema = new Schema();
			$schema->setConfiguration($configuration);
			$actual['declared '.$name] = $schema->declaredActionNames();
		}

		foreach (['read', 'update', 'missing'] as $action) {
			$schema = new Schema();
			$schema->setAuthorization(['read' => ['a', ['group' => 'b']], 'update' => []]);
			$actual['groups '.$action] = $schema->getAuthorizedGroups($action);
			$actual['groups empty '.$action] = (new Schema())->getAuthorizedGroups($action);
		}

		$this->assertMatchesFixture($actual);
	}

	private function assertMatchesFixture(array $actual): void {
		if (getenv('UPDATE_GOLDEN') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
			$this->addToAssertionCount(1);
			return;
		}

		$expected = json_decode((string) file_get_contents(self::FIXTURE), true);
		$this->assertSame(array_keys($expected), array_keys($actual));
		foreach ($expected as $name => $outcome) {
			$this->assertSame(json_encode($outcome), json_encode($actual[$name]), $name);
		}
	}

	private function validation(array $authorization, array $properties, array $configuration): array|bool {
		$schema = new Schema();
		$schema->setProperties($properties);
		$schema->setConfiguration($configuration);
		$schema->setAuthorization($authorization);
		try {
			return $schema->validateAuthorization();
		} catch (Throwable $e) {
			return ['threw' => get_class($e).': '.$e->getMessage()];
		}
	}

	private function propertyReads(array $properties): array {
		$schema = new Schema();
		$schema->setProperties($properties);
		$reads = [
			'has'  => $schema->hasPropertyAuthorization(),
			'all'  => $schema->getPropertiesWithAuthorization(),
			'each' => [],
		];
		foreach (array_merge(array_keys($properties), ['absent']) as $name) {
			$reads['each'][(string) $name] = $schema->getPropertyAuthorization((string) $name);
		}

		return $reads;
	}

	private function blocks(): array {
		$action = [
			'x-openregister-action' => [
				'approve' => ['name' => 'Approve', 'description' => 'Sign off'],
				'half'    => ['name' => 'Half'],
				'read'    => ['name' => 'Read', 'description' => 'canonical, not declarable'],
			],
		];
		$mixedMatch = ['a' => ['$in' => ['x', 'y']], 'b' => 'plain', 'c' => ['notop' => 1]];
		$blocks = [
			'empty'                      => [[], [], []],
			'unknown action'             => [['fly' => ['g']], [], []],
			'rules not a list'           => [['read' => 'g'], [], []],
			'empty group'                => [['read' => ['  ']], [], []],
			'number rule'                => [['read' => [5]], [], []],
			'conditional without group'  => [['read' => [['match' => ['a' => 1]]]], [], []],
			'conditional empty group'    => [['read' => [['group' => ' ']]], [], []],
			'conditional number group'   => [['read' => [['group' => 3]]], [], []],
			'match not an array'         => [['read' => [['group' => 'g', 'match' => 'x']]], [], []],
			'unsupported operator'       => [['read' => [['group' => 'g', 'match' => ['a' => ['$regex' => 'x']]]]], [], []],
			'in needs a list'            => [['read' => [['group' => 'g', 'match' => ['a' => ['$in' => 'x']]]]], [], []],
			'nin map'                    => [['read' => [['group' => 'g', 'match' => ['a' => ['$nin' => ['k' => 'x']]]]]], [], []],
			'in token'                   => [['read' => [['group' => 'g', 'match' => ['a' => ['$in' => '$user.groups']]]]], [], []],
			'in list'                    => [['read' => [['group' => 'g', 'match' => $mixedMatch]]], [], []],
			'cross organisation text'    => [['read' => [['group' => 'g', 'crossOrganisation' => 'yes']]], [], []],
			'cross organisation true'    => [['read' => [['group' => 'g', 'crossOrganisation' => true]]], [], []],
			'inherit flag text'          => [['inheritFromPublic' => 'yes'], [], []],
			'inherit flag true'          => [['inheritFromPublic' => true, 'read' => ['g']], [], []],
			'scope unknown'              => [['scope' => 'world'], [], []],
			'scope organisation'         => [['scope' => 'organisation'], [], []],
			'scope private'              => [['scope' => 'private'], [], []],
			'roles text'                 => [['roles' => 'x'], [], []],
			'roles nameless'             => [['roles' => [['g']]], [], []],
			'roles blank name'           => [['roles' => [' ' => ['g']]], [], []],
			'role without groups'        => [['roles' => ['r' => []]], [], []],
			'role groups text'           => [['roles' => ['r' => 'g']], [], []],
			'role blank group'           => [['roles' => ['r' => ['g', '']]], [], []],
			'roles ok'                   => [['roles' => ['r' => ['g1', 'g2']]], [], []],
			'reveal wrong'               => [['reveal' => 'always'], [], []],
			'reveal explicit'            => [['reveal' => 'explicit'], [], []],
			'declared action'            => [['approve' => ['g']], [], $action],
			'half declared action'       => [['half' => ['g']], [], $action],
			'property not an array'      => [[], ['p' => ['type' => 'string', 'authorization' => 'g']], []],
			'property unknown action'    => [[], ['p' => ['type' => 'string', 'authorization' => ['fly' => ['g']]]], []],
			'property bad rule'          => [[], ['p' => ['type' => 'string', 'authorization' => ['read' => [['match' => []]]]]], []],
			'property ok'                => [[], ['p' => ['type' => 'string', 'authorization' => ['read' => ['g']]], 'q' => 'plain'], []],
			'schema before property'     => [['fly' => ['g']], ['p' => ['type' => 'string', 'authorization' => 'g']], []],
			'first refusal wins'         => [['read' => [''], 'fly' => ['g']], [], []],
		];
		foreach (array_keys(PermissionCatalogue::CANONICAL) as $canonical) {
			$blocks['canonical '.$canonical] = [[$canonical => ['g', ['group' => 'h', 'match' => ['_organisation' => '$organisation']]]], [], []];
		}

		foreach (PermissionCatalogue::CONTROL_KEYS as $control) {
			$blocks['control '.$control.' as text'] = [[$control => 'anything'], [], []];
		}

		return $blocks;
	}

	private function permissionCases(): array {
		$conditional = ['read' => [['group' => 'g', 'match' => ['status' => 'open']]]];
		$organisation = ['read' => [['group' => 'g', 'match' => ['_organisation' => '$organisation']]]];
		$relation = ['read' => [['group' => 'g', 'match' => ['zaak' => 'z1']]]];
		$second = ['read' => [['group' => 'g', 'match' => ['status' => 'open']], ['group' => 'g', 'match' => ['status' => 'closed']]]];

		return [
			'admin group'                 => [['read' => ['x']], ['admin', 'update']],
			'admin user group'            => [['read' => ['x']], ['g', 'update', null, 'admin']],
			'owner'                       => [['read' => ['x']], ['g', 'update', 'u1', null, 'u1']],
			'other owner'                 => [['read' => ['x']], ['g', 'update', 'u1', null, 'u2']],
			'owner without user'          => [['read' => ['x']], ['g', 'update', null, null, 'u2']],
			'no block'                    => [[], ['g', 'delete']],
			'unlisted action'             => [['read' => ['g']], ['g', 'delete']],
			'empty action'                => [['read' => []], ['g', 'read']],
			'public unlisted'             => [['read' => ['public']], ['public', 'update']],
			'group listed'                => [['read' => ['h', 'g']], ['g', 'read']],
			'group not listed'            => [['read' => ['h']], ['g', 'read']],
			'conditional without match'   => [['read' => [['group' => 'g']]], ['g', 'read']],
			'conditional empty match'     => [['read' => [['group' => 'g', 'match' => []]]], ['g', 'read']],
			'conditional other group'     => [$conditional, ['h', 'read', null, null, null, ['status' => 'open']]],
			'conditional met'             => [$conditional, ['g', 'read', null, null, null, ['status' => 'open']]],
			'conditional unmet'           => [$conditional, ['g', 'read', null, null, null, ['status' => 'closed']]],
			'conditional no data'         => [$conditional, ['g', 'read']],
			'organisation no active'      => [$organisation, ['g', 'read', null, null, null, [], 'o1']],
			'organisation same'           => [$organisation, ['g', 'read', null, null, null, [], 'o1', 'o1']],
			'organisation other'          => [$organisation, ['g', 'read', null, null, null, [], 'o1', 'o2']],
			'organisation from data only' => [$organisation, ['g', 'read', null, null, null, ['_organisation' => 'o1'], null, 'o1']],
			'resolved relation'           => [$relation, ['g', 'read', null, null, null, ['zaak' => ['id' => 'z1']]]],
			'resolved other relation'     => [$relation, ['g', 'read', null, null, null, ['zaak' => ['id' => 'z2']]]],
			'second entry matches'        => [$second, ['g', 'read', null, null, null, ['status' => 'closed']]],
			'loose type'                  => [['read' => [['group' => 'g', 'match' => ['n' => '1']]]], ['g', 'read', null, null, null, ['n' => 1]]],
			'entry without group'         => [['read' => [['match' => ['a' => 1]], 'g']], ['g', 'read']],
			'number entry'                => [['read' => [5]], ['5', 'read']],
		];
	}

	private function propertyCases(): array {
		return [
			'none'           => [],
			'plain'          => ['a' => ['type' => 'string'], 'b' => 'not an array'],
			'authorization'  => ['a' => ['type' => 'string', 'authorization' => ['read' => ['g']]], 'b' => ['type' => 'string']],
			'empty block'    => ['a' => ['type' => 'string', 'authorization' => []]],
			'scope'          => ['a' => ['type' => 'string', 'scope' => ' organisation ']],
			'blank scope'    => ['a' => ['type' => 'string', 'scope' => '  ']],
			'number scope'   => ['a' => ['type' => 'string', 'scope' => 5]],
			'block wins'     => ['a' => ['type' => 'string', 'scope' => 'private', 'authorization' => ['read' => ['g']]]],
			'numeric names'  => [0 => ['type' => 'string', 'authorization' => ['update' => ['g']]]],
		];
	}

	private function declaredActionCases(): array {
		return [
			'none'          => [],
			'not a map'     => ['x-openregister-action' => 'approve'],
			'mixed'         => ['x-openregister-action' => [
				'approve'  => ['name' => 'Approve', 'description' => 'Sign off'],
				'half'     => ['name' => 'Half'],
				'text'     => 'approve',
				'read'     => ['name' => 'Read', 'description' => 'canonical'],
				'public'   => ['name' => 'Public', 'description' => 'control key'],
				'reject'   => ['name' => 'Reject', 'description' => 'Send back'],
			]],
		];
	}
}
