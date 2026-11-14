<?php

/**
 * MailObjectBinding: the object an outgoing mail is sent from.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Notifications\Service\Notification
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Notifications\Service\Notification;

/**
 * The object, its register and schema, and who sends: enough to write the
 * outbound email link of a sent mail.
 *
 * @spec openspec/changes/reply-threading-by-headers/specs/integration-email/spec.md
 */
final class MailObjectBinding {

	/**
	 * Constructor.
	 *
	 * @param string   $objectUuid The object's uuid.
	 * @param int      $registerId The object's register.
	 * @param int|null $schemaId   The object's schema, when known.
	 * @param string   $linkedBy   Who the link is recorded for: the acting user.
	 */
	public function __construct(
		public readonly string $objectUuid,
		public readonly int $registerId,
		public readonly ?int $schemaId,
		public readonly string $linkedBy,
	) {
	}//end __construct()

	/**
	 * The binding of a serialised object, or null when it names no uuid or register.
	 *
	 * @param array<string, mixed> $json     The object as serialised, with its `@self`.
	 * @param string               $linkedBy The acting user.
	 *
	 * @return self|null The binding.
	 *
	 * @spec openspec/changes/reply-threading-by-headers/specs/integration-email/spec.md
	 */
	public static function fromObjectJson(array $json, string $linkedBy): ?self {
		$self = ($json['@self'] ?? []);
		if (is_array($self) === false) {
			$self = [];
		}

		$uuid = ($json['uuid'] ?? ($self['id'] ?? ($json['id'] ?? null)));
		$register = self::positiveId(value: ($self['register'] ?? null));
		if (is_string($uuid) === false || $uuid === '' || $register === null || $linkedBy === '') {
			return null;
		}

		return new self(objectUuid: $uuid, registerId: $register, schemaId: self::positiveId(value: ($self['schema'] ?? null)), linkedBy: $linkedBy);
	}//end fromObjectJson()

	/**
	 * A positive integer id from an int or a digit string, else null.
	 *
	 * @param mixed $value The value.
	 *
	 * @return int|null The id.
	 */
	private static function positiveId(mixed $value): ?int {
		if (is_int($value) === true && $value > 0) {
			return $value;
		}

		if (is_string($value) === true && preg_match('/^[1-9]\d*$/', $value) === 1) {
			return (int) $value;
		}

		return null;
	}//end positiveId()
}//end class
