<?php

/**
 * OpenRegister ReferencedAddressNotifier
 *
 * Mails the address a notification rule reads off the object, directly or
 * through a reference: `{kind: email, field: supplierRef.contactEmail}`.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Notification
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/external-recipient-opt-out/spec.md#requirement-a-rule-may-mail-an-address-it-reads-through-a-reference-req-ero-007
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Notification;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Calculation\ReferenceTenantGuard;
use OCA\OpenRegister\Service\ObjectService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Resolves and mails the `email` recipient kind.
 *
 * The address is record data, so it is never trusted as an account: it is
 * mailed as a bare address, after integriq's opt-out check, exactly like a
 * party's address. A reference is read as the system (the dispatcher runs in
 * whatever session saved the object) and bounded to the object's tenant by
 * the same guard calculations use, so a reference cannot reach an address in
 * another organisation's register.
 */
class ReferencedAddressNotifier {

	/**
	 * Outcome when the field, or the reference it goes through, holds no usable address.
	 *
	 * @var string
	 */
	public const OUTCOME_NO_ADDRESS = 'no-address';

	/**
	 * Outcome when the reference names an object the tenant may not read, or none at all.
	 *
	 * @var string
	 */
	public const OUTCOME_REFERENCE_UNRESOLVED = 'reference-unresolved';

	/**
	 * Outcome when integriq refused the address.
	 *
	 * @var string
	 */
	public const OUTCOME_REFUSED_OPTED_OUT = 'refused-opted-out';

	/**
	 * Outcome when integriq did not answer.
	 *
	 * @var string
	 */
	public const OUTCOME_AUTHORITY_UNAVAILABLE = 'authority-unavailable';

	/**
	 * Wire the collaborators.
	 *
	 * @param ObjectService        $objectService Reads the referenced object.
	 * @param ReferenceTenantGuard $tenantGuard   Keeps the reference inside the object's tenant.
	 * @param OptOutAuthority      $optOut        Integriq's opt-out answer.
	 * @param EmailSender          $email         The shared mail send unit.
	 * @param LoggerInterface      $logger        For a reference read that failed.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/external-recipient-opt-out/spec.md#requirement-a-rule-may-mail-an-address-it-reads-through-a-reference-req-ero-007
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly ReferenceTenantGuard $tenantGuard,
		private readonly OptOutAuthority $optOut,
		private readonly EmailSender $email,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Mail every `email` recipient of a rule.
	 *
	 * @param array<int, mixed>    $recipientsSpec The rule's `recipients`.
	 * @param ObjectEntity         $object         The object the rule fired on.
	 * @param string               $subject        The rendered subject.
	 * @param string               $body           The rendered body.
	 * @param string               $category       The rule's message category.
	 *
	 * @return array<int, array{field: string, outcome: string}> What happened per recipient entry.
	 *
	 * @spec openspec/specs/external-recipient-opt-out/spec.md#requirement-a-rule-may-mail-an-address-it-reads-through-a-reference-req-ero-007
	 */
	public function notify(array $recipientsSpec, ObjectEntity $object, string $subject, string $body, string $category): array {
		$resolved = [];
		foreach ($recipientsSpec as $recipient) {
			if (is_array($recipient) === false || (string)($recipient['kind'] ?? '') !== 'email') {
				continue;
			}

			$field = trim((string)($recipient['field'] ?? ''));
			if ($field === '') {
				continue;
			}

			$resolved[] = ['field' => $field] + $this->addressFor(object: $object, field: $field);
		}

		if ($resolved === []) {
			return [];
		}

		$askable = [];
		foreach ($resolved as $entry) {
			if ($entry['address'] !== null) {
				$askable[] = $entry['address'];
			}
		}

		$decisions = $this->optOut->ask(
			channel: 'email',
			category: $category,
			addresses: $askable,
			correlationId: 'openregister-email-field:' . (string)$object->getUuid()
		);

		$outcomes = [];
		foreach ($resolved as $entry) {
			$outcomes[] = [
				'field' => $entry['field'],
				'outcome' => $this->send(entry: $entry, decisions: $decisions, subject: $subject, body: $body),
			];
		}

		return $outcomes;
	}//end notify()

	/**
	 * The address a field path names, or why there is none.
	 *
	 * One segment reads the address off the object itself. More segments read
	 * the first as a reference, load that object, and read the rest of the
	 * path inside it.
	 *
	 * @param ObjectEntity $object The object the rule fired on.
	 * @param string       $field  The dotted field path.
	 *
	 * @return array{address: string|null, outcome: string} The address, or null with the reason.
	 *
	 * @spec openspec/specs/external-recipient-opt-out/spec.md#requirement-a-rule-may-mail-an-address-it-reads-through-a-reference-req-ero-007
	 */
	public function addressFor(ObjectEntity $object, string $field): array {
		$data = ($object->getObject() ?? []);
		$segments = explode('.', $field);
		$head = array_shift($segments);

		if ($segments === []) {
			return $this->asAddress(value: ($data[$head] ?? null));
		}

		$referenced = $this->referenced(object: $object, reference: ($data[$head] ?? null));
		if ($referenced === null) {
			return ['address' => null, 'outcome' => self::OUTCOME_REFERENCE_UNRESOLVED];
		}

		$value = ($referenced->getObject() ?? []);
		foreach ($segments as $segment) {
			if (is_array($value) === false || array_key_exists($segment, $value) === false) {
				return ['address' => null, 'outcome' => self::OUTCOME_NO_ADDRESS];
			}

			$value = $value[$segment];
		}

		return $this->asAddress(value: $value);
	}//end addressFor()

	/**
	 * Send one resolved entry, or say why it was not sent.
	 *
	 * @param array{field: string, address: string|null, outcome: string} $entry     The resolved entry.
	 * @param array<string, array<string, mixed>>                          $decisions Integriq's answers.
	 * @param string                                                       $subject   The subject.
	 * @param string                                                       $body      The body.
	 *
	 * @return string The outcome.
	 */
	private function send(array $entry, array $decisions, string $subject, string $body): string {
		if ($entry['address'] === null) {
			return $entry['outcome'];
		}

		$decision = $this->optOut->decisionFor(decisions: $decisions, address: $entry['address']);
		if ($decision['send'] !== true) {
			if ($decision['code'] === OptOutAuthority::CODE_AUTHORITY_UNAVAILABLE) {
				return self::OUTCOME_AUTHORITY_UNAVAILABLE;
			}

			return self::OUTCOME_REFUSED_OPTED_OUT;
		}

		return $this->email->sendToAddress(
			address: $entry['address'],
			displayName: '',
			subject: $subject,
			body: $this->optOut->withLink(body: $body, unsubscribe: $decision['unsubscribe']),
			unsubscribe: $decision['unsubscribe']
		);
	}//end send()

	/**
	 * The object a reference value names, read as the system and bounded to the tenant.
	 *
	 * @param ObjectEntity $object    The object holding the reference.
	 * @param mixed        $reference A uuid, a URL or path ending in one, or an object carrying `id`/`uuid`.
	 *
	 * @return ObjectEntity|null The referenced object, or null when it is missing or outside the tenant.
	 */
	private function referenced(ObjectEntity $object, mixed $reference): ?ObjectEntity {
		$id = $this->referenceId(reference: $reference);
		if ($id === null) {
			return null;
		}

		try {
			$found = $this->objectService->find(
				id: $id,
				_rbac: false,
				_multitenancy: false,
				_render: false,
				_audit: false
			);
		} catch (Throwable $error) {
			$this->logger->info(
				'[ReferencedAddressNotifier] reference ' . $id . ' on ' . (string)$object->getUuid()
				. ' did not resolve: ' . $error->getMessage()
			);

			return null;
		}

		return $this->tenantGuard->firstAdmitted(savingOrganisation: $object->getOrganisation(), candidates: [$found]);
	}//end referenced()

	/**
	 * The object id a reference value carries.
	 *
	 * @param mixed $reference The stored reference.
	 *
	 * @return string|null The id, or null when the value names nothing.
	 */
	private function referenceId(mixed $reference): ?string {
		if (is_array($reference) === true) {
			$reference = ($reference['id'] ?? $reference['uuid'] ?? $reference['value'] ?? null);
		}

		if (is_string($reference) === false) {
			return null;
		}

		$reference = trim($reference);
		if ($reference === '') {
			return null;
		}

		// A URL or path names the object in its last segment.
		$parts = explode('/', rtrim($reference, '/'));
		$last = (string)end($parts);
		if ($last === '') {
			return null;
		}

		return $last;
	}//end referenceId()

	/**
	 * A value as a mailable address.
	 *
	 * @param mixed $value The value the path reached.
	 *
	 * @return array{address: string|null, outcome: string} The address, or null with the reason.
	 */
	private function asAddress(mixed $value): array {
		if (is_string($value) === true && filter_var(trim($value), FILTER_VALIDATE_EMAIL) !== false) {
			return ['address' => trim($value), 'outcome' => ''];
		}

		return ['address' => null, 'outcome' => self::OUTCOME_NO_ADDRESS];
	}//end asAddress()
}//end class
