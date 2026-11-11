<?php

/**
 * The vendored OMG schema set is the one we said it was.
 *
 * 🔴 THIS TEST IS THE WHOLE REASON THE CHECKSUMS EXIST. A vendored third-party
 * artefact is the easiest thing in a repository to edit quietly: Camunda and
 * Flowable both widened `calledElement` in their copies, and nothing in either
 * repository says so. The moment somebody relaxes a type here to make our own
 * export pass, this test names the file.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Flow\Bpmn
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-bpmn-interchange/specs/flow-bpmn-interchange/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow\Bpmn;

use OCA\OpenRegister\Service\Flow\Bpmn\BpmnSchemaValidator;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the vendored files, their sums and their recorded provenance.
 */
class BpmnSchemaProvenanceTest extends TestCase {

	/**
	 * The directory the five files sit in.
	 *
	 * @return string The path.
	 */
	private function directory(): string {
		return (new BpmnSchemaValidator())->schemaDirectory();
	}//end directory()

	/**
	 * Every vendored file hashes to the sum recorded when it was fetched.
	 *
	 * @return void
	 */
	public function testEveryVendoredSchemaMatchesItsRecordedChecksum(): void {
		foreach (BpmnSchemaValidator::CHECKSUMS as $file => $expected) {
			$path = ($this->directory() . DIRECTORY_SEPARATOR . $file);
			$this->assertFileExists($path, sprintf('%s is named in the provenance but missing from disk', $file));
			$this->assertSame(
				$expected,
				hash_file('sha256', $path),
				sprintf('%s no longer matches the sum recorded when it was fetched from omg.org', $file)
			);
		}
	}//end testEveryVendoredSchemaMatchesItsRecordedChecksum()

	/**
	 * All five sit together, because they reference each other by relative path.
	 *
	 * @return void
	 */
	public function testTheWholeSetIsVendoredAndNothingElseIs(): void {
		$found = glob($this->directory() . DIRECTORY_SEPARATOR . '*.xsd');
		$this->assertIsArray($found);

		$names = array_map('basename', $found);
		sort($names);

		$expected = array_keys(BpmnSchemaValidator::CHECKSUMS);
		sort($expected);

		$this->assertSame(
			$expected,
			$names,
			'BPMN20.xsd reaches the other four by relative schemaLocation, so a partial set validates nothing'
		);
	}//end testTheWholeSetIsVendoredAndNothingElseIs()

	/**
	 * 🔴 The files carry no notice, which is why the provenance file has to.
	 *
	 * This asserts the fact the attribution rests on. If OMG ever ships these
	 * with a header, the header is what must be preserved and this test is the
	 * thing that notices.
	 *
	 * @return void
	 */
	public function testTheSchemasCarryNoNoticeOfTheirOwn(): void {
		foreach (array_keys(BpmnSchemaValidator::CHECKSUMS) as $file) {
			$contents = (string)file_get_contents($this->directory() . DIRECTORY_SEPARATOR . $file);
			$this->assertSame(
				0,
				preg_match('/copyright|licen[cs]e/i', $contents),
				sprintf('%s now carries a notice; it must be preserved rather than left to PROVENANCE.md', $file)
			);
		}
	}//end testTheSchemasCarryNoNoticeOfTheirOwn()

	/**
	 * The provenance file records the same sums, the source and the attribution.
	 *
	 * 🔑 A provenance file that drifts from the constant is worse than none:
	 * it reads as verification while verifying nothing.
	 *
	 * @return void
	 */
	public function testTheProvenanceFileRecordsTheSameSumsAndTheAttribution(): void {
		$provenance = (string)file_get_contents($this->directory() . DIRECTORY_SEPARATOR . 'PROVENANCE.md');

		foreach (BpmnSchemaValidator::CHECKSUMS as $file => $expected) {
			$this->assertStringContainsString($file, $provenance);
			$this->assertStringContainsString($expected, $provenance, sprintf('PROVENANCE.md has a stale sum for %s', $file));
		}

		$this->assertStringContainsString('https://www.omg.org/spec/BPMN/20100501/', $provenance, 'the source URL is the pin');
		$this->assertStringContainsString('2026-09-19', $provenance, 'the fetch date is the pin');
		$this->assertStringContainsString(BpmnSchemaValidator::BPMN_VERSION, $provenance);
		$this->assertStringContainsString('Object Management Group', $provenance, 'the copyright line the files cannot carry');
		$this->assertStringContainsString('no modifications are made', $provenance, 'the licence condition we are keeping');
	}//end testTheProvenanceFileRecordsTheSameSumsAndTheAttribution()
}//end class
