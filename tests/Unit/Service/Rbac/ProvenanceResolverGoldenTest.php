<?php

/**
 * Golden test for ProvenanceResolver::forActions().
 *
 * For a matrix of callers, cascade shapes (object / schema / register blocks,
 * role assignments, malformed blocks) and denials (none, enforced, staged),
 * pins the full provenance record of every action — granted AND refused —
 * against tests/Unit/Service/Rbac/fixtures/provenance-golden.json, which was
 * written from the code before its units were split. Set UPDATE_GOLDEN=1 to
 * rewrite it.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Rbac;

use OCA\OpenRegister\Service\Rbac\DenyEntryMatcher;
use OCA\OpenRegister\Service\Rbac\DenyResolver;
use OCA\OpenRegister\Service\Rbac\ProvenanceResolver;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Rbac\ProvenanceResolver
 */
final class ProvenanceResolverGoldenTest extends TestCase {

	private const FIXTURE = __DIR__.'/fixtures/provenance-golden.json';

	private const ACTIONS = ['read', 'update', 'delete', 'publish'];

	/**
	 * Callers as [userId, groups].
	 *
	 * @return array<string, array{0: string, 1: string[]}>
	 */
	private function callers(): array {
		return [
			'ana-behandelaars' => ['ana', ['behandelaars']],
			'bob-none' => ['bob', []],
			'cor-admins' => ['cor', ['admin', 'behandelaars']],
		];
	}//end callers()

	/**
	 * Cascade shapes, as forActions() blocks.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function cascades(): array {
		$roles = [
			['name' => 'behandelaar', 'actions' => ['read', 'update']],
			['name' => 'beheerder', 'actions' => ['read', 'update', 'delete']],
		];

		return [
			'empty' => [],
			'all-null' => ['object' => null, 'schema' => null, 'register' => null],
			'empty-arrays' => ['object' => [], 'schema' => [], 'register' => []],
			'schema-group' => ['schema' => ['read' => ['behandelaars'], 'update' => ['user:ana']]],
			'object-beats-schema' => [
				'object' => ['read' => ['user:bob']],
				'schema' => ['read' => ['behandelaars'], 'delete' => ['admin']],
				'register' => ['read' => ['public'], 'publish' => ['behandelaars']],
			],
			'register-only' => ['register' => ['read' => ['behandelaars'], 'update' => 'not-a-list']],
			'schema-roles' => [
				'schema' => ['roles' => ['behandelaar' => ['behandelaars'], 'beheerder' => ['admin']]],
				'roleDefinitions' => $roles,
			],
			'flat-role-map' => [
				'schema' => ['roles' => ['behandelaar' => ['behandelaars']]],
				'roleDefinitions' => ['behandelaar' => ['read', 'delete']],
			],
			'malformed-roles' => [
				'object' => ['roles' => 'x'],
				'schema' => ['roles' => [0 => ['behandelaars'], 'behandelaar' => 'behandelaars', 'onbekend' => ['behandelaars']]],
				'roleDefinitions' => $roles,
			],
			'direct-before-role-same-level' => [
				'schema' => ['update' => ['admin'], 'roles' => ['behandelaar' => ['behandelaars']]],
				'roleDefinitions' => $roles,
			],
			'role-at-register-direct-at-schema-miss' => [
				'schema' => ['read' => ['user:zed']],
				'register' => ['roles' => ['beheerder' => ['behandelaars', 'admin']]],
				'roleDefinitions' => $roles,
			],
		];
	}//end cascades()

	/**
	 * Denial shapes, keyed by action.
	 *
	 * @return array<string, array<string, array|null>>
	 */
	private function denialSets(): array {
		$deny = ['rule' => ['group' => 'behandelaars'], 'principal' => 'behandelaars', 'action' => 'read', 'conditional' => false];

		return [
			'read-and-delete' => ['read' => $deny, 'delete' => ['action' => 'delete']],
			'explicit-null' => ['read' => null],
		];
	}//end denialSets()

	/**
	 * Compute every record of the matrix.
	 *
	 * @return array<string, mixed>
	 */
	private function matrix(): array {
		$resolver = new ProvenanceResolver(new DenyResolver(new DenyEntryMatcher()));
		$denyReader = new DenyResolver(new DenyEntryMatcher());
		$out = [];
		foreach ($this->callers() as $callerKey => [$uid, $groups]) {
			$principals = $denyReader->principalsFor(userId: $uid, userGroups: $groups);
			// Every cascade, no denial.
			foreach ($this->cascades() as $cascadeKey => $blocks) {
				$out[$callerKey.'|'.$cascadeKey] = $resolver->forActions(self::ACTIONS, $principals, $blocks);
			}

			// Every denial shape, enforced and staged, over three cascades.
			foreach (['empty', 'schema-group', 'schema-roles'] as $cascadeKey) {
				$blocks = $this->cascades()[$cascadeKey];
				foreach ($this->denialSets() as $denialKey => $denials) {
					foreach ([true, false] as $enforced) {
						$key = $callerKey.'|'.$cascadeKey.'|'.$denialKey.'|'.($enforced ? 'enforced' : 'staged');
						$out[$key] = $resolver->forActions(self::ACTIONS, $principals, $blocks, $denials, $enforced);
					}
				}
			}
		}

		// The defaults: no blocks, no denials, enforced.
		$out['defaults'] = $resolver->forActions(self::ACTIONS, ['user:ana']);
		$out['no-actions'] = $resolver->forActions([], ['user:ana'], ['schema' => ['read' => ['user:ana']]]);

		return $out;
	}//end matrix()

	/**
	 * Every record in the matrix matches the golden.
	 *
	 * @return void
	 */
	public function testForActionsMatchesTheGolden(): void {
		$actual = $this->matrix();
		if (getenv('UPDATE_GOLDEN') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES))."\n");
		}

		$expected = json_decode((string) file_get_contents(self::FIXTURE), true);
		$this->assertSame(array_keys($expected), array_keys($actual));
		foreach ($expected as $key => $records) {
			$this->assertSame($records, $actual[$key], $key);
		}
	}//end testForActionsMatchesTheGolden()

	/**
	 * A block or denial that is not an array (or null) is refused with a TypeError.
	 *
	 * @return void
	 */
	public function testANonArrayBlockOrDenialIsRefused(): void {
		$resolver = new ProvenanceResolver(new DenyResolver(new DenyEntryMatcher()));
		$cases    = [
			'object' => [['object' => 'scalar', 'schema' => ['read' => ['user:ana']]], []],
			'schema' => [['schema' => 7], []],
			'register' => [['register' => true], []],
			'denial' => [['schema' => ['read' => ['user:ana']]], ['read' => 'denied']],
		];
		foreach ($cases as $label => [$blocks, $denials]) {
			$thrown = null;
			try {
				$resolver->forActions(['read'], ['user:ana'], $blocks, $denials);
			} catch (\TypeError $e) {
				$thrown = \TypeError::class;
			}

			$this->assertSame(\TypeError::class, $thrown, $label);
		}

		// With no action asked about, nothing is read and nothing is refused.
		$this->assertSame([], $resolver->forActions([], ['user:ana'], ['object' => 'scalar'], ['read' => 'x']));
		// A denial for an action not asked about is never read.
		$this->assertTrue($resolver->forActions(['update'], ['user:ana'], ['schema' => ['update' => ['user:ana']]], ['read' => 'x'])['update']['granted']);
	}//end testANonArrayBlockOrDenialIsRefused()

	/**
	 * The matrix exercises both outcomes and every source, so a silent shrink fails.
	 *
	 * @return void
	 */
	public function testTheGoldenCoversAllowedAndRefusedOutcomes(): void {
		$sources = [];
		$granted = [];
		foreach ($this->matrix() as $records) {
			foreach ($records as $record) {
				$sources[$record['source']] = true;
				$granted[var_export($record['granted'], true)] = true;
			}
		}

		ksort($sources);
		$this->assertSame(['default-open', 'deny', 'none', 'object', 'register', 'role', 'schema'], array_keys($sources));
		$this->assertArrayHasKey('true', $granted);
		$this->assertArrayHasKey('false', $granted);
	}//end testTheGoldenCoversAllowedAndRefusedOutcomes()
}//end class
