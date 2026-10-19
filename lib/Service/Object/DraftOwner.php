<?php

/**
 * Who owns a draft: a Nextcloud user, or a scope value such as `portal:<subjectRef>` (decision 195).
 *
 * Ruben, 11 October 2026: OpenRegister accepts a scope-value draft owner such
 * as `portal:<subjectRef>`, and only that subject reads or sends the draft. A
 * resident signed in at a portal has no Nextcloud account, so a user id cannot
 * own their draft. The value is stored in the object's ordinary `_owner`
 * column. A Nextcloud user id cannot hold a colon, so `<scope>:<reference>`
 * never names a user and no user's owner admit in the read RBAC matches it.
 *
 * The caller asserts the owner. For a portal that is the app that verified
 * the resident's session in-process; OpenRegister then only ever compares the
 * asserted value with the stored one. Nothing here names an app or a
 * procedure (decision 182): `portal` is just the scope portals use.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-a-draft-must-be-able-to-be-owned-by-a-scope-value
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

use InvalidArgumentException;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\IUser;

/**
 * A draft owner value, and the one comparison that decides whether it owns a draft.
 *
 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-a-draft-must-be-able-to-be-owned-by-a-scope-value
 */
final class DraftOwner {

	/**
	 * The scope a portal's signed-in subjects own drafts under.
	 *
	 * @var string
	 */
	public const PORTAL_SCOPE = 'portal';

	/**
	 * The width of the `_owner` column; a longer value would be cut and then match nothing.
	 *
	 * @var integer
	 */
	public const MAX_LENGTH = 64;

	/**
	 * Constructor.
	 *
	 * @param string     $value The stored owner value.
	 * @param IUser|null $user  The user, when the owner is one.
	 */
	private function __construct(
		private readonly string $value,
		private readonly ?IUser $user,
	) {

	}//end __construct()

	/**
	 * A Nextcloud user as owner: the uid, as every object has had until now.
	 *
	 * @param IUser $user The user.
	 *
	 * @return self The owner.
	 *
	 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-a-draft-must-be-able-to-be-owned-by-a-scope-value
	 */
	public static function ofUser(IUser $user): self {
		return new self(value: $user->getUID(), user: $user);
	}//end ofUser()

	/**
	 * A scope value as owner: `<scope>:<reference>`.
	 *
	 * @param string $scope     Lower case letters, digits and hyphens, starting with a letter.
	 * @param string $reference The subject within the scope; trimmed, no control characters.
	 *
	 * @return self The owner.
	 *
	 * @throws InvalidArgumentException When either part is malformed or the value does not fit the owner column.
	 *
	 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-a-draft-must-be-able-to-be-owned-by-a-scope-value
	 */
	public static function scoped(string $scope, string $reference): self {
		if (preg_match('/^[a-z][a-z0-9-]*$/', $scope) !== 1) {
			throw new InvalidArgumentException('A draft owner scope is lower case letters, digits and hyphens.');
		}

		$reference = trim($reference);
		if ($reference === '' || preg_match('/[\x00-\x1F\x7F]/', $reference) === 1) {
			throw new InvalidArgumentException('A draft owner reference is a non-empty value without control characters.');
		}

		$value = $scope . ':' . $reference;
		if (strlen($value) > self::MAX_LENGTH) {
			throw new InvalidArgumentException(sprintf('A draft owner value is at most %d characters.', self::MAX_LENGTH));
		}

		return new self(value: $value, user: null);
	}//end scoped()

	/**
	 * A portal's signed-in subject as owner: `portal:<subjectRef>`.
	 *
	 * @param string $subjectRef The subject reference the portal verified.
	 *
	 * @return self The owner.
	 *
	 * @throws InvalidArgumentException When the reference is empty or too long.
	 *
	 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-a-draft-must-be-able-to-be-owned-by-a-scope-value
	 */
	public static function portalSubject(string $subjectRef): self {
		return self::scoped(scope: self::PORTAL_SCOPE, reference: $subjectRef);
	}//end portalSubject()

	/**
	 * The draft's owner: the asserted one, else the signed-in subject, else nobody.
	 *
	 * @param IUser|null      $subject The signed-in subject.
	 * @param DraftOwner|null $owner   The asserted owner.
	 *
	 * @return self|null The owner, or null when there is none.
	 *
	 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-a-draft-must-be-able-to-be-owned-by-a-scope-value
	 */
	public static function resolve(?IUser $subject, ?self $owner): ?self {
		if ($owner !== null || $subject === null) {
			return $owner;
		}

		return self::ofUser(user: $subject);
	}//end resolve()

	/**
	 * The value stored in, and compared with, the object's owner.
	 *
	 * @return string The value.
	 *
	 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-a-draft-must-be-able-to-be-owned-by-a-scope-value
	 */
	public function value(): string {
		return $this->value;
	}//end value()

	/**
	 * The user, when the owner is one.
	 *
	 * @return IUser|null The user, or null for a scope value.
	 *
	 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-a-draft-must-be-able-to-be-owned-by-a-scope-value
	 */
	public function user(): ?IUser {
		return $this->user;
	}//end user()

	/**
	 * Whether the owner is a scope value rather than a user.
	 *
	 * @return bool True for a scope value.
	 *
	 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-a-draft-must-be-able-to-be-owned-by-a-scope-value
	 */
	public function isScoped(): bool {
		return $this->user === null;
	}//end isScoped()

	/**
	 * Whether this owner owns the object as a draft: it is a draft and its owner is exactly this value.
	 *
	 * @param ObjectEntity $object The stored object.
	 *
	 * @return bool True when it is this owner's draft.
	 *
	 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-a-draft-must-be-able-to-be-owned-by-a-scope-value
	 */
	public function owns(ObjectEntity $object): bool {
		return $object->isDraft() === true && $object->getOwner() === $this->value;
	}//end owns()
}//end class
