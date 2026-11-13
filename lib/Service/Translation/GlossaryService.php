<?php

/**
 * OpenRegister GlossaryService
 *
 * Finds the glossary terms that apply to a text and the style guide of a
 * target language, from the translation-glossary register (design D-3, D-4).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Translation
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/ai-translation-with-a-glossary/specs
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Translation;

use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Reads the glossary once per language pair and matches it in memory.
 *
 * @spec openspec/changes/ai-translation-with-a-glossary/specs
 */
class GlossaryService {

	/**
	 * The register the glossary lives in.
	 *
	 * @var string
	 */
	public const REGISTER = 'translation-glossary';

	/**
	 * The schema of one term.
	 *
	 * @var string
	 */
	public const TERM_SCHEMA = 'translation-term';

	/**
	 * The schema of one style guide.
	 *
	 * @var string
	 */
	public const STYLE_GUIDE_SCHEMA = 'translation-style-guide';

	/**
	 * The most entries one text is given.
	 *
	 * @var int
	 */
	public const MAX_ENTRIES = 50;

	/**
	 * The terms read so far, by `from|to`.
	 *
	 * @var array<string, list<array{term: string, translation: string, doNotTranslate: bool, caseSensitive: bool, register: string}>>
	 */
	private array $termsByPair = [];

	/**
	 * The style guides read so far, by language; null when there is none.
	 *
	 * @var array<string, array{formality: string, instructions: string}|null>
	 */
	private array $styleGuides = [];

	/**
	 * Constructor.
	 *
	 * @param ObjectOperationsInterface $objects Reads the glossary register.
	 * @param LoggerInterface           $logger  The logger.
	 */
	public function __construct(
		private readonly ObjectOperationsInterface $objects,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The glossary entries that occur in a text: the pair's global terms and
	 * the register's own, matched on word boundaries, case-folded unless a
	 * term is case sensitive, longest term first, at most 50.
	 *
	 * @param string       $text     The source text.
	 * @param LanguagePair $pair     The language pair.
	 * @param string|null  $register The register the text belongs to, if any.
	 *
	 * @return list<array{term: string, translation: string, doNotTranslate: bool, caseSensitive: bool, register: string}>
	 *
	 * @spec openspec/changes/ai-translation-with-a-glossary/specs
	 */
	public function entriesFor(string $text, LanguagePair $pair, ?string $register=null): array {
		if (trim($text) === '') {
			return [];
		}

		$matched = [];
		foreach ($this->termsFor(pair: $pair) as $entry) {
			if ($entry['register'] !== '' && $entry['register'] !== (string) $register) {
				continue;
			}

			if (self::occurs(needle: $entry['term'], haystack: $text, caseSensitive: $entry['caseSensitive']) === true) {
				$matched[] = $entry;
			}
		}

		usort(
			$matched,
			static fn (array $a, array $b): int => [mb_strlen($b['term']), $a['term']] <=> [mb_strlen($a['term']), $b['term']]
		);

		return array_slice($matched, 0, self::MAX_ENTRIES);
	}//end entriesFor()

	/**
	 * The style guide of a target language, or null when it has none.
	 *
	 * @param string $language The BCP 47 target language.
	 *
	 * @return array{formality: string, instructions: string}|null
	 *
	 * @spec openspec/changes/ai-translation-with-a-glossary/specs
	 */
	public function styleGuideFor(string $language): ?array {
		if (array_key_exists($language, $this->styleGuides) === true) {
			return $this->styleGuides[$language];
		}

		$guide = null;
		foreach ($this->rows(schema: self::STYLE_GUIDE_SCHEMA, filters: ['language' => $language]) as $data) {
			if ((string) ($data['language'] ?? '') !== $language) {
				continue;
			}

			$guide = [
				'formality'    => (string) ($data['formality'] ?? ''),
				'instructions' => (string) ($data['instructions'] ?? ''),
			];
			break;
		}

		$this->styleGuides[$language] = $guide;
		return $guide;
	}//end styleGuideFor()

	/**
	 * Whether a glossary text occurs in a translation, compared with Unicode
	 * case folding.
	 *
	 * @param string $needle   The expected text.
	 * @param string $haystack The translation.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/ai-translation-with-a-glossary/specs
	 */
	public static function contains(string $needle, string $haystack): bool {
		return str_contains(mb_convert_case($haystack, MB_CASE_FOLD), mb_convert_case($needle, MB_CASE_FOLD));
	}//end contains()

	/**
	 * The pair's terms, read once.
	 *
	 * @param LanguagePair $pair The language pair.
	 *
	 * @return list<array{term: string, translation: string, doNotTranslate: bool, caseSensitive: bool, register: string}>
	 */
	private function termsFor(LanguagePair $pair): array {
		$key = $pair->fromLong.'|'.$pair->toLong;
		if (isset($this->termsByPair[$key]) === true) {
			return $this->termsByPair[$key];
		}

		$terms = [];
		$rows  = $this->rows(
			schema: self::TERM_SCHEMA,
			filters: ['sourceLanguage' => $pair->fromLong, 'targetLanguage' => $pair->toLong]
		);
		foreach ($rows as $data) {
			$entry = self::entry(data: $data, pair: $pair);
			if ($entry !== null) {
				$terms[] = $entry;
			}
		}

		$this->termsByPair[$key] = $terms;
		return $terms;
	}//end termsFor()

	/**
	 * One stored term as an entry, or null when it is not for this pair or
	 * has nothing to apply.
	 *
	 * @param array<string, mixed> $data The stored term.
	 * @param LanguagePair         $pair The language pair.
	 *
	 * @return array{term: string, translation: string, doNotTranslate: bool, caseSensitive: bool, register: string}|null
	 */
	private static function entry(array $data, LanguagePair $pair): ?array {
		$term           = trim((string) ($data['term'] ?? ''));
		$translation    = trim((string) ($data['translation'] ?? ''));
		$doNotTranslate = (($data['doNotTranslate'] ?? false) === true);
		if ($term === '' || ($translation === '' && $doNotTranslate === false)) {
			return null;
		}

		if ((string) ($data['sourceLanguage'] ?? '') !== $pair->fromLong || (string) ($data['targetLanguage'] ?? '') !== $pair->toLong) {
			return null;
		}

		return [
			'term'           => $term,
			'translation'    => $translation,
			'doNotTranslate' => $doNotTranslate,
			'caseSensitive'  => (($data['caseSensitive'] ?? false) === true),
			'register'       => trim((string) ($data['register'] ?? '')),
		];
	}//end entry()

	/**
	 * Whether a term occurs in a text as a whole word or phrase.
	 *
	 * @param string $needle        The term.
	 * @param string $haystack      The text.
	 * @param bool   $caseSensitive Whether case must match.
	 *
	 * @return bool
	 */
	private static function occurs(string $needle, string $haystack, bool $caseSensitive): bool {
		$flags = 'u';
		if ($caseSensitive === false) {
			$flags = 'iu';
		}

		$pattern = '/(?<![\p{L}\p{N}])'.preg_quote($needle, '/').'(?![\p{L}\p{N}])/'.$flags;

		return preg_match($pattern, $haystack) === 1;
	}//end occurs()

	/**
	 * The data of the glossary rows of one schema, or none when the register
	 * cannot be read: a translation then runs without a glossary.
	 *
	 * @param string               $schema  The schema slug.
	 * @param array<string, mixed> $filters The property filters.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function rows(string $schema, array $filters): array {
		try {
			$rows = $this->objects->searchObjectsBySlug(
				registerSlug: self::REGISTER,
				schemaSlug: $schema,
				filters: $filters
			);
		} catch (Throwable $failure) {
			$this->logger->warning(
				'[GlossaryService] The translation glossary could not be read',
				['schema' => $schema, 'exception' => $failure->getMessage()]
			);
			return [];
		}

		$data = [];
		foreach ((array) $rows as $row) {
			if (is_object($row) === true && method_exists($row, 'getObject') === true) {
				$row = $row->getObject();
			}

			if (is_array($row) === true) {
				$data[] = $row;
			}
		}

		return $data;
	}//end rows()
}//end class
