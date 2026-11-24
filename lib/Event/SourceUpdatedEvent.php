<?php

/**
 * OpenRegister SourceUpdatedEvent
 *
 * This file contains the event class dispatched when a source is updated
 * in the OpenRegister application.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Event
 * @package  OCA\OpenRegister\Event
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

namespace OCA\OpenRegister\Event;

use OCA\OpenRegister\Kernel\Contracts\SourceInterface;
use OCP\AppFramework\Db\Entity;
use OCP\EventDispatcher\Event;

/**
 * Event dispatched when a source is updated.
 */
class SourceUpdatedEvent extends Event {

	/**
	 * The updated source state.
	 *
	 * @var SourceInterface&Entity The source after update.
	 */
	private SourceInterface $newSource;

	/**
	 * The previous source state.
	 *
	 * @var SourceInterface&Entity The source before update.
	 */
	private SourceInterface $oldSource;

	/**
	 * Constructor for SourceUpdatedEvent.
	 *
	 * @param SourceInterface&Entity $newSource The source after update.
	 * @param SourceInterface&Entity $oldSource The source before update.
	 *
	 * @return void
	 */
	public function __construct(SourceInterface $newSource, SourceInterface $oldSource) {
		parent::__construct();
		$this->newSource = $newSource;
		$this->oldSource = $oldSource;
	}//end __construct()

	/**
	 * Get the updated source.
	 *
	 * @return SourceInterface&Entity The source after update.
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md#requirement-event-payloads-for-webhook-delivery-must-include-register-and-schema-context-for-object-events
	 */
	public function getSource(): SourceInterface {
		return $this->newSource;
	}//end getSource()

	/**
	 * Get the new source state.
	 *
	 * @return SourceInterface&Entity The source after update.
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md#requirement-event-payloads-for-webhook-delivery-must-include-register-and-schema-context-for-object-events
	 */
	public function getNewSource(): SourceInterface {
		return $this->newSource;
	}//end getNewSource()

	/**
	 * Get the old source state.
	 *
	 * @return SourceInterface&Entity The source before update.
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md#requirement-event-payloads-for-webhook-delivery-must-include-register-and-schema-context-for-object-events
	 */
	public function getOldSource(): SourceInterface {
		return $this->oldSource;
	}//end getOldSource()
}//end class
