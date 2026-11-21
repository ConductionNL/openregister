<?php

/**
 * ForeignIdentifierRegistry — records and resolves the identifiers other systems gave objects.
 *
 * An identifier from another system with no issuer is a string that collides
 * sooner or later, so every identifier here names its issuer (D-C41-2): the
 * lookup is exact, and two senders may use the same value without ambiguity.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <dev@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/generated-identifier/specs/computed-fields/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use DateTime;
use InvalidArgumentException;
use OCA\OpenRegister\Db\ForeignIdentifier;
use OCA\OpenRegister\Db\ForeignIdentifierMapper;
use OCP\DB\Exception as DbException;

/**
 * The foreign identifiers of objects: record one, resolve one, list an object's.
 */
class ForeignIdentifierRegistry {

	/**
	 * The longest issuer name the index holds.
	 */
	public const MAX_ISSUER_LENGTH = 128;

	/**
	 * The longest value the index holds.
	 */
	public const MAX_VALUE_LENGTH = 191;

	/**
	 * Constructor.
	 *
	 * @param ForeignIdentifierMapper $mapper The store.
	 */
	public function __construct(
		private readonly ForeignIdentifierMapper $mapper,
	) {
	}//end __construct()

	/**
	 * Record that an issuer identifies an object by a value.
	 *
	 * Recording the same identifier for the same object again changes
	 * nothing. The issuer and the value are compared exactly as given, after
	 * trimming surrounding whitespace.
	 *
	 * @param string $objectUuid The object.
	 * @param string $issuer     The issuing system.
	 * @param string $value      The value as the issuer wrote it.
	 *
	 * @return ForeignIdentifier The stored identifier.
	 *
	 * @throws InvalidArgumentException When the object, issuer or value is empty or too long.
	 * @throws ForeignIdentifierConflictException When the issuer's value already identifies another object.
	 * @throws DbException When the store fails for another reason.
	 *
	 * @spec openspec/changes/generated-identifier/specs/computed-fields/spec.md#requirement-an-object-carries-foreign-identifiers-that-name-their-issuer-req-gid-005
	 */
	public function record(string $objectUuid, string $issuer, string $value): ForeignIdentifier {
		$objectUuid = trim($objectUuid);
		$issuer     = self::checked(text: $issuer, what: 'issuer', max: self::MAX_ISSUER_LENGTH);
		$value      = self::checked(text: $value, what: 'value', max: self::MAX_VALUE_LENGTH);
		if ($objectUuid === '') {
			throw new InvalidArgumentException('A foreign identifier needs the object it identifies.');
		}

		$existing = $this->mapper->findByIssuerAndValue(issuer: $issuer, value: $value);
		if ($existing !== null) {
			if ($existing->getObjectUuid() === $objectUuid) {
				return $existing;
			}

			throw new ForeignIdentifierConflictException(
				sprintf('Issuer "%s" already uses value "%s" for another object.', $issuer, $value)
			);
		}

		$identifier = new ForeignIdentifier();
		$identifier->setObjectUuid($objectUuid);
		$identifier->setIssuer($issuer);
		$identifier->setValue($value);
		$identifier->setCreated(new DateTime());

		try {
			return $this->mapper->insert(entity: $identifier);
		} catch (DbException $e) {
			if ($e->getReason() !== DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				throw $e;
			}

			// A concurrent writer recorded it between the read and the insert;
			// the unique index decided, so answer as if it had been there first.
			$winner = $this->mapper->findByIssuerAndValue(issuer: $issuer, value: $value);
			if ($winner !== null && $winner->getObjectUuid() === $objectUuid) {
				return $winner;
			}

			throw new ForeignIdentifierConflictException(
				sprintf('Issuer "%s" already uses value "%s" for another object.', $issuer, $value),
				previous: $e
			);
		}
	}//end record()

	/**
	 * The object an issuer's value identifies, or null.
	 *
	 * @param string $issuer The issuing system.
	 * @param string $value  The value as the issuer wrote it.
	 *
	 * @return string|null The object's UUID.
	 *
	 * @spec openspec/changes/generated-identifier/specs/computed-fields/spec.md#requirement-an-object-carries-foreign-identifiers-that-name-their-issuer-req-gid-005
	 */
	public function resolve(string $issuer, string $value): ?string {
		$issuer = trim($issuer);
		$value  = trim($value);
		if ($issuer === '' || $value === '') {
			return null;
		}

		return $this->mapper->findByIssuerAndValue(issuer: $issuer, value: $value)?->getObjectUuid();
	}//end resolve()

	/**
	 * Every foreign identifier of one object.
	 *
	 * @param string $objectUuid The object.
	 *
	 * @return array<int, array{issuer: string|null, value: string|null, object: string|null, created: string|null}>
	 *
	 * @spec openspec/changes/generated-identifier/specs/computed-fields/spec.md#requirement-an-object-carries-foreign-identifiers-that-name-their-issuer-req-gid-005
	 */
	public function forObject(string $objectUuid): array {
		return array_map(
			static fn (ForeignIdentifier $identifier): array => $identifier->jsonSerialize(),
			$this->mapper->findForObject(objectUuid: $objectUuid)
		);
	}//end forObject()

	/**
	 * A trimmed, non-empty text that fits its column.
	 *
	 * @param string $text The text.
	 * @param string $what What it is, for the message.
	 * @param int    $max  The longest allowed.
	 *
	 * @return string The trimmed text.
	 *
	 * @throws InvalidArgumentException When it is empty or too long.
	 */
	private static function checked(string $text, string $what, int $max): string {
		$text = trim($text);
		if ($text === '') {
			throw new InvalidArgumentException(sprintf('A foreign identifier needs a non-empty %s.', $what));
		}

		if (mb_strlen($text) > $max) {
			throw new InvalidArgumentException(sprintf('A foreign identifier %s is at most %d characters.', $what, $max));
		}

		return $text;
	}//end checked()
}//end class
