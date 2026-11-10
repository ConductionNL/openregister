<?php

/**
 * A check an administrator writes, with the sentence it says (row 11.53).
 *
 * The ledger note: "FieldValidator and StepConfigValidator validate what the
 * code says. An administrator cannot add a check, and cannot write the sentence
 * the handler reads when it fails."
 *
 * 🔑 THE MESSAGE IS THE ADMINISTRATOR'S, AND IT IS RETURNED VERBATIM (D-6). A
 * validation with a generic message teaches people to click past it. The
 * sentence is stored beside the rule as translatable content, points at the
 * properties it concerns, and reaches the handler unedited — not summarised,
 * not prefixed, not wrapped in "Validation failed:".
 *
 * 🔴 A VALIDATION WITH NO MESSAGE IS REFUSED AT SAVE. Not defaulted to a
 * generic sentence: a default here is how every validation ends up saying the
 * same thing, which is the state the row describes. If nobody wrote the
 * sentence, the check does not ship.
 *
 * 🔴 AND A CONDITION THAT CANNOT BE EVALUATED REFUSES THE SAVE. A validation
 * whose condition is unresolvable has not been satisfied; it has not been
 * ASKED. Treating that as "the check passed" would let a broken reference
 * silently switch off every check that used it — the same fail-open shape
 * {@see NamedConditionEvaluator} exists to prevent, one layer up.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rules
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/rules-compose-read-transitions-and-time/specs/object-lifecycle/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rules;

/**
 * Evaluates the validations a schema declares, in the administrator's words.
 *
 * @spec openspec/changes/rules-compose-read-transitions-and-time/specs/object-lifecycle/spec.md
 */
class AdministeredValidations {

	/**
	 * The schema annotation validations are declared under.
	 *
	 * 🔴 IT MUST BE IN `Schema::ANNOTATION_VOCABULARY`. Absent from that list,
	 * `setConfiguration()` DROPS it, and a schema whose author had just added a
	 * mandatory check would read a 200 and then watch every violating object
	 * save happily. That is the silent no-op the vocabulary list exists to
	 * prevent, and here it is a missing CONTROL, not a missing feature.
	 *
	 * @var string
	 */
	public const ANNOTATION = 'x-openregister-validations';

	/**
	 * The save fails.
	 *
	 * @var string
	 */
	public const REFUSE = 'refuse';

	/**
	 * The save succeeds and the message comes back with it.
	 *
	 * @var string
	 */
	public const WARN = 'warn';

	/**
	 * The severities a validation may declare.
	 *
	 * @var array<int, string>
	 */
	public const SEVERITIES = [self::REFUSE, self::WARN];

	/**
	 * The language a message falls back to when the caller's is not declared.
	 *
	 * @var string
	 */
	public const FALLBACK_LANGUAGE = 'nl';

	/**
	 * Constructor.
	 *
	 * @param NamedConditionEvaluator $evaluator The evaluator, so a validation can use a named condition.
	 */
	public function __construct(
		private readonly NamedConditionEvaluator $evaluator,
	) {
	}//end __construct()

	/**
	 * The declared validations, keyed by name.
	 *
	 * @param array<string, mixed>|null $annotation The declaration.
	 *
	 * @return array<string, array<string, mixed>> The validations.
	 *
	 * @spec openspec/changes/rules-compose-read-transitions-and-time/specs/object-lifecycle/spec.md
	 */
	public function declarationsFrom(?array $annotation): array {
		$declarations = [];
		foreach (($annotation ?? []) as $name => $declaration) {
			if ((string)$name !== '' && is_array($declaration) === true) {
				$declarations[(string)$name] = $declaration;
			}
		}

		return $declarations;
	}//end declarationsFrom()

	/**
	 * Why a declared validation may not be saved, or null when it may.
	 *
	 * @param array<string, mixed>|null $annotation         The declaration.
	 * @param array<int, string>        $declaredProperties The properties the schema declares.
	 *
	 * @return string|null The reason, naming the validation.
	 *
	 * @spec openspec/changes/rules-compose-read-transitions-and-time/specs/object-lifecycle/spec.md
	 */
	public function refusalFor(?array $annotation, array $declaredProperties = []): ?string {
		foreach ($this->declarationsFrom(annotation: $annotation) as $name => $declaration) {
			$reason = ($this->severityRefusal(declaration: $declaration) ?? $this->contentRefusal(declaration: $declaration))
				?? $this->propertyRefusal(declaration: $declaration, declaredProperties: $declaredProperties);
			if ($reason !== null) {
				return sprintf('validation "%s" %s', $name, $reason);
			}
		}

		return null;
	}//end refusalFor()

	/**
	 * Why a validation's condition or severity is refused, after its name.
	 *
	 * @param array<string, mixed> $declaration The validation.
	 *
	 * @return string|null The reason, or null.
	 */
	private function severityRefusal(array $declaration): ?string {
		if (array_key_exists('condition', $declaration) === false) {
			return 'declares no condition';
		}

		$severity = (string)($declaration['severity'] ?? '');
		if (in_array($severity, self::SEVERITIES, true) === false) {
			return sprintf('declares severity "%s": use one of %s', $severity, implode(' or ', self::SEVERITIES));
		}

		return null;
	}//end severityRefusal()

	/**
	 * Why a validation's message or property list is refused, after its name.
	 *
	 * @param array<string, mixed> $declaration The validation.
	 *
	 * @return string|null The reason, or null.
	 */
	private function contentRefusal(array $declaration): ?string {
		if ($this->messagesOf(declaration: $declaration) === []) {
			return 'carries no message; a check whose sentence nobody wrote is not shipped, '
				. 'because a generic one is how every validation ends up saying the same thing';
		}

		if (is_array(($declaration['properties'] ?? [])) === false) {
			return 'must name its properties as a list';
		}

		return null;
	}//end contentRefusal()

	/**
	 * Why a validation points at a property the schema does not declare, after its name.
	 *
	 * Only checked when the schema's properties were supplied: an empty list
	 * here means "the caller did not tell me", which is a different thing from
	 * "the schema declares nothing", and refusing on it would refuse every
	 * validation on every schema.
	 *
	 * @param array<string, mixed> $declaration        The validation, its properties already a list.
	 * @param array<int, string>   $declaredProperties The properties the schema declares.
	 *
	 * @return string|null The reason, or null.
	 */
	private function propertyRefusal(array $declaration, array $declaredProperties): ?string {
		if ($declaredProperties === []) {
			return null;
		}

		foreach (($declaration['properties'] ?? []) as $property) {
			if (in_array((string)$property, $declaredProperties, true) === false) {
				return sprintf('points at "%s", which this schema does not declare', (string)$property);
			}
		}

		return null;
	}//end propertyRefusal()

	/**
	 * Evaluate the declared validations against one document.
	 *
	 * A validation's condition describes the VIOLATION, so a condition that
	 * holds is a check that failed. That reads the right way round in a
	 * declaration — "refuse when bedrag is above 50000 and mandaat is empty" —
	 * and it is stated here because the opposite convention is equally
	 * defensible and silently inverts every check.
	 *
	 * @param array<string, mixed>|null $annotation The declared validations.
	 * @param ValidationInput           $input      The object as it would be saved, the named conditions in scope and the caller's language.
	 *
	 * @return array{refusals: array<int, array<string, mixed>>, warnings: array<int, array<string, mixed>>} The outcome.
	 *
	 * @spec openspec/changes/rules-compose-read-transitions-and-time/specs/object-lifecycle/spec.md
	 */
	public function evaluate(?array $annotation, ValidationInput $input): array {
		$outcome = ['refusals' => [], 'warnings' => []];
		foreach ($this->declarationsFrom(annotation: $annotation) as $name => $declaration) {
			$entry = $this->entryFor(declaration: $declaration, input: $input);
			if ($entry === null) {
				continue;
			}

			$outcome[$this->bucketOf(entry: $entry)][] = (['validation' => (string)$name] + $entry);
		}

		return $outcome;
	}//end evaluate()

	/**
	 * The outcome of one validation without its name, or null when it holds.
	 *
	 * @param array<string, mixed> $declaration The validation.
	 * @param ValidationInput      $input       What the evaluation reads.
	 *
	 * @return array<string, mixed>|null The entry, or null when the check passed.
	 */
	private function entryFor(array $declaration, ValidationInput $input): ?array {
		try {
			$node     = ($declaration['condition'] ?? null);
			$violated = $this->evaluator->holds(
				node: $node,
				evaluation: new ConditionEvaluation(document: $input->document, library: $input->library, depth: 0)
			);
		} catch (ConditionRefusedException $refused) {
			return $this->unevaluableEntry(declaration: $declaration, refused: $refused);
		}

		if ($violated === false) {
			return null;
		}

		return $this->violationEntry(declaration: $declaration, language: $input->language);
	}//end entryFor()

	/**
	 * The entry for a validation whose condition holds: the check failed.
	 *
	 * @param array<string, mixed> $declaration The validation.
	 * @param string               $language    The caller's language.
	 *
	 * @return array<string, mixed> The entry, without the validation's name.
	 */
	private function violationEntry(array $declaration, string $language): array {
		return [
			'severity' => (string)($declaration['severity'] ?? self::REFUSE),
			'properties' => $this->propertiesOf(declaration: $declaration),
			'message' => $this->messageIn(declaration: $declaration, language: $language),
			'unevaluable' => false,
		];
	}//end violationEntry()

	/**
	 * Which list an entry lands in: only a violated `warn` lets the save through.
	 *
	 * @param array<string, mixed> $entry The entry.
	 *
	 * @return string `warnings` or `refusals`.
	 */
	private function bucketOf(array $entry): string {
		if ($entry['severity'] === self::WARN) {
			return 'warnings';
		}

		return 'refusals';
	}//end bucketOf()

	/**
	 * The refusal entry for a validation whose condition could not be evaluated.
	 *
	 * 🔴 Unevaluable is a REFUSAL of the save, whatever the declared severity.
	 * A check that could not be asked has not been passed, and a `warn` that
	 * quietly becomes "fine" is how a broken named condition switches off a
	 * mandatory control.
	 *
	 * @param array<string, mixed>      $declaration The validation.
	 * @param ConditionRefusedException $refused     Why it could not be evaluated.
	 *
	 * @return array<string, mixed> The entry, without the validation's name.
	 */
	private function unevaluableEntry(array $declaration, ConditionRefusedException $refused): array {
		return [
			'severity' => self::REFUSE,
			'properties' => $this->propertiesOf(declaration: $declaration),
			'message' => sprintf('This check could not be evaluated, so the save is refused: %s', $refused->getWhy()),
			'unevaluable' => true,
		];
	}//end unevaluableEntry()

	/**
	 * The message in the caller's language, or the nearest one declared.
	 *
	 * Falls back rather than returning an empty string, because a refusal with
	 * no sentence is the generic message wearing a different hat. A validation
	 * with no message at all cannot reach here: it is refused at save.
	 *
	 * @param array<string, mixed> $declaration The validation.
	 * @param string               $language    The caller's language.
	 *
	 * @return string The message, verbatim.
	 *
	 * @spec openspec/changes/rules-compose-read-transitions-and-time/specs/object-lifecycle/spec.md
	 */
	public function messageIn(array $declaration, string $language): string {
		$messages = $this->messagesOf(declaration: $declaration);

		// A regional tag falls back to its base language: `en_GB` should read
		// the English sentence rather than the Dutch one.
		$base = strtolower((string)preg_replace('/[_-].*$/', '', $language));
		foreach ([$language, $base, self::FALLBACK_LANGUAGE] as $candidate) {
			if (array_key_exists($candidate, $messages) === true) {
				return $messages[$candidate];
			}
		}

		return (string)reset($messages);
	}//end messageIn()

	/**
	 * The declared messages, keyed by language.
	 *
	 * A bare string is accepted as the fallback language, because that is what
	 * an author writes first and refusing it would make the simple case the
	 * awkward one.
	 *
	 * @param array<string, mixed> $declaration The validation.
	 *
	 * @return array<string, string> Language to message.
	 */
	private function messagesOf(array $declaration): array {
		$message = ($declaration['message'] ?? null);
		if (is_string($message) === true && trim($message) !== '') {
			return [self::FALLBACK_LANGUAGE => $message];
		}

		if (is_array($message) === false) {
			return [];
		}

		return array_filter($message, static fn (mixed $text): bool => is_string($text) === true && trim($text) !== '');
	}//end messagesOf()

	/**
	 * The properties a validation points at.
	 *
	 * @param array<string, mixed> $declaration The validation.
	 *
	 * @return array<int, string> The properties.
	 */
	private function propertiesOf(array $declaration): array {
		$properties = ($declaration['properties'] ?? []);
		if (is_array($properties) === false) {
			return [];
		}

		return array_values(array_map(static fn (mixed $name): string => (string)$name, $properties));
	}//end propertiesOf()
}//end class
