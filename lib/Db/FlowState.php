<?php

/**
 * OpenRegister FlowState entity.
 *
 * State a flow keeps BETWEEN runs, as opposed to `FlowRun`'s marking/items/
 * context, which are per-run and discarded when the run ends. See OR#2216.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Database
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use DateTime;
use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * One flow's persistent state.
 *
 * A scheduled flow starts blank on every tick — the flow object holds its
 * definition, and a run's marking/items/context die with the run. This is where
 * a counter, a cursor, a capacity table or a "what have I already processed"
 * watermark lives so the next tick can read it.
 *
 * Keyed by flow uuid and stored BESIDE the flow, never on it: writing state
 * onto the flow object would churn the definition's audit history and race with
 * an operator editing it. `FlowScheduleService` already made that choice for
 * the last-fire timestamp; this generalises it.
 *
 * @method string|null getFlowId()
 * @method void setFlowId(?string $flowId)
 * @method array|null getState()
 * @method void setState(?array $state)
 * @method DateTime|null getUpdated()
 * @method void setUpdated(?DateTime $updated)
 *
 * @category Database
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */
class FlowState extends Entity implements JsonSerializable {

	/**
	 * The uuid of the flow this state belongs to.
	 *
	 * @var string|null
	 */
	protected ?string $flowId = null;

	/**
	 * The state itself, as a free-form map.
	 *
	 * @var array|null
	 */
	protected ?array $state = null;

	/**
	 * When the state was last written.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $updated = null;

	/**
	 * Register field types.
	 *
	 * @return void
	 */
	public function __construct() {
		$this->addType(fieldName: 'flowId', type: 'string');
		$this->addType(fieldName: 'state', type: 'json');
		$this->addType(fieldName: 'updated', type: 'datetime');

	}//end __construct()

	/**
	 * Serialise for API output.
	 *
	 * @return array The serialised state.
	 */
	public function jsonSerialize(): array {
		$updated = null;
		if ($this->updated !== null) {
			$updated = $this->updated->format('c');
		}

		return [
			'id' => $this->id,
			'flowId' => $this->flowId,
			'state' => ($this->state ?? []),
			'updated' => $updated,
		];

	}//end jsonSerialize()
}//end class
