<?php

/**
 * An update seen by the lifecycle listeners: the stored object, the incoming one and what the schema declares.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Listener
 * @package  OCA\OpenRegister\Listener
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

namespace OCA\OpenRegister\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;

/**
 * One update of an object whose schema declares a lifecycle.
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
final class LifecycleEdit {
	/**
	 * Constructor.
	 *
	 * @param ObjectUpdatingEvent  $event       The update.
	 * @param ObjectEntity         $stored      The object as stored before this write.
	 * @param LifecycleDeclaration $declaration What the object's schema declares.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function __construct(
		private readonly ObjectUpdatingEvent $event,
		public readonly ObjectEntity $stored,
		public readonly LifecycleDeclaration $declaration,
	) {
	}//end __construct()

	/**
	 * The incoming object.
	 *
	 * @return ObjectEntity
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function object(): ObjectEntity {
		return $this->event->getNewObject();
	}//end object()

	/**
	 * The incoming object's uuid.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function uuid(): string {
		return (string)$this->object()->getUuid();
	}//end uuid()

	/**
	 * The stored data.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function getOldData(): array {
		return ($this->stored->getObject() ?? []);
	}//end getOldData()

	/**
	 * The incoming data.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function getNewData(): array {
		return ($this->object()->getObject() ?? []);
	}//end getNewData()

	/**
	 * The stored lifecycle value.
	 *
	 * @return mixed
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function getOldValue(): mixed {
		return ($this->getOldData()[$this->declaration->field()] ?? null);
	}//end getOldValue()

	/**
	 * The incoming lifecycle value.
	 *
	 * @return mixed
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function getNewValue(): mixed {
		return ($this->getNewData()[$this->declaration->field()] ?? null);
	}//end getNewValue()

	/**
	 * The stored lifecycle value as text.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function getFromState(): string {
		return (string)$this->getOldValue();
	}//end getFromState()

	/**
	 * The incoming lifecycle value as text.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function getToState(): string {
		return (string)$this->getNewValue();
	}//end getToState()

	/**
	 * Whether this write changes the lifecycle value.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function changesState(): bool {
		return $this->getOldValue() !== $this->getNewValue();
	}//end changesState()

	/**
	 * Whether this write changes the lifecycle value to a text value.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function changesStateToText(): bool {
		return $this->changesState() === true && is_string($this->getNewValue()) === true;
	}//end changesStateToText()

	/**
	 * Whether the incoming lifecycle value is a non-empty string.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function hasTextState(): bool {
		return is_string($this->getNewValue()) === true && $this->getNewValue() !== '';
	}//end hasTextState()

	/**
	 * Stop the update and stamp a structured error onto it.
	 *
	 * @param array<string, mixed> $error Structured error payload.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function refuse(array $error): void {
		$this->event->setErrors($error);
		$this->event->stopPropagation();
	}//end refuse()
}//end class
