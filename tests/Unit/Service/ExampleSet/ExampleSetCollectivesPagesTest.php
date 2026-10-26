<?php

/**
 * Tests for the example set's Collectives pages.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/changes/example-set-collectives-pages/specs/example-sets/spec.md#requirement-a-fragment-may-write-collectives-pages
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ExampleSet;

/**
 * A fragment writes a work-instruction page in a collective its team can read, and remove takes away what it wrote.
 */
class ExampleSetCollectivesPagesTest extends ExampleSetTestCase {

	/**
	 * Set up the cast.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->app(app: 'openregister', fragment: $this->castFile());
	}//end setUp()

	/**
	 * A work-instruction page.
	 *
	 * @param array<string, mixed> $extra Fields to add or replace.
	 *
	 * @return array<string, mixed>
	 */
	private static function page(array $extra = []): array {
		return [...[
			'collective' => 'Woo en informatie',
			'title'      => 'Hoe we Woo-verzoeken behandelen',
			'content'    => "# Hoe we Woo-verzoeken behandelen\n\nBinnen een week bevestigen we de ontvangst.",
			'owner'      => '{{cast:esra.uid}}',
			'shareWith'  => ['demo-staff'],
		], ...$extra];
	}//end page()

	/**
	 * The collective is created with the team's group, the page with its content; a second install keeps both and
	 * remove deletes them.
	 *
	 * @return void
	 */
	public function testAPageIsWrittenOnceAndTakenAwayWithItsCollective(): void {
		$this->app(app: 'alpha', fragment: ['collectivesPages' => [['key' => 'woo-werkinstructie', 'data' => self::page()]]]);
		$plan = $this->catalogue->gather(set: 'demo');

		$this->assertSame([], $this->installer->check(plan: $plan, today: $this->today())['refusals']);
		$this->assertSame([], $this->collectives['pages'], 'the check writes no page');

		$report = $this->installer->install(plan: $plan, today: $this->today());
		$this->assertTrue($report['ok'], (string) json_encode($report));
		$this->assertSame(['name' => 'Woo en informatie', 'owner' => 'esra.demir', 'groups' => ['demo-staff']], array_values($this->collectives['collectives'])[0]);
		$page = array_values($this->collectives['pages'])[0];
		$this->assertSame('Hoe we Woo-verzoeken behandelen', $page['title']);
		$this->assertStringContainsString('Binnen een week', $page['content']);

		$this->installer->install(plan: $plan, today: $this->today());
		$this->assertCount(1, $this->collectives['pages'], 'a kept page is written once');

		$removed = $this->remover->remove(set: 'demo');
		$this->assertSame([], $removed['failed']);
		$this->assertSame([], $this->collectives['collectives']);
		$this->assertSame([], $this->collectives['pages']);
	}//end testAPageIsWrittenOnceAndTakenAwayWithItsCollective()

	/**
	 * A collective that already exists is used, and remove leaves it and its own pages.
	 *
	 * @return void
	 */
	public function testAnExistingCollectiveIsUsedAndKept(): void {
		$this->collectives['collectives'][7] = ['name' => 'Woo en informatie', 'owner' => 'esra.demir', 'groups' => []];
		$this->collectives['pages'][50]      = ['collective' => 7, 'title' => 'Eigen pagina', 'content' => '', 'owner' => 'esra.demir'];
		$this->app(app: 'alpha', fragment: ['collectivesPages' => [['key' => 'woo-werkinstructie', 'data' => self::page()]]]);

		$this->installer->install(plan: $this->catalogue->gather(set: 'demo'), today: $this->today());
		$this->assertCount(2, $this->collectives['pages']);

		$this->remover->remove(set: 'demo');
		$this->assertArrayHasKey(7, $this->collectives['collectives']);
		$this->assertSame([50], array_keys($this->collectives['pages']));
	}//end testAnExistingCollectiveIsUsedAndKept()

	/**
	 * Without Collectives the install reports the page as not written.
	 *
	 * @return void
	 */
	public function testWithoutCollectivesThePageIsNotWritten(): void {
		$this->collectivesInstalled = false;
		$this->app(app: 'alpha', fragment: ['collectivesPages' => [['key' => 'woo-werkinstructie', 'data' => self::page()]]]);

		$report = $this->installer->install(plan: $this->catalogue->gather(set: 'demo'), today: $this->today());

		$this->assertFalse($report['ok']);
		$this->assertStringContainsString('Collectives is not installed', (string) json_encode($report['dropped']));
	}//end testWithoutCollectivesThePageIsNotWritten()

	/**
	 * A faulty page is refused at check time and nothing is written.
	 *
	 * @param array<string, mixed> $data   The row's fields.
	 * @param string               $reason A part of the refusal.
	 *
	 * @return void
	 *
	 * @dataProvider refusedPages
	 */
	public function testACheckRefusesAFaultyPage(array $data, string $reason): void {
		$this->app(app: 'alpha', fragment: ['collectivesPages' => [['key' => 'bad', 'data' => $data]]]);

		$refusals = $this->installer->check(plan: $this->catalogue->gather(set: 'demo'), today: $this->today())['refusals'];

		$this->assertCount(1, $refusals);
		$this->assertStringContainsString($reason, $refusals[0]);
		$this->assertSame([], $this->collectives['pages']);
	}//end testACheckRefusesAFaultyPage()

	/**
	 * The faulty pages and a part of their refusal.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: string}>
	 */
	public static function refusedPages(): array {
		return [
			'no collective'    => [self::page(['collective' => '']), 'needs a collective'],
			'no content'       => [self::page(['content' => ' ']), 'needs its content'],
			'unknown field'    => [self::page(['emoji' => 'x']), 'not a collectives page field'],
			'foreign owner'    => [self::page(['owner' => 'nobody']), 'not an account the set creates or adopts'],
			'shareWith a map'  => [self::page(['shareWith' => ['a' => 'demo-staff']]), 'shareWith is a list'],
		];
	}//end refusedPages()
}//end class
