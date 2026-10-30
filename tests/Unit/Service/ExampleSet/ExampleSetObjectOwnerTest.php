<?php

/**
 * A declared object may name its owner from the set's cast: written as that
 * owner on create, handed to it on `--refresh`, and refused when the value is
 * no account the cast declares.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/changes/example-set-object-owner/specs/example-sets/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ExampleSet;

/**
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetInstaller
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetRewriter
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetValues
 */
class ExampleSetObjectOwnerTest extends ExampleSetTestCase {
	/**
	 * The cast, and one decision whose owner is given.
	 *
	 * @param string $owner The declared owner.
	 *
	 * @return void
	 */
	private function decisionOwnedBy(string $owner): void {
		$this->store->offer(register: 'besluiten', schema: 'decision');
		$this->app(app: 'openregister', fragment: $this->castFile());
		$this->app(app: 'alpha', fragment: ['objects' => [
			['key' => 'besluit-1', 'register' => 'besluiten', 'schema' => 'decision', 'owner' => $owner, 'data' => ['title' => 'Besluit op Woo-verzoek']],
		]]);
	}//end decisionOwnedBy()

	/**
	 * The object is created as the cast account it names.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/example-set-object-owner/specs/example-sets/spec.md#requirement-a-declared-object-may-name-its-owner-from-the-cast
	 */
	public function testTheObjectIsCreatedAsTheNamedCastAccount(): void {
		$this->decisionOwnedBy(owner: '{{cast:esra.uid}}');
		$plan = $this->catalogue->gather(set: 'demo');

		$this->assertSame([], $this->installer->check(plan: $plan, today: $this->today())['refusals']);
		$report = $this->installer->install(plan: $plan, today: $this->today());

		$this->assertTrue($report['ok'], (string) json_encode($report));
		$id = $this->store->ids(register: 'besluiten', schema: 'decision')[0];
		$this->assertSame('esra.demir', $this->store->owners[$id]);
	}//end testTheObjectIsCreatedAsTheNamedCastAccount()

	/**
	 * A refresh hands a kept object whose owner differs to the declared owner; an object already owned so is left.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/example-set-object-owner/specs/example-sets/spec.md#requirement-a-declared-object-may-name-its-owner-from-the-cast
	 */
	public function testARefreshHandsAKeptObjectToTheDeclaredOwner(): void {
		$this->decisionOwnedBy(owner: '{{cast:esra.uid}}');
		$plan = $this->catalogue->gather(set: 'demo');
		$this->installer->install(plan: $plan, today: $this->today());
		$id = $this->store->ids(register: 'besluiten', schema: 'decision')[0];
		$this->store->owners[$id] = '__system__';

		$report = $this->installer->install(plan: ($plan + ['refresh' => true]), today: $this->today());

		$this->assertTrue($report['ok'], (string) json_encode($report));
		$this->assertSame('esra.demir', $this->store->owners[$id]);
		$this->assertSame(1, $this->store->ownerChanges);

		$this->installer->install(plan: ($plan + ['refresh' => true]), today: $this->today());
		$this->assertSame(1, $this->store->ownerChanges, 'an object the owner already holds is not handed over again');
	}//end testARefreshHandsAKeptObjectToTheDeclaredOwner()

	/**
	 * An owner that is no account of the cast, or a placeholder that does not resolve, refuses the set.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/example-set-object-owner/specs/example-sets/spec.md#requirement-a-declared-object-may-name-its-owner-from-the-cast
	 */
	public function testAnOwnerOutsideTheCastIsRefused(): void {
		foreach (['admin', '{{cast:nobody.uid}}', '{{cast:shop.name}}'] as $owner) {
			$this->decisionOwnedBy(owner: $owner);
			$refusals = $this->installer->check(plan: $this->catalogue->gather(set: 'demo'), today: $this->today())['refusals'];
			$this->assertCount(1, $refusals, $owner);
			$this->assertStringContainsString('owner', $refusals[0], $owner);
		}

		$this->assertSame([], $this->store->ids(register: 'besluiten', schema: 'decision'));
	}//end testAnOwnerOutsideTheCastIsRefused()
}//end class
