<?php

/**
 * OpenRegister RedactionRegionCutter
 *
 * Cuts the regions between two markers out of a lower-cased file.
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
 * Marker-delimited regions, shared by the route readers.
 *
 * @spec openspec/changes/redaction-release-safeguards/specs/redaction-output-guarantee/spec.md#requirement-the-written-copy-cannot-be-read-back-req-rrs-001-carried-from-filinq-req-rwb-01
 */
trait RedactionRegionCutter {

	/**
	 * Everything between two markers, every time they occur.
	 *
	 * An opening marker without a closing one runs to the end of the file:
	 * a false positive costs somebody a second look, a false negative
	 * publishes a name.
	 *
	 * @param string $haystack The lower-cased file.
	 * @param string $start    The opening marker.
	 * @param string $end      The closing marker.
	 *
	 * @return string The concatenated regions, or an empty string.
	 *
	 * @spec openspec/changes/redaction-release-safeguards/tasks.md#task-1.1
	 */
	private function between(string $haystack, string $start, string $end): string {
		$out    = '';
		$offset = 0;
		while (true) {
			$from = strpos($haystack, $start, $offset);
			if ($from === false) {
				break;
			}

			$to = strpos($haystack, $end, ($from + strlen($start)));
			if ($to === false) {
				$out .= substr($haystack, $from);
				break;
			}

			$out   .= substr($haystack, $from, (($to - $from) + strlen($end))) . "\n";
			$offset = ($to + strlen($end));
		}//end while

		return $out;
	}//end between()

	/**
	 * A region with its XML markup also read as text, so a value split over
	 * several runs (`<w:t>Jan</w:t><w:t> Jansen</w:t>`) is still found.
	 *
	 * @param string $region The lower-cased region.
	 *
	 * @return string The region followed by its text.
	 *
	 * @spec openspec/changes/redaction-release-safeguards/tasks.md#task-1.1
	 */
	private function withText(string $region): string {
		if ($region === '' || str_contains($region, '<') === false) {
			return $region;
		}

		return $region . "\n" . html_entity_decode(strip_tags($region), (ENT_QUOTES | ENT_XML1), 'UTF-8');
	}//end withText()
}//end class
