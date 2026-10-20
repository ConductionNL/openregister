<?php

/**
 * OpenRegister Decision
 *
 * A decision as its own object: a verdict on one subject, who decided it,
 * when, and a note. The subject links to it; the decision points back at its
 * subject so every decision ever taken on it can be read in order.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Database
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/reviewer-owns-their-decisions/specs/decisions/spec.md#requirement-a-decision-is-its-own-object-req-dec-001
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use DateTime;
use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * One decision on one subject.
 *
 * A decision is never edited. Changing a verdict records a new decision that
 * names the one it supersedes, and the subject links to the new one. So the
 * person who decided is a fact, not a field someone else can overwrite.
 *
 * @method string|null getUuid()
 * @method void setUuid(?string $uuid)
 * @method string|null getSubjectType()
 * @method void setSubjectType(?string $subjectType)
 * @method string|null getSubjectId()
 * @method void setSubjectId(?string $subjectId)
 * @method string|null getVerdict()
 * @method void setVerdict(?string $verdict)
 * @method string|null getDecidedBy()
 * @method void setDecidedBy(?string $decidedBy)
 * @method DateTime|null getDecidedAt()
 * @method void setDecidedAt(?DateTime $decidedAt)
 * @method string|null getNote()
 * @method void setNote(?string $note)
 * @method string|null getSupersedes()
 * @method void setSupersedes(?string $supersedes)
 *
 * @spec openspec/changes/reviewer-owns-their-decisions/specs/decisions/spec.md#requirement-a-decision-is-its-own-object-req-dec-001
 */
class Decision extends Entity implements JsonSerializable {

	/**
	 * The decision's own uuid, by which subjects link to it.
	 *
	 * @var string|null
	 */
	protected ?string $uuid = null;

	/**
	 * What kind of thing was decided on, for example `entity-relation` or `object`.
	 *
	 * @var string|null
	 */
	protected ?string $subjectType = null;

	/**
	 * The id or uuid of the thing decided on, within its type.
	 *
	 * @var string|null
	 */
	protected ?string $subjectId = null;

	/**
	 * The verdict, in the subject type's own words (`redact`, `release`, ...).
	 *
	 * @var string|null
	 */
	protected ?string $verdict = null;

	/**
	 * The user who decided.
	 *
	 * @var string|null
	 */
	protected ?string $decidedBy = null;

	/**
	 * When it was decided.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $decidedAt = null;

	/**
	 * The decider's note on the decision.
	 *
	 * @var string|null
	 */
	protected ?string $note = null;

	/**
	 * The uuid of the decision this one replaces, if any.
	 *
	 * @var string|null
	 */
	protected ?string $supersedes = null;

	/**
	 * Declare the column types.
	 *
	 * @spec openspec/changes/reviewer-owns-their-decisions/specs/decisions/spec.md#requirement-a-decision-is-its-own-object-req-dec-001
	 */
	public function __construct() {
		$this->addType(fieldName: 'uuid', type: 'string');
		$this->addType(fieldName: 'subjectType', type: 'string');
		$this->addType(fieldName: 'subjectId', type: 'string');
		$this->addType(fieldName: 'verdict', type: 'string');
		$this->addType(fieldName: 'decidedBy', type: 'string');
		$this->addType(fieldName: 'decidedAt', type: 'datetime');
		$this->addType(fieldName: 'note', type: 'string');
		$this->addType(fieldName: 'supersedes', type: 'string');
	}//end __construct()

	/**
	 * The decision as the API answers it.
	 *
	 * @return array{uuid: string|null, subjectType: string|null, subjectId: string|null,
	 *     verdict: string|null, decidedBy: string|null, decidedAt: string|null,
	 *     note: string|null, supersedes: string|null}
	 *
	 * @spec openspec/changes/reviewer-owns-their-decisions/specs/decisions/spec.md#requirement-a-decision-is-its-own-object-req-dec-001
	 */
	public function jsonSerialize(): array {
		return [
			'uuid' => $this->uuid,
			'subjectType' => $this->subjectType,
			'subjectId' => $this->subjectId,
			'verdict' => $this->verdict,
			'decidedBy' => $this->decidedBy,
			'decidedAt' => $this->decidedAt?->format(DateTime::ATOM),
			'note' => $this->note,
			'supersedes' => $this->supersedes,
		];
	}//end jsonSerialize()
}//end class
