<?php

/**
 * OpenRegister NotificationTransportValidator
 *
 * Schema-save validation for the `transports` block of a notification rule.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Notification
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Notifications\Service\Notification;

/**
 * Validates the optional `transports` block of a notification rule.
 *
 * @spec openspec/specs/notificatie-engine/spec.md#requirement-one-rule-reaches-a-person-and-an-integration-recorded-once-req-nrg-004
 */
final class NotificationTransportValidator {

	/**
	 * Transport kinds a rule may declare beside its channels.
	 */
	private const VALID_TRANSPORT_KINDS = ['outbound'];

	/**
	 * Every diagnostic this class reports: fault => [error code, sprintf format].
	 */
	private const FAULTS = [
		'transports-malformed' => ['notification-transports-malformed', 'Notification "%s" transports must be an array.'],
		'transport-malformed'  => ['notification-transport-malformed', 'Notification "%s" transports[%s] must be an object.'],
		'transport-bad-kind'   => ['notification-transport-bad-kind', 'Notification "%s" transports[%s] kind "%s" not in [%s].'],
		'transport-no-handler' => [
			'notification-transport-no-handler',
			'Notification "%s" transports[%s] kind=outbound requires a handler (DI tag or FQCN).',
		],
	];

	/**
	 * Validate the optional `transports` block.
	 *
	 * Only `outbound` is a transport a rule declares here; the in-app notice,
	 * the e-mail and the activity entry stay in `channels`, which is where
	 * every existing rule already spells them. A handler is required because a
	 * transport naming none is an integration that silently never runs.
	 *
	 * @param mixed  $transports Raw value of the `transports` key.
	 * @param string $name       The notification name (for error messages).
	 *
	 * @return array<int, array{code: string, message: string}> One entry per fault.
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md#requirement-one-rule-reaches-a-person-and-an-integration-recorded-once-req-nrg-004
	 */
	public function validate(mixed $transports, string $name): array {
		if (is_array($transports) === false) {
			return [$this->fault(fault: 'transports-malformed', args: [$name])];
		}

		$errors = [];
		foreach ($transports as $i => $transport) {
			$errors[] = $this->transportError(transport: $transport, where: [$name, (string)$i]);
		}

		return array_values(array_filter($errors));
	}//end validate()

	/**
	 * The error for one declared transport, null when it is valid.
	 *
	 * @param mixed                 $transport The declared transport.
	 * @param array{string, string} $where     The rule key and the transport's index.
	 *
	 * @return array{code: string, message: string}|null
	 */
	private function transportError(mixed $transport, array $where): ?array {
		if (is_array($transport) === false) {
			return $this->fault(fault: 'transport-malformed', args: $where);
		}

		$kind = (string)($transport['kind'] ?? '');
		if (in_array($kind, self::VALID_TRANSPORT_KINDS, true) === false) {
			return $this->fault(fault: 'transport-bad-kind', args: [...$where, $kind, implode(', ', self::VALID_TRANSPORT_KINDS)]);
		}

		// `outbound` is the only kind, so reaching here means it; a second kind adds its own guard.
		if ((string)($transport['handler'] ?? '') === '') {
			return $this->fault(fault: 'transport-no-handler', args: $where);
		}

		return null;
	}//end transportError()

	/**
	 * One error as the annotation reports it.
	 *
	 * @param string            $fault A key of self::FAULTS.
	 * @param array<int, mixed> $args  The values its format interpolates.
	 *
	 * @return array{code: string, message: string}
	 */
	private function fault(string $fault, array $args): array {
		[$code, $format] = self::FAULTS[$fault];

		return ['code' => $code, 'message' => sprintf($format, ...$args)];
	}//end fault()
}//end class
