<?php

/**
 * OpenRegister TaskProcessingTranslationProvider
 *
 * Translates through Nextcloud's Task Processing, the AI the administrator
 * already chose: plain machine translation, or a fenced prompt carrying the
 * glossary and style guide, checked afterwards (design D-1, D-2, D-5).
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

use OCP\IUserSession;
use OCP\TaskProcessing\IManager;
use OCP\TaskProcessing\Task;
use OCP\TaskProcessing\TaskTypes\TextToText;
use OCP\TaskProcessing\TaskTypes\TextToTextTranslate;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * A translation provider over Task Processing, with a checked glossary.
 *
 * @spec openspec/changes/ai-translation-with-a-glossary/specs
 */
class TaskProcessingTranslationProvider implements TranslationProviderInterface {

	/**
	 * The provider identifier.
	 *
	 * @var string
	 */
	public const IDENTIFIER = 'taskprocessing';

	/**
	 * Constructor.
	 *
	 * @param IManager        $taskProcessing Nextcloud's Task Processing.
	 * @param GlossaryService $glossary       The glossary and style guides.
	 * @param IUserSession    $userSession    The acting user the task runs as.
	 * @param LoggerInterface $logger         The logger.
	 */
	public function __construct(
		private readonly IManager $taskProcessing,
		private readonly GlossaryService $glossary,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Translate a text; null when no task type is available or the task fails.
	 *
	 * @param string       $text The source text.
	 * @param LanguagePair $pair The language pair.
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/ai-translation-with-a-glossary/specs
	 */
	public function translate(string $text, LanguagePair $pair): ?string {
		return $this->translateDetailed(text: $text, pair: $pair)?->text;
	}//end translate()

	/**
	 * The provider identifier.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/ai-translation-with-a-glossary/specs
	 */
	public function getIdentifier(): string {
		return self::IDENTIFIER;
	}//end getIdentifier()

	/**
	 * Translate a text and report the matched glossary terms the translation
	 * left out.
	 *
	 * Without matched terms and without a style guide the dedicated translate
	 * task runs; otherwise a text-to-text prompt carries them, falling back to
	 * the translate task when that type is not available.
	 *
	 * @param string       $text     The source text.
	 * @param LanguagePair $pair     The language pair.
	 * @param string|null  $register The register the text belongs to, if any.
	 *
	 * @return TranslationOutcome|null Null when no task type is available or the task gives no text.
	 *
	 * @spec openspec/changes/ai-translation-with-a-glossary/specs
	 */
	public function translateDetailed(string $text, LanguagePair $pair, ?string $register=null): ?TranslationOutcome {
		$entries   = $this->glossary->entriesFor(text: $text, pair: $pair, register: $register);
		$style     = $this->glossary->styleGuideFor(language: $pair->toLong);
		$userId    = $this->userSession->getUser()?->getUID();
		$available = $this->taskProcessing->getAvailableTaskTypeIds(userId: $userId);

		$task = null;
		if (($entries !== [] || $style !== null) && in_array(TextToText::ID, $available, true) === true) {
			$task = new Task(
				taskTypeId: TextToText::ID,
				input: ['input' => self::prompt(text: $text, pair: $pair, entries: $entries, style: $style)],
				appId: 'openregister',
				userId: $userId
			);
		} else if (in_array(TextToTextTranslate::ID, $available, true) === true) {
			$task = new Task(
				taskTypeId: TextToTextTranslate::ID,
				input: ['input' => $text, 'origin_language' => $pair->fromLong, 'target_language' => $pair->toLong],
				appId: 'openregister',
				userId: $userId
			);
		}

		if ($task === null) {
			return null;
		}

		$output = $this->run(task: $task);
		if ($output === null) {
			return null;
		}

		return new TranslationOutcome(text: $output, missingTerms: self::missingTerms(entries: $entries, output: $output));
	}//end translateDetailed()

	/**
	 * The fenced prompt: the instructions first, built from administered data
	 * only, and the record's text last between tags, so it is read as data.
	 *
	 * @param string                                               $text    The source text.
	 * @param LanguagePair                                         $pair    The language pair.
	 * @param list<array<string, mixed>>                           $entries The matched entries (GlossaryService::entriesFor()).
	 * @param array{formality: string, instructions: string}|null $style   The style guide.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/ai-translation-with-a-glossary/specs
	 */
	public static function prompt(string $text, LanguagePair $pair, array $entries, ?array $style): string {
		$lines = [sprintf('Translate the text between <text> tags from %s to %s.', $pair->fromLong, $pair->toLong)];

		$use  = [];
		$keep = [];
		foreach ($entries as $entry) {
			if ($entry['doNotTranslate'] === true) {
				$keep[] = sprintf('"%s"', $entry['term']);
				continue;
			}

			$use[] = sprintf('"%s" -> "%s"', $entry['term'], $entry['translation']);
		}

		if ($use !== []) {
			$lines[] = 'Use exactly these translations for these terms: '.implode(', ', $use).'.';
		}

		if ($keep !== []) {
			$lines[] = 'Leave these terms untranslated: '.implode(', ', $keep).'.';
		}

		if ($style !== null) {
			$lines[] = trim(sprintf('Style: %s. %s', $style['formality'], $style['instructions']));
		}

		$lines[] = 'Return only the translation, without the tags and without comments.';
		$lines[] = '<text>'.$text.'</text>';

		return implode("\n", $lines);
	}//end prompt()

	/**
	 * The matched terms whose required text is not in the translation: the
	 * translation, or the term itself when it is kept as is.
	 *
	 * @param list<array<string, mixed>> $entries The matched entries (GlossaryService::entriesFor()).
	 * @param string                     $output  The translation.
	 *
	 * @return list<string>
	 */
	private static function missingTerms(array $entries, string $output): array {
		$missing = [];
		foreach ($entries as $entry) {
			$expected = $entry['translation'];
			if ($entry['doNotTranslate'] === true) {
				$expected = $entry['term'];
			}

			if (GlossaryService::contains(needle: $expected, haystack: $output) === false) {
				$missing[] = $entry['term'];
			}
		}

		return $missing;
	}//end missingTerms()

	/**
	 * Run a task and answer its text output, or null on failure or no text.
	 *
	 * @param Task $task The task.
	 *
	 * @return string|null
	 */
	private function run(Task $task): ?string {
		try {
			$done = $this->taskProcessing->runTask($task);
		} catch (Throwable $failure) {
			$this->logger->warning(
				'[TaskProcessingTranslationProvider] The translation task failed',
				['taskType' => $task->getTaskTypeId(), 'exception' => $failure->getMessage()]
			);
			return null;
		}

		$output = trim((string) ($done->getOutput()['output'] ?? ''));
		if ($output === '') {
			return null;
		}

		return $output;
	}//end run()
}//end class
