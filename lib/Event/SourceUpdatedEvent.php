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

use OCA\OpenRegister\Kernel\Contracts\SourceReadInterface;
use OCP\AppFramework\Db\Entity;
use OCP\EventDispatcher\Event;

/**
 * Event dispatched when a source is updated.
 */
class SourceUpdatedEvent extends Event {

	/**
	 * The updated source state.
	 *
	 * @var SourceReadInterface&Entity The source after update.
	 */
	private SourceReadInterface $newSource;

	/**
	 * The previous source state.
	 *
	 * @var SourceReadInterface&Entity The source before update.
	 */
	private SourceReadInterface $oldSource;

	/**
	 * Constructor for SourceUpdatedEvent.
	 *
	 * @param SourceReadInterface&Entity $newSource The source after update.
	 * @param SourceReadInterface&Entity $oldSource The source before update.
	 *
	 * @return void
	 */
	public function __construct(SourceReadInterface $newSource, SourceReadInterface $oldSource) {
		parent::__construct();
		$this->newSource = $newSource;
		$this->oldSource = $oldSource;
	}//end __construct()

	/**
	 * Get the updated source.
	 *
	 * @return SourceReadInterface&Entity The source after update.
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md#requirement-event-payloads-for-webhook-delivery-must-include-register-and-schema-context-for-object-events
	 */
	public function getSource(): SourceReadInterface {
		return $this->newSource;
	}//end getSource()

	/**
	 * Get the new source state.
	 *
	 * @return SourceReadInterface&Entity The source after update.
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md#requirement-event-payloads-for-webhook-delivery-must-include-register-and-schema-context-for-object-events
	 */
	public function getNewSource(): SourceReadInterface {
		return $this->newSource;
	}//end getNewSource()

	/**
	 * Get the old source state.
	 *
	 * @return SourceReadInterface&Entity The source before update.
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md#requirement-event-payloads-for-webhook-delivery-must-include-register-and-schema-context-for-object-events
	 */
	public function getOldSource(): SourceReadInterface {
		return $this->oldSource;
	}//end getOldSource()
}//end class
