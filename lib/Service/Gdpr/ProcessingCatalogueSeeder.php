<?php

/**
 * Seeds the processing activities an app declares into the verwerkingsregister on register import.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Gdpr
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/avg-verwerkingsregister/spec.md#requirement-apps-must-contribute-processing-activities-and-attribution-declaratively-via-x-openregister-processing-or-pa-2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Gdpr;

use InvalidArgumentException;
use OCA\OpenRegister\Db\Verwerkingsactiviteit;
use OCA\OpenRegister\Db\VerwerkingsactiviteitMapperInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Upserts, by code, the activities a configuration's `x-openregister-processing` blocks declare.
 *
 * Without this, an app's declared catalogue never reached the record of
 * processing: the register held only the fallback activity, and every
 * `attribution.default` code resolved to nothing.
 *
 * Two shapes are read, on every schema and register of the document:
 * - the `activities` catalogue (a map keyed by code, or a list of entries with a `code`);
 * - a block that is itself one activity: it carries `code` beside its name and purpose.
 *
 * A code already in the register is left alone, whatever its fields, so a
 * privacy officer's edits and activation survive every re-import. A new code
 * is written in lifecycle `concept`. An entry the register refuses (no name,
 * no purpose, an unknown legal basis) is named in the result and never stops
 * the import.
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Each branch is a way a declared entry can be shaped or refused.
 *
 * @spec openspec/specs/avg-verwerkingsregister/spec.md#requirement-apps-must-contribute-processing-activities-and-attribution-declaratively-via-x-openregister-processing-or-pa-2
 */
class ProcessingCatalogueSeeder {

	/**
	 * The annotation key.
	 */
	public const ANNOTATION_KEY = 'x-openregister-processing';

	/**
	 * Length of the register's retention-period column.
	 */
	private const RETENTION_PERIOD_LENGTH = 64;

	/**
	 * Spellings of a legal basis apps write, mapped onto the register's vocabulary.
	 *
	 * @var array<string, string>
	 */
	private const LEGAL_BASIS_ALIASES = [
		'publieke_taak'           => 'public_task',
		'wettelijke_verplichting' => 'legal_obligation',
		'toestemming'             => 'consent',
		'overeenkomst'            => 'contract',
		'gerechtvaardigd_belang'  => 'legitimate_interest',
		'vitaal_belang'           => 'vital_interests',
	];

	/**
	 * Constructor.
	 *
	 * @param VerwerkingsactiviteitMapperInterface $activities The record of processing.
	 * @param LoggerInterface                      $logger     Logger.
	 */
	public function __construct(
		private readonly VerwerkingsactiviteitMapperInterface $activities,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Seed the activities a configuration document declares.
	 *
	 * @param array<string, mixed> $document The decoded configuration document.
	 *
	 * @return array{created: list<string>, kept: list<string>, refused: array<string, string>} Codes per outcome.
	 *
	 * @spec openspec/specs/avg-verwerkingsregister/spec.md#scenario-register-import-seeds-the-apps-catalogue-as-drafts
	 */
	public function seed(array $document): array {
		$result = ['created' => [], 'kept' => [], 'refused' => []];
		foreach ($this->declaredEntries(document: $document) as $code => $entry) {
			$outcome = $this->seedOne(code: $code, entry: $entry);
			if ($outcome === 'created' || $outcome === 'kept') {
				$result[$outcome][] = $code;
				continue;
			}

			$result['refused'][$code] = $outcome;
		}

		return $result;
	}//end seed()

	/**
	 * Every declared activity in the document, keyed by code; the first declaration of a code wins.
	 *
	 * @param array<string, mixed> $document The decoded configuration document.
	 *
	 * @return array<string, array<string, mixed>> Entries by code.
	 *
	 * @spec openspec/specs/avg-verwerkingsregister/spec.md#requirement-apps-must-contribute-processing-activities-and-attribution-declaratively-via-x-openregister-processing-or-pa-2
	 */
	public function declaredEntries(array $document): array {
		$entries = [];
		$components = $document['components'] ?? [];
		if (is_array($components) === false) {
			return [];
		}

		foreach (['registers', 'schemas'] as $kind) {
			$definitions = $components[$kind] ?? [];
			if (is_array($definitions) === false) {
				continue;
			}

			foreach ($definitions as $definition) {
				$block = $this->blockOf(definition: $definition);
				foreach ($this->entriesOf(block: $block) as $code => $entry) {
					$entries[$code] ??= $entry;
				}
			}
		}

		return $entries;
	}//end declaredEntries()

	/**
	 * The processing block of a schema or register definition, top level or under `configuration`.
	 *
	 * @param mixed $definition The definition.
	 *
	 * @return array<string, mixed> The block, empty when there is none.
	 */
	private function blockOf(mixed $definition): array {
		if (is_array($definition) === false) {
			return [];
		}

		$block = $definition[self::ANNOTATION_KEY] ?? ($definition['configuration'][self::ANNOTATION_KEY] ?? null);
		if (is_array($block) === false) {
			return [];
		}

		return $block;
	}//end blockOf()

	/**
	 * The activities one block declares: its `activities` catalogue and, when it names a code, itself.
	 *
	 * @param array<string, mixed> $block The processing block.
	 *
	 * @return array<string, array<string, mixed>> Entries by code.
	 */
	private function entriesOf(array $block): array {
		$entries = [];
		$catalogue = $block['activities'] ?? [];
		if (is_array($catalogue) === true) {
			foreach ($catalogue as $key => $entry) {
				if (is_array($entry) === false) {
					continue;
				}

				$code = $this->text(value: ($entry['code'] ?? $key));
				if ($code !== null) {
					$entries[$code] ??= $entry;
				}
			}
		}

		$ownCode = $this->text(value: ($block['code'] ?? null));
		if ($ownCode !== null) {
			$entries[$ownCode] ??= $block;
		}

		return $entries;
	}//end entriesOf()

	/**
	 * Write one entry when its code is new.
	 *
	 * @param string               $code  The activity code.
	 * @param array<string, mixed> $entry The declared entry.
	 *
	 * @return string 'created', 'kept', or the reason it was refused.
	 */
	private function seedOne(string $code, array $entry): string {
		try {
			if ($this->activities->findByCode(code: $code) !== null) {
				return 'kept';
			}

			$this->activities->insert(entity: $this->activityFrom(code: $code, entry: $entry));
			return 'created';
		} catch (InvalidArgumentException $e) {
			return $e->getMessage();
		} catch (Throwable $e) {
			$this->logger->warning(
				message: '[ProcessingCatalogueSeeder] could not seed processing activity: ' . $e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__, 'code' => $code]
			);
			return 'not written: ' . $e->getMessage();
		}//end try
	}//end seedOne()

	/**
	 * Build the activity a declared entry describes, in lifecycle `concept`.
	 *
	 * @param string               $code  The activity code.
	 * @param array<string, mixed> $entry The declared entry.
	 *
	 * @return Verwerkingsactiviteit The unsaved activity.
	 *
	 * @spec openspec/specs/avg-verwerkingsregister/spec.md#requirement-apps-must-contribute-processing-activities-and-attribution-declaratively-via-x-openregister-processing-or-pa-2
	 */
	public function activityFrom(string $code, array $entry): Verwerkingsactiviteit {
		$activity = new Verwerkingsactiviteit();
		$activity->setCode($code);
		$activity->setName($this->text(value: ($entry['name'] ?? ($entry['naam'] ?? null))));
		$activity->setDescription($this->text(value: ($entry['description'] ?? ($entry['omschrijving'] ?? null))));
		$activity->setPurpose($this->text(value: ($entry['purpose'] ?? ($entry['doelbinding'] ?? null))));
		$activity->setLegalBasis($this->legalBasis(value: ($entry['legalBasis'] ?? ($entry['rechtsgrond'] ?? null))));
		$activity->setPersonalDataCategories($this->list(value: ($entry['personalDataCategories'] ?? ($entry['dataCategories'] ?? null))));
		$activity->setDataSubjectCategories($this->list(value: ($entry['dataSubjectCategories'] ?? ($entry['betrokkenen'] ?? null))));
		$activity->setRecipients($this->list(value: ($entry['recipients'] ?? ($entry['ontvangers'] ?? null))));
		$activity->setRetentionPeriod($this->retentionPeriod(value: ($entry['retentionPeriod'] ?? ($entry['bewaartermijn'] ?? null))));
		$activity->setStatus('concept');

		return $activity;
	}//end activityFrom()

	/**
	 * A legal basis on the register's vocabulary: hyphens become underscores and Dutch names map across.
	 *
	 * An unknown value is passed through, so the mapper refuses it with its own message.
	 *
	 * @param mixed $value The declared legal basis.
	 *
	 * @return string|null The normalised value.
	 *
	 * @spec openspec/specs/avg-verwerkingsregister/spec.md#requirement-apps-must-contribute-processing-activities-and-attribution-declaratively-via-x-openregister-processing-or-pa-2
	 */
	public function legalBasis(mixed $value): ?string {
		$text = $this->text(value: $value);
		if ($text === null) {
			return null;
		}

		$normal = str_replace(search: '-', replace: '_', subject: strtolower($text));
		return self::LEGAL_BASIS_ALIASES[$normal] ?? $normal;
	}//end legalBasis()

	/**
	 * A retention period short enough for the register's column; a longer text is not a period and is left out.
	 *
	 * A prose retention note (learniq's `retentionReference`) is not read: the
	 * column holds a period such as `P7Y`, and the activity stays valid without one.
	 *
	 * @param mixed $value The declared period.
	 *
	 * @return string|null The period.
	 */
	private function retentionPeriod(mixed $value): ?string {
		$text = $this->text(value: $value);
		if ($text === null || mb_strlen($text) > self::RETENTION_PERIOD_LENGTH) {
			return null;
		}

		return $text;
	}//end retentionPeriod()

	/**
	 * A non-empty trimmed string, or null.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string|null The text.
	 */
	private function text(mixed $value): ?string {
		if (is_string($value) === false || trim($value) === '') {
			return null;
		}

		return trim($value);
	}//end text()

	/**
	 * A list of strings, or null when there is none.
	 *
	 * @param mixed $value The value.
	 *
	 * @return list<mixed>|null The list.
	 */
	private function list(mixed $value): ?array {
		if (is_string($value) === true && trim($value) !== '') {
			return [trim($value)];
		}

		if (is_array($value) === false || $value === []) {
			return null;
		}

		return array_values($value);
	}//end list()
}//end class
