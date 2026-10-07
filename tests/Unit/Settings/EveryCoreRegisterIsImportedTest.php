<?php

/**
 * A register descriptor nobody imports is a data model that exists only in the
 * source tree.
 *
 * This is a gate, not a unit test. It exists because the failure it catches
 * reports SUCCESS: `occ upgrade` finishes cleanly, every check is green, and
 * the register is simply not there. The only evidence is
 * `occ openregister:descriptors:list` saying ABSENT, or two unrelated e2e
 * suites dying on a register slug nobody can find. The comment beside
 * `ImportFlowRegister` in `appinfo/info.xml` records the first time this
 * happened: eight of fifteen declared registers missing.
 *
 * It happened again with `survey_register.json`, which shipped with no step
 * naming it. This test is the answer to "how would we know next time".
 *
 * The rule is mechanical: every descriptor whose `x-openregister.type` is not
 * `mock` must be named by a repair step that `appinfo/info.xml` runs. A `mock`
 * descriptor is deliberately excluded from the inventory, so it needs none.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/survey-object/specs/survey-object/spec.md#requirement-req-surv-001-a-survey-is-its-own-object-with-its-own-questions
 */

declare(strict_types=1);

namespace Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
class EveryCoreRegisterIsImportedTest extends TestCase {

	/**
	 * The repo root.
	 *
	 * @var string
	 */
	private string $root;

	/**
	 * The repair-step class shortnames `appinfo/info.xml` actually runs.
	 *
	 * @var array<int,string>
	 */
	private array $steps;

	/**
	 * Read info.xml once per test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->root = dirname(__DIR__, 3);

		$info = (string) file_get_contents($this->root.'/appinfo/info.xml');
		preg_match_all('/Repair\\\\(\w+)<\/step>/', $info, $matches);
		$this->steps = array_values(array_unique($matches[1]));

	}//end setUp()

	/**
	 * Every register descriptor under lib/Settings, with its type and slugs.
	 *
	 * A descriptor is recognised BY SHAPE, not by filename: an OpenAPI
	 * document carrying `components.registers`. That is how
	 * RegisterDescriptorService finds them, so a test that matched on
	 * `*_register.json` would miss exactly the file somebody named
	 * differently.
	 *
	 * @return array<string,array<string,mixed>> Descriptors by basename.
	 */
	private function descriptors(): array {
		$found = [];
		foreach ((array) glob($this->root.'/lib/Settings/*.json') as $path) {
			$decoded = json_decode((string) file_get_contents($path), true);
			if (is_array($decoded) === false) {
				continue;
			}

			$registers = ($decoded['components']['registers'] ?? null);
			if (is_array($registers) === false || $registers === []) {
				continue;
			}

			$found[basename($path)] = [
				'type'      => (string) ($decoded['x-openregister']['type'] ?? ''),
				'registers' => array_keys($registers),
			];
		}

		return $found;

	}//end descriptors()

	/**
	 * Which repair step, if any, actually IMPORTS a descriptor file.
	 *
	 * Matched against the path the step imports, not against the file's text.
	 * A plain substring search over the source passes on a docblock that
	 * merely mentions the descriptor, which is how a step pointed at the wrong
	 * file would still look correct: the sentence explaining what it does
	 * outlives the constant that does it.
	 *
	 * Found by mutation: repointing this step's REGISTER_PATH at
	 * flow_register.json left the whole gate green, because the class comment
	 * still said "survey_register.json".
	 *
	 * @param string $basename The descriptor's file name.
	 *
	 * @return string|null The step's shortname, or null.
	 */
	private function stepFor(string $basename): ?string {
		foreach ($this->steps as $step) {
			$path = $this->root.'/lib/Repair/'.$step.'.php';
			if (is_file($path) === false) {
				continue;
			}

			$source = (string) file_get_contents($path);
			foreach ($this->importedPaths($source) as $imported) {
				if (basename($imported) === $basename) {
					return $step;
				}
			}
		}

		return null;

	}//end stepFor()

	/**
	 * The descriptor paths a repair step's CODE names, ignoring its comments.
	 *
	 * @param string $source The step's source.
	 *
	 * @return array<int,string> The paths.
	 */
	private function importedPaths(string $source): array {
		$withoutComments = (string) preg_replace('!/\*.*?\*/!s', '', $source);
		$withoutComments = (string) preg_replace('!//[^\n]*!', '', $withoutComments);

		preg_match_all("!'([^']*/lib/Settings/[^']+\.json)'!", $withoutComments, $matches);

		return $matches[1];

	}//end importedPaths()

	public function testTheTestItselfFoundSomethingToCheck(): void {
		// The control. A glob that matched nothing, or an info.xml regex that
		// matched nothing, would make every assertion below pass vacuously.
		$this->assertGreaterThan(10, count($this->descriptors()), 'no register descriptors were found at all');
		$this->assertGreaterThan(10, count($this->steps), 'no repair steps were found in info.xml at all');

	}//end testTheTestItselfFoundSomethingToCheck()

	public function testEveryCoreDescriptorIsImportedByAStepInfoXmlRuns(): void {
		$orphans = [];
		foreach ($this->descriptors() as $basename => $descriptor) {
			if ($descriptor['type'] === 'mock') {
				continue;
			}

			if ($this->stepFor($basename) === null) {
				$orphans[] = $basename.' ('.implode(', ', $descriptor['registers']).')';
			}
		}

		$this->assertSame(
			[],
			$orphans,
			"These register descriptors are never imported, so their registers do not exist on any instance.\n"
			."Add a repair step under lib/Repair/ that names the file, and name the step in appinfo/info.xml.\n"
			.'Orphans: '.implode('; ', $orphans)
		);

	}//end testEveryCoreDescriptorIsImportedByAStepInfoXmlRuns()

	public function testTheSurveyRegisterIsImported(): void {
		// Named on its own, because it is the one that was missing, and a
		// regression here should say "the survey register" rather than
		// "an array is not empty".
		$this->assertNotNull(
			$this->stepFor('survey_register.json'),
			'survey_register.json ships four schemas that no repair step imports'
		);

	}//end testTheSurveyRegisterIsImported()

	public function testAMockDescriptorNeedsNoStep(): void {
		// The other half of the rule, so a future reader does not "fix" the
		// mock descriptors by writing steps for registers that are meant to
		// stay out of the inventory.
		$mocks = array_filter(
			$this->descriptors(),
			static fn (array $descriptor): bool => $descriptor['type'] === 'mock'
		);

		$this->assertNotEmpty($mocks, 'the mock exemption is only meaningful if mock descriptors exist');

	}//end testAMockDescriptorNeedsNoStep()
	/*
	 * NOT ASSERTED HERE, deliberately: whether a step's REGISTER_VERSION
	 * matches its descriptor's register version.
	 *
	 * Two steps already disagree with their descriptors on this branch:
	 * ImportCredentialBrokerRegister pins 1.0.0 against a 1.4.0 register, and
	 * ImportFlowRegister pins 1.4.0 against a 1.3.0 register (1.4.0 is that
	 * descriptor's SCHEMA version, not its register's). Both predate this
	 * change. The importer's version_compare gate is what decides whether an
	 * upgrade re-applies a descriptor, so a mismatch is worth knowing about,
	 * but which of the two numbers it compares is a question this test cannot
	 * answer by reading files.
	 *
	 * Asserting it here would fail the build on inherited debt and teach
	 * everybody to skip the gate. Reported instead, and left for whoever owns
	 * the importer.
	 */


}//end class
