<?php

/**
 * The token a call was made with, named without its secret.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Audit
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/audit-trail-shipped-and-purpose-bound/specs/enhanced-audit-trail/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Audit;

/**
 * Who made this write, when a token made it.
 *
 * Three things, and they are three because the question people ask needs all
 * three: "which koppeling changed this field" is answered by the CONSUMER,
 * "who do I ring about it" by the OWNER, and "which of that consumer's four
 * credentials do I revoke" by the TOKEN.
 *
 * ⚠️ THE TOKEN IS NAMED, NEVER QUOTED. `reference` is the token's stable id and
 * `name` is the label its owner gave it. The token VALUE never reaches this
 * object and must never be added to it: an audit trail is read by more people
 * than a credential store is, it is shipped off the instance by design (the
 * file sink in this same change), and it is retained for years. A trail that
 * carries live credentials is a breach waiting for somebody to grep it.
 *
 * @spec openspec/changes/audit-trail-shipped-and-purpose-bound/specs/enhanced-audit-trail/spec.md
 */
class TokenIdentity {
	/**
	 * Constructor.
	 *
	 * @param string      $mechanism    How the caller authenticated: `app-password`, `jwt` or `api-key`.
	 * @param string|null $reference    The token's stable identifier, never its value.
	 * @param string|null $name         The label the token carries, as its owner wrote it.
	 * @param string|null $ownerUid     The uid of the principal the token belongs to.
	 * @param string|null $ownerName    That principal's display name.
	 * @param string|null $consumerUuid The registered consumer's uuid.
	 * @param string|null $consumerName The registered consumer's name.
	 */
	public function __construct(
		private readonly string $mechanism,
		private readonly ?string $reference = null,
		private readonly ?string $name = null,
		private readonly ?string $ownerUid = null,
		private readonly ?string $ownerName = null,
		private readonly ?string $consumerUuid = null,
		private readonly ?string $consumerName = null,
	) {
	}//end __construct()

	/**
	 * How the caller authenticated.
	 *
	 * @return string The mechanism name.
	 *
	 * @spec openspec/changes/audit-trail-shipped-and-purpose-bound/specs/enhanced-audit-trail/spec.md
	 */
	public function mechanism(): string {
		return $this->mechanism;
	}//end mechanism()

	/**
	 * The token's stable identifier.
	 *
	 * @return string|null The reference, or null when the mechanism has none.
	 *
	 * @spec openspec/changes/audit-trail-shipped-and-purpose-bound/specs/enhanced-audit-trail/spec.md
	 */
	public function reference(): ?string {
		return $this->reference;
	}//end reference()

	/**
	 * The label the token carries.
	 *
	 * @return string|null The name, or null when it has none.
	 *
	 * @spec openspec/changes/audit-trail-shipped-and-purpose-bound/specs/enhanced-audit-trail/spec.md
	 */
	public function name(): ?string {
		return $this->name;
	}//end name()

	/**
	 * The uid of the principal the token belongs to.
	 *
	 * @return string|null The owner's uid, or null when unresolved.
	 *
	 * @spec openspec/changes/audit-trail-shipped-and-purpose-bound/specs/enhanced-audit-trail/spec.md
	 */
	public function ownerUid(): ?string {
		return $this->ownerUid;
	}//end ownerUid()

	/**
	 * The display name of the principal the token belongs to.
	 *
	 * @return string|null The owner's display name, or null when unresolved.
	 *
	 * @spec openspec/changes/audit-trail-shipped-and-purpose-bound/specs/enhanced-audit-trail/spec.md
	 */
	public function ownerName(): ?string {
		return $this->ownerName;
	}//end ownerName()

	/**
	 * The registered consumer's uuid.
	 *
	 * @return string|null The consumer uuid, or null when the token belongs to no consumer.
	 *
	 * @spec openspec/changes/audit-trail-shipped-and-purpose-bound/specs/enhanced-audit-trail/spec.md
	 */
	public function consumerUuid(): ?string {
		return $this->consumerUuid;
	}//end consumerUuid()

	/**
	 * The registered consumer's name.
	 *
	 * This is the value projected onto the audit row's indexed `consumer`
	 * column, because "which koppeling wrote this field" is asked about a name
	 * rather than a uuid, and the answer has to survive the consumer record
	 * being deleted.
	 *
	 * @return string|null The consumer name, or null when the token belongs to no consumer.
	 *
	 * @spec openspec/changes/audit-trail-shipped-and-purpose-bound/specs/enhanced-audit-trail/spec.md
	 */
	public function consumerName(): ?string {
		return $this->consumerName;
	}//end consumerName()

	/**
	 * Whether this identity names anything worth writing down.
	 *
	 * An interactive browser session resolves to a mechanism and nothing else.
	 * Stamping that on a row would claim a token made the write when none did,
	 * which is worse than the row saying nothing: the whole point of the field
	 * is that its presence means a machine wrote this.
	 *
	 * @return bool True when at least the token or the consumer is known.
	 *
	 * @spec openspec/changes/audit-trail-shipped-and-purpose-bound/specs/enhanced-audit-trail/spec.md
	 */
	public function isAttributable(): bool {
		$hasToken = ($this->reference !== null && $this->reference !== '');
		$hasConsumer = ($this->consumerName !== null && $this->consumerName !== '');

		return ($hasToken === true || $hasConsumer === true);
	}//end isAttributable()

	/**
	 * The sealed form written into the audit row's result summary.
	 *
	 * @return array<string, string|null> The token, its owner and its consumer.
	 *
	 * @spec openspec/changes/audit-trail-shipped-and-purpose-bound/specs/enhanced-audit-trail/spec.md
	 */
	public function toArray(): array {
		return [
			'mechanism' => $this->mechanism,
			'reference' => $this->reference,
			'name' => $this->name,
			'ownerUid' => $this->ownerUid,
			'ownerName' => $this->ownerName,
			'consumerUuid' => $this->consumerUuid,
			'consumer' => $this->consumerName,
		];
	}//end toArray()
}//end class
