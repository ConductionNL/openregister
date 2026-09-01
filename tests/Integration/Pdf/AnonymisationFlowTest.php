<?php

/**
 * PDF Anonymisation Flow — integration test (tasks.md §9.3).
 *
 * Spec REQ (pdf-anonymisation):
 *   End-to-end coverage of `PdfTextReplacer::replaceInPdf()` against checked-in
 *   PDF fixtures: simple body text, tables, multi-page, Identity-H font,
 *   multiple filter chains, residual-text detection.
 *
 * Fixture-checkin guard:
 *
 *  - The integration suite consumes fixtures from `tests/fixtures/pdf-anonymisation/`.
 *  - When the directory is empty (typical CI worktree before the upstream-tag
 *    SAPP pin transition) the test SKIPS cleanly with a documented message.
 *  - When at least one `.pdf` file is present the test exercises the full
 *    SAPP-byte-replace + smalot validation chain and asserts that:
 *      1. The pipeline produces output without raising
 *         `PdfAnonymisationException`.
 *      2. The output re-extracted via smalot contains NONE of the substitution
 *         values (anonymisation invariant).
 *      3. A `*.expected.json` sidecar (when present) lists the entities the
 *         fixture is known to carry; each MUST be absent from the output.
 *
 * The guard pattern lets the tasks.md "fixture-checkin question" be answered
 * by dropping the fixture into the repo: the guard auto-promotes the deferred
 * test to a live assertion the next CI run picks up. No code change required.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Integration\Pdf
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/pdf-anonymisation/specs/pdf-anonymisation/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Integration\Pdf;

use OCA\OpenRegister\Exception\PdfAnonymisationException;
use OCA\OpenRegister\Service\File\Pdf\PdfTextReplacer;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * PDF anonymisation flow tests against checked-in fixtures.
 *
 * The fixtures directory `tests/fixtures/pdf-anonymisation/` holds one or more
 * `.pdf` files. Each may carry a `*.expected.json` sidecar with the shape:
 *
 * ```json
 * {
 *   "substitutions": {"Jan Jansen": "[PERSON: 1]"},
 *   "must_be_absent": ["Jan Jansen", "Janssen"]
 * }
 * ```
 *
 * @group integration
 * @group pdf-anonymisation
 */
class AnonymisationFlowTest extends TestCase {

	/**
	 * Directory holding pinned anonymisation fixtures.
	 *
	 * @var string
	 */
	private const FIXTURE_DIR = __DIR__ . '/../../fixtures/pdf-anonymisation';

	/**
	 * Skip the suite cleanly when no fixture is checked in yet.
	 *
	 * Per tasks.md §9.3: "the integration suite currently requires a fixture
	 * file checked into the repo." The guard makes the test self-promoting:
	 * once a fixture lands, the next CI run picks it up.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		if (class_exists(PdfTextReplacer::class) === false) {
			$this->markTestSkipped('PdfTextReplacer class is not autoloaded; skip until composer install runs.');
		}

		if (class_exists(\Smalot\PdfParser\Parser::class) === false) {
			$this->markTestSkipped('smalot/pdfparser is not installed; skip integration test (composer install pending).');
		}

		if (class_exists(\Ddn\Sapp\PDFDoc::class) === false && class_exists('Ddn\\Sapp\\PDFDoc') === false) {
			$this->markTestSkipped('ddn/sapp is not installed; skip integration test (composer install pending).');
		}

		if (is_dir(self::FIXTURE_DIR) === false) {
			$this->markTestSkipped(
				'No PDF anonymisation fixtures checked in at ' . self::FIXTURE_DIR . '. '
				. 'See tasks.md §9.3 fixture-checkin question. Drop a *.pdf + matching '
				. '*.expected.json into the directory to enable the integration assertion.'
			);
		}

		$pdfs = $this->collectFixtures();
		if ($pdfs === []) {
			$this->markTestSkipped(
				'PDF anonymisation fixture directory is empty. Drop a *.pdf + '
				. 'matching *.expected.json into ' . self::FIXTURE_DIR . ' to enable the assertion.'
			);
		}
	}//end setUp()

	/**
	 * For each pinned fixture, run the anonymisation pipeline and assert
	 * that no substituted entity appears in the re-extracted output.
	 *
	 * @return void
	 */
	public function testAllPinnedFixturesAnonymiseCleanly(): void {
		$replacer = new PdfTextReplacer(logger: $this->createMock(LoggerInterface::class));

		foreach ($this->collectFixtures() as $pdfPath) {
			$expected = $this->loadExpected($pdfPath);
			$substitutions = $expected['substitutions'];
			$mustBeAbsent = $expected['must_be_absent'];

			$bytes = file_get_contents($pdfPath);
			$this->assertIsString($bytes, 'Fixture must be readable: ' . $pdfPath);

			try {
				$output = $replacer->replaceInPdf(pdfBytes: $bytes, substitutions: $substitutions, strict: true);
			} catch (PdfAnonymisationException $e) {
				$this->fail(sprintf(
					'Fixture %s raised PdfAnonymisationException reason=%s. '
					. 'The pinned fixture set is meant to round-trip cleanly.',
					basename($pdfPath),
					$e->getReason()
				));
			}

			$this->assertIsString($output);
			$this->assertNotEmpty($output, 'Anonymised output for ' . basename($pdfPath) . ' must not be empty');

			// smalot re-extract — assert no banned needle survives.
			$parser = new \Smalot\PdfParser\Parser();
			$document = $parser->parseContent($output);
			$extracted = $document->getText();

			foreach ($mustBeAbsent as $needle) {
				$this->assertFalse(
					mb_stripos($extracted, $needle) !== false,
					sprintf(
						'Fixture %s: substring %s MUST NOT survive anonymisation '
						. '(GDPR-anonymisation invariant — see spec REQ %s).',
						basename($pdfPath),
						json_encode($needle),
						'openspec/changes/pdf-anonymisation/specs/pdf-anonymisation/spec.md'
					)
				);
			}
		}//end foreach
	}//end testAllPinnedFixturesAnonymiseCleanly()

	/**
	 * Sanity test: the fixture-checkin guard itself.
	 *
	 * Asserts the directory + sidecar contract is honoured for every PDF in
	 * the directory. A fixture missing its expected.json sidecar is allowed
	 * (the main test simply skips its absence-assertions) but malformed
	 * sidecars fail loud.
	 *
	 * @return void
	 */
	public function testFixtureSidecarShapeIsValid(): void {
		foreach ($this->collectFixtures() as $pdfPath) {
			$sidecar = preg_replace('/\.pdf$/i', '.expected.json', $pdfPath);
			if ($sidecar === null || is_file($sidecar) === false) {
				// No sidecar -> assertion-free run; nothing to validate.
				continue;
			}

			$decoded = json_decode((string)file_get_contents($sidecar), true);
			$this->assertIsArray($decoded, sprintf('Sidecar %s must be a JSON object', basename($sidecar)));
			$this->assertArrayHasKey('substitutions', $decoded, 'Sidecar must declare substitutions');
			$this->assertArrayHasKey('must_be_absent', $decoded, 'Sidecar must declare must_be_absent');
			$this->assertIsArray($decoded['substitutions']);
			$this->assertIsArray($decoded['must_be_absent']);
		}

		// Reach assertion-count threshold so PHPUnit does not flag risky.
		$this->assertTrue(true);
	}//end testFixtureSidecarShapeIsValid()

	/**
	 * Enumerate `.pdf` files under the fixture directory.
	 *
	 * @return string[] Absolute paths to checked-in fixtures.
	 */
	private function collectFixtures(): array {
		if (is_dir(self::FIXTURE_DIR) === false) {
			return [];
		}

		$iterator = new \FilesystemIterator(self::FIXTURE_DIR, \FilesystemIterator::SKIP_DOTS);
		$pdfs = [];
		foreach ($iterator as $entry) {
			/** @var \SplFileInfo $entry */
			if ($entry->isFile() === false) {
				continue;
			}

			if (strtolower($entry->getExtension()) !== 'pdf') {
				continue;
			}

			$pdfs[] = $entry->getPathname();
		}

		sort($pdfs);
		return $pdfs;
	}//end collectFixtures()

	/**
	 * Load the sidecar for a fixture, applying defaults when absent.
	 *
	 * @param string $pdfPath Path to the fixture.
	 *
	 * @return array{substitutions: array<string, string>, must_be_absent: list<string>}
	 */
	private function loadExpected(string $pdfPath): array {
		$defaults = ['substitutions' => [], 'must_be_absent' => []];

		$sidecar = preg_replace('/\.pdf$/i', '.expected.json', $pdfPath);
		if ($sidecar === null || is_file($sidecar) === false) {
			return $defaults;
		}

		$decoded = json_decode((string)file_get_contents($sidecar), true);
		if (is_array($decoded) === false) {
			return $defaults;
		}

		return [
			'substitutions' => is_array($decoded['substitutions'] ?? null) ? $decoded['substitutions'] : [],
			'must_be_absent' => is_array($decoded['must_be_absent'] ?? null) ? array_values($decoded['must_be_absent']) : [],
		];
	}//end loadExpected()
}//end class
