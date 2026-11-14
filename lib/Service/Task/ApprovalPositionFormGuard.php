<?php

/**
 * OpenRegister ApprovalPositionFormGuard
 *
 * Refuses, at schema save, an approval chain position whose form no approver
 * could fill in (approval-position-task-form, D-2).
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Task
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/approval-position-task-form/specs/flow-approval-consolidation/spec.md#requirement-an-approval-position-may-declare-the-form-its-task-presents
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <dev@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Task;

use OCA\OpenRegister\Db\Schema;
use OCP\IL10N;
use Throwable;
use UnexpectedValueException;

/**
 * Judges the forms of a schema body's approval chains against that body.
 *
 * The rule and the reasons are the flow step's (`flow-task-forms`, "A field
 * that cannot be rendered is refused when the step is saved"): the same
 * {@see TaskFormReader} parses the declaration and names why a field cannot
 * be asked for. Refusing here, not when the first approval is requested,
 * keeps the failure in front of the schema author instead of the requester.
 *
 * @spec openspec/changes/approval-position-task-form/specs/flow-approval-consolidation/spec.md#requirement-an-approval-position-may-declare-the-form-its-task-presents
 */
class ApprovalPositionFormGuard {

	/**
	 * The schema configuration key that declares approval chains.
	 *
	 * @var string
	 */
	public const CHAINS_KEY = 'x-openregister-approval-chains';

	/**
	 * Constructor.
	 *
	 * @param TaskFormReader $forms Parses a form record and names unrenderable fields.
	 * @param IL10N          $l10n  Translations, for refusals an author reads.
	 */
	public function __construct(
		private readonly TaskFormReader $forms,
		private readonly IL10N $l10n,
	) {
	}//end __construct()

	/**
	 * Whether a schema body declares any position form, so the guard has work.
	 *
	 * @param array<string, mixed> $data The schema body.
	 *
	 * @return bool True when at least one `approvers` entry carries `form`.
	 *
	 * @spec openspec/changes/approval-position-task-form/specs/flow-approval-consolidation/spec.md#requirement-an-approval-position-may-declare-the-form-its-task-presents
	 */
	public static function declaresForms(array $data): bool {
		foreach (self::chainsOf(data: $data) as $chain) {
			foreach ((array)($chain['approvers'] ?? []) as $approver) {
				if (is_array($approver) === true && array_key_exists('form', $approver) === true) {
					return true;
				}
			}
		}

		return false;
	}//end declaresForms()

	/**
	 * One refusal per position form that cannot be filled in, naming the chain, the position, the field and why.
	 *
	 * @param array<string, mixed> $data The schema body: `properties`, `configuration`, `slug`.
	 *
	 * @return array<int, string> The refusals, empty when every form can be filled in.
	 *
	 * @spec openspec/changes/approval-position-task-form/specs/flow-approval-consolidation/spec.md#requirement-an-approval-position-may-declare-the-form-its-task-presents
	 */
	public function violations(array $data): array {
		$schema     = $this->bodySchema(data: $data);
		$violations = [];
		foreach (self::chainsOf(data: $data) as $chainKey => $chain) {
			$approvers = array_values((array)($chain['approvers'] ?? []));
			foreach ($approvers as $index => $approver) {
				if (is_array($approver) === false || array_key_exists('form', $approver) === false) {
					continue;
				}

				$where = $this->l10n->t(
					'Approval chain "%1$s", position %2$d (%3$s)',
					[(string)$chainKey, ($index + 1), trim((string)($approver['role'] ?? ''))]
				);
				foreach ($this->formViolations(declared: $approver['form'], schema: $schema) as $reason) {
					$violations[] = $where . ': ' . $reason;
				}
			}
		}

		return $violations;
	}//end violations()

	/**
	 * Why one position form cannot be filled in.
	 *
	 * @param mixed  $declared The declared form.
	 * @param Schema $schema   The schema the body describes.
	 *
	 * @return array<int, string> The reasons.
	 */
	private function formViolations(mixed $declared, Schema $schema): array {
		$record = ApprovalPositionForm::declaration(declared: $declared, schemaId: 0);
		if ($record === null) {
			return [];
		}

		try {
			$form = $this->forms->fromRecord(record: $record);
			if ($form->isExternal() === true) {
				$this->forms->validate(form: $form);
				return [];
			}

			if ($form->hasForm() === false) {
				return [];
			}

			$fields = $this->forms->declaredFields(form: $form, schema: $schema);
		} catch (UnexpectedValueException $refused) {
			return [$refused->getMessage()];
		}

		$reasons = [];
		foreach ($fields as $field) {
			$reason = $this->forms->unrenderableReason(schema: $schema, field: $field['field']);
			if ($reason !== null) {
				$reasons[] = $this->l10n->t(
					'Field "%1$s" of schema "%2$s" cannot be asked for: %3$s',
					[$field['field'], (string)$schema->getSlug(), $reason]
				);
			}
		}

		return $reasons;
	}//end formViolations()

	/**
	 * The schema the body describes, to judge fields and actions against.
	 *
	 * @param array<string, mixed> $data The schema body.
	 *
	 * @return Schema The unsaved schema.
	 */
	private function bodySchema(array $data): Schema {
		$schema = new Schema();
		$schema->setSlug(trim((string)($data['slug'] ?? '')));
		$schema->setProperties((array)($data['properties'] ?? []));
		try {
			$schema->setConfiguration((array)($data['configuration'] ?? []));
		} catch (Throwable) {
			// A configuration the vocabulary refuses is answered by the save
			// itself; only a lifecycle action form needs it here, and that
			// form is then refused as naming an undeclared action.
			$schema->setConfiguration([]);
		}

		return $schema;
	}//end bodySchema()

	/**
	 * The chains a schema body declares, by key.
	 *
	 * @param array<string, mixed> $data The schema body.
	 *
	 * @return array<int|string, array<string, mixed>> The chains that are maps.
	 */
	private static function chainsOf(array $data): array {
		$chains = (((array)($data['configuration'] ?? []))[self::CHAINS_KEY] ?? null);
		if (is_array($chains) === false) {
			return [];
		}

		return array_filter($chains, 'is_array');
	}//end chainsOf()
}//end class
