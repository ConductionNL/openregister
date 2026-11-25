<?php

/**
 * OpenRegister RedactionIrreversibilityVerifier
 *
 * Verifies that a written anonymised copy cannot be read back (REQ-RRS-001).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Anonymisation
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/redaction-release-safeguards/tasks.md#task-1.1
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Anonymisation;


/**
 * Checks the bytes actually written for every way a redacted value survives.
 *
 * Carried over from filinq's verifier (REQ-RWB-01) with its seven routes and
 * three verdicts, plus readers for the package formats the engine writes.
 *
 * - An unchecked route is a failure, not a pass: `clean` needs every route
 *   in {@see RedactionLeakRoute::ALL} examined.
 * - An unreadable file is not a clean file: bytes the mode's reader cannot
 *   parse, an empty file, an unknown output mode or an empty list of values
 *   give `unverifiable`.
 * - A finding names the route and how many values were found on it, never a
 *   value, so a result can be logged and returned as it is.
 *
 * @spec openspec/changes/redaction-release-safeguards/specs/redaction-output-guarantee/spec.md#requirement-the-written-copy-cannot-be-read-back-req-rrs-001-carried-from-filinq-req-rwb-01
 */
class RedactionIrreversibilityVerifier {

	/**
	 * Every route was examined and nothing was found.
	 */
	public const CLEAN = 'clean';

	/**
	 * At least one redacted value is still recoverable.
	 */
	public const LEAKING = 'leaking';

	/**
	 * The bytes could not be examined, so nothing is known.
	 */
	public const UNVERIFIABLE = 'unverifiable';

	/**
	 * Values shorter than this are not searched: they match almost any file,
	 * and a verification that always fails is switched off within a week.
	 */
	private const MIN_VALUE_LENGTH = 3;

	/**
	 * Verify one written copy against every leak route.
	 *
	 * @param string       $bytes          The produced file, exactly as written.
	 * @param list<string> $redactedValues The values that must not be recoverable.
	 * @param string       $outputMode     The mode that produced it ({@see RedactionOutputMode}).
	 *
	 * @return array<string, mixed> `verdict`, `outputMode`, `routesChecked`, `findings` (route, description, count),
	 *                              `mayBePublished`, and `reason` when unverifiable.
	 *
	 * @spec openspec/changes/redaction-release-safeguards/tasks.md#task-1.1
	 */
	public function verify(string $bytes, array $redactedValues, string $outputMode): array {
		$needles = $this->needles(values: $redactedValues);
		if (trim($bytes) === '') {
			return $this->unverifiable(outputMode: $outputMode, why: 'the written copy is empty, so nothing could be examined');
		}

		if ($needles === []) {
			// Verifying against nothing would pass every document, including
			// one where the redaction never ran.
			return $this->unverifiable(outputMode: $outputMode, why: 'no redacted values were supplied, so there was nothing to look for');
		}

		$regions = $this->regionsFor(bytes: $bytes, outputMode: $outputMode);
		if ($regions === null) {
			$readAs = $outputMode;
			if ($readAs === '') {
				$readAs = 'any known output mode';
			}

			return $this->unverifiable(outputMode: $outputMode, why: sprintf('the written copy could not be read as %s', $readAs));
		}

		return $this->scan(regions: $regions, needles: $needles, outputMode: $outputMode);
	}//end verify()

	/**
	 * Whether a verification result permits publication.
	 *
	 * Callers ask this rather than compare the verdict, because the two
	 * states that must not publish are `leaking` and `unverifiable`.
	 *
	 * @param array<string, mixed> $result The verification result.
	 *
	 * @return bool True only when the copy was examined and found clean.
	 *
	 * @spec openspec/changes/redaction-release-safeguards/tasks.md#task-1.1
	 */
	public function mayBePublished(array $result): bool {
		return (($result['verdict'] ?? '') === self::CLEAN && ($result['mayBePublished'] ?? false) === true);
	}//end mayBePublished()

	/**
	 * The regions of the copy, read by its mode's reader.
	 *
	 * @param string $bytes      The produced file.
	 * @param string $outputMode The mode.
	 *
	 * @return array<string, string>|null The regions, or null when unreadable or the mode is unknown.
	 */
	private function regionsFor(string $bytes, string $outputMode): ?array {
		$reader = (RedactionOutputMode::READERS[$outputMode] ?? null);
		if ($reader === RedactionOutputMode::READER_PDF) {
			return (new RedactionPdfRouteReader())->regions(bytes: $bytes);
		}

		if ($reader === RedactionOutputMode::READER_PACKAGE) {
			return (new RedactionPackageRouteReader())->regions(bytes: $bytes);
		}

		if ($reader === RedactionOutputMode::READER_TEXT) {
			// Plain text has no structure to hide anything in: every other
			// route is examined and empty.
			$regions = array_fill_keys(RedactionLeakRoute::ALL, '');
			$regions[RedactionLeakRoute::TEXT_UNDER_MARK] = strtolower($bytes);
			return $regions;
		}

		return null;
	}//end regionsFor()

	/**
	 * Examine every route, in order, and give the verdict.
	 *
	 * @param array<string, string>       $regions    The region of each route.
	 * @param array<string, list<string>> $needles    Each value's search forms.
	 * @param string                      $outputMode The mode.
	 *
	 * @return array<string, mixed> The result.
	 */
	private function scan(array $regions, array $needles, string $outputMode): array {
		$checked  = [];
		$findings = [];
		foreach (RedactionLeakRoute::ALL as $route) {
			if (array_key_exists($route, $regions) === false) {
				continue;
			}

			$checked[] = $route;
			$count     = $this->countFound(haystack: $regions[$route], needles: $needles);
			if ($count > 0) {
				$findings[] = ['route' => $route, 'description' => RedactionLeakRoute::DESCRIPTIONS[$route], 'count' => $count];
			}
		}

		$unchecked = array_values(array_diff(RedactionLeakRoute::ALL, $checked));
		if ($findings === [] && $unchecked !== []) {
			$result = $this->unverifiable(outputMode: $outputMode, why: 'not every route could be examined: ' . implode(', ', $unchecked));
			$result['routesChecked'] = $checked;
			return $result;
		}

		$clean   = ($findings === []);
		$verdict = self::LEAKING;
		if ($clean === true) {
			$verdict = self::CLEAN;
		}

		return [
			'verdict' => $verdict,
			'outputMode' => $outputMode,
			'routesChecked' => $checked,
			'findings' => $findings,
			'mayBePublished' => $clean,
		];
	}//end scan()

	/**
	 * How many of the values appear in a region.
	 *
	 * @param string                      $haystack The lower-cased region.
	 * @param array<string, list<string>> $needles  Each value's search forms.
	 *
	 * @return int The number of values found.
	 */
	private function countFound(string $haystack, array $needles): int {
		if ($haystack === '') {
			return 0;
		}

		$count = 0;
		foreach ($needles as $forms) {
			foreach ($forms as $form) {
				if (str_contains($haystack, $form) === true) {
					$count++;
					break;
				}
			}
		}

		return $count;
	}//end countFound()

	/**
	 * The values to look for: lower-cased, with their XML-escaped form, and
	 * without the trivially short.
	 *
	 * @param array<array-key, mixed> $values The redacted values.
	 *
	 * @return array<string, list<string>> Each value's search forms.
	 */
	private function needles(array $values): array {
		$needles = [];
		foreach ($values as $value) {
			if (is_scalar($value) === false) {
				continue;
			}

			$text = strtolower(trim((string) $value));
			if (strlen($text) < self::MIN_VALUE_LENGTH) {
				continue;
			}

			$needles[$text] = array_values(array_unique([$text, htmlspecialchars($text, (ENT_QUOTES | ENT_XML1), 'UTF-8')]));
		}

		return $needles;
	}//end needles()

	/**
	 * A result that says nothing is known, and why.
	 *
	 * @param string $outputMode The mode.
	 * @param string $why        The reason, in words.
	 *
	 * @return array<string, mixed> The result.
	 */
	private function unverifiable(string $outputMode, string $why): array {
		return [
			'verdict' => self::UNVERIFIABLE,
			'outputMode' => $outputMode,
			'routesChecked' => [],
			'findings' => [],
			'mayBePublished' => false,
			'reason' => $why,
		];
	}//end unverifiable()
}//end class
