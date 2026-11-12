<?php

/**
 * A create seen by the lifecycle initial-state listener.
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
use OCA\OpenRegister\Event\ObjectCreatingEvent;

/**
 * One create of an object whose schema declares a lifecycle.
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
final class LifecycleCreate {
	/**
	 * Constructor.
	 *
	 * @param ObjectCreatingEvent  $event       The create.
	 * @param LifecycleDeclaration $declaration What the object's schema declares.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function __construct(
		private readonly ObjectCreatingEvent $event,
		public readonly LifecycleDeclaration $declaration,
	) {
	}//end __construct()

	/**
	 * The object being created.
	 *
	 * @return ObjectEntity
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function object(): ObjectEntity {
		return $this->event->getObject();
	}//end object()

	/**
	 * The object's data.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function data(): array {
		return ($this->object()->getObject() ?? []);
	}//end data()

	/**
	 * Whether the schema names a lifecycle field and an initial state.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function declaresInitial(): bool {
		return $this->declaration->field() !== '' && array_key_exists('initial', $this->declaration->annotation) === true;
	}//end declaresInitial()

	/**
	 * The value the caller set on the lifecycle field.
	 *
	 * @return mixed
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function chosen(): mixed {
		return ($this->data()[$this->declaration->field()] ?? null);
	}//end chosen()

	/**
	 * Whether the caller set a value on the lifecycle field. An empty string is no value.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function hasChosen(): bool {
		return $this->chosen() !== null && $this->chosen() !== '';
	}//end hasChosen()

	/**
	 * Fill the lifecycle field. An initial state that did not resolve fills nothing.
	 *
	 * @param string|null $initial The resolved initial state.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function fill(?string $initial): void {
		if ($initial === null || $initial === '') {
			return;
		}

		$data = $this->data();
		$data[$this->declaration->field()] = $initial;
		$this->object()->setObject($data);
	}//end fill()

	/**
	 * Stop the create and stamp a structured error onto it.
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
