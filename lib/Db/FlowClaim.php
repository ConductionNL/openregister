<?php

/**
 * A claim on one PLACE of one run's marking, held by one worker pass.
 *
 * The row IS the lock: `(run_uuid, place)` is unique, so acquiring a claim is
 * an INSERT that either lands or is refused by the index. Nothing waits on a
 * claim, ever — a refusal is returned immediately and the candidate firing is
 * abandoned for this pass. The other columns exist for the reaper: `owner`
 * names the pass that took the claim, `stream_id` and `transition` say what it
 * was taken FOR, so an abandoned claim can be failed with a message a person
 * can act on.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-parallel-streams/specs/flow-parallel-streams/spec.md#requirement-a-firing-must-exclusively-claim-every-place-it-touches
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use DateTime;
use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * One place claim.
 *
 * @method string|null getRunUuid()
 * @method void setRunUuid(?string $runUuid)
 * @method string|null getPlace()
 * @method void setPlace(?string $place)
 * @method string|null getOwner()
 * @method void setOwner(?string $owner)
 * @method string|null getStreamId()
 * @method void setStreamId(?string $streamId)
 * @method string|null getTransition()
 * @method void setTransition(?string $transition)
 * @method DateTime|null getClaimedAt()
 * @method void setClaimedAt(?DateTime $claimedAt)
 */
class FlowClaim extends Entity implements JsonSerializable {

	/**
	 * The run whose marking holds the place.
	 *
	 * @var string|null
	 */
	protected ?string $runUuid = null;

	/**
	 * The claimed place name.
	 *
	 * @var string|null
	 */
	protected ?string $place = null;

	/**
	 * The pass token of the holder.
	 *
	 * @var string|null
	 */
	protected ?string $owner = null;

	/**
	 * The stream the claim was taken for.
	 *
	 * @var string|null
	 */
	protected ?string $streamId = null;

	/**
	 * The transition the claim was taken for.
	 *
	 * @var string|null
	 */
	protected ?string $transition = null;

	/**
	 * When the claim was taken — the reaper's input.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $claimedAt = null;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->addType(fieldName: 'runUuid', type: 'string');
		$this->addType(fieldName: 'place', type: 'string');
		$this->addType(fieldName: 'owner', type: 'string');
		$this->addType(fieldName: 'streamId', type: 'string');
		$this->addType(fieldName: 'transition', type: 'string');
		$this->addType(fieldName: 'claimedAt', type: 'datetime');
	}//end __construct()

	/**
	 * JSON shape.
	 *
	 * @return array<string, mixed> The claim as an array.
	 */
	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'runUuid' => $this->runUuid,
			'place' => $this->place,
			'owner' => $this->owner,
			'streamId' => $this->streamId,
			'transition' => $this->transition,
			'claimedAt' => $this->claimedAt?->format('c'),
		];
	}//end jsonSerialize()
}//end class
