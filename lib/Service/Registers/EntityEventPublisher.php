<?php

/**
 * EntityEventPublisher: dispatches the entity events the mappers announce.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Registers
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Registers;

use InvalidArgumentException;
use OCA\OpenRegister\Db\Application;
use OCA\OpenRegister\Db\Configuration;
use OCA\OpenRegister\Db\EntityRelation;
use OCA\OpenRegister\Db\Organisation;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\Source;
use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Event\ApplicationCreatedEvent;
use OCA\OpenRegister\Event\ApplicationDeletedEvent;
use OCA\OpenRegister\Event\ApplicationUpdatedEvent;
use OCA\OpenRegister\Event\ConfigurationCreatedEvent;
use OCA\OpenRegister\Event\ConfigurationDeletedEvent;
use OCA\OpenRegister\Event\ConfigurationUpdatedEvent;
use OCA\OpenRegister\Event\EntityRelationDecisionUpdatedEvent;
use OCA\OpenRegister\Event\FlowRunTerminalEvent;
use OCA\OpenRegister\Event\OrganisationCreatedEvent;
use OCA\OpenRegister\Event\OrganisationDeletedEvent;
use OCA\OpenRegister\Event\OrganisationUpdatedEvent;
use OCA\OpenRegister\Event\RegisterCreatedEvent;
use OCA\OpenRegister\Event\RegisterDeletedEvent;
use OCA\OpenRegister\Event\RegisterUpdatedEvent;
use OCA\OpenRegister\Event\SchemaCreatedEvent;
use OCA\OpenRegister\Event\SchemaDeletedEvent;
use OCA\OpenRegister\Event\SchemaUpdatedEvent;
use OCA\OpenRegister\Event\SourceCreatedEvent;
use OCA\OpenRegister\Event\SourceDeletedEvent;
use OCA\OpenRegister\Event\SourceUpdatedEvent;
use OCA\OpenRegister\Event\TaskTerminalEvent;
use OCA\OpenRegister\Kernel\Contracts\EntityEventsInterface;
use OCA\OpenRegister\Kernel\Contracts\RegisterInterface;
use OCA\OpenRegister\Kernel\Contracts\SchemaInterface;
use OCP\AppFramework\Db\Entity;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IUser;

/**
 * Turns each announcement of a mapper into the lib/Event class it dispatched itself before.
 *
 * One event per call, built with the same arguments the mapper passed, so listeners see the
 * same class, order and payload (ADR-013 Rule 4).
 *
 * @spec openspec/specs/event-driven-architecture/spec.md#requirement-all-entity-mutations-must-dispatch-typed-php-events-via-ieventdispatcher
 */
final class EntityEventPublisher implements EntityEventsInterface {

	/**
	 * Constructor.
	 *
	 * @param IEventDispatcher $dispatcher The dispatcher the events go to.
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md#requirement-all-entity-mutations-must-dispatch-typed-php-events-via-ieventdispatcher
	 */
	public function __construct(
		private readonly IEventDispatcher $dispatcher,
	) {
	}//end __construct()

	/**
	 * An application was created.
	 *
	 * @param Entity $application The stored application.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md#requirement-all-entity-mutations-must-dispatch-typed-php-events-via-ieventdispatcher
	 */
	public function applicationCreated(Entity $application): void {
		$this->dispatcher->dispatchTyped(new ApplicationCreatedEvent(application: self::narrow(entity: $application, class: Application::class)));
	}//end applicationCreated()

	/**
	 * An application was updated.
	 *
	 * @param Entity $newApplication The application as stored now.
	 * @param Entity $oldApplication The application as it was before the write.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md#requirement-all-entity-mutations-must-dispatch-typed-php-events-via-ieventdispatcher
	 */
	public function applicationUpdated(Entity $newApplication, Entity $oldApplication): void {
		$this->dispatcher->dispatchTyped(
			new ApplicationUpdatedEvent(
				newApplication: self::narrow(entity: $newApplication, class: Application::class),
				oldApplication: self::narrow(entity: $oldApplication, class: Application::class)
			)
		);
	}//end applicationUpdated()

	/**
	 * An application was deleted.
	 *
	 * @param Entity $application The deleted application.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md#requirement-all-entity-mutations-must-dispatch-typed-php-events-via-ieventdispatcher
	 */
	public function applicationDeleted(Entity $application): void {
		$this->dispatcher->dispatchTyped(new ApplicationDeletedEvent(application: self::narrow(entity: $application, class: Application::class)));
	}//end applicationDeleted()

	/**
	 * A configuration was created.
	 *
	 * @param Entity $configuration The stored configuration.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md#requirement-all-entity-mutations-must-dispatch-typed-php-events-via-ieventdispatcher
	 */
	public function configurationCreated(Entity $configuration): void {
		$this->dispatcher->dispatchTyped(
			new ConfigurationCreatedEvent(configuration: self::narrow(entity: $configuration, class: Configuration::class))
		);
	}//end configurationCreated()

	/**
	 * A configuration was updated.
	 *
	 * @param Entity $newConfiguration The configuration as stored now.
	 * @param Entity $oldConfiguration The configuration as it was before the write.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md#requirement-all-entity-mutations-must-dispatch-typed-php-events-via-ieventdispatcher
	 */
	public function configurationUpdated(Entity $newConfiguration, Entity $oldConfiguration): void {
		$this->dispatcher->dispatchTyped(
			new ConfigurationUpdatedEvent(
				newConfiguration: self::narrow(entity: $newConfiguration, class: Configuration::class),
				oldConfiguration: self::narrow(entity: $oldConfiguration, class: Configuration::class)
			)
		);
	}//end configurationUpdated()

	/**
	 * A configuration was deleted.
	 *
	 * @param Entity $configuration The deleted configuration.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md#requirement-all-entity-mutations-must-dispatch-typed-php-events-via-ieventdispatcher
	 */
	public function configurationDeleted(Entity $configuration): void {
		$this->dispatcher->dispatchTyped(
			new ConfigurationDeletedEvent(configuration: self::narrow(entity: $configuration, class: Configuration::class))
		);
	}//end configurationDeleted()

	/**
	 * An organisation was created.
	 *
	 * @param Entity $organisation The stored organisation.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md#requirement-all-entity-mutations-must-dispatch-typed-php-events-via-ieventdispatcher
	 */
	public function organisationCreated(Entity $organisation): void {
		$this->dispatcher->dispatchTyped(
			new OrganisationCreatedEvent(organisation: self::narrow(entity: $organisation, class: Organisation::class))
		);
	}//end organisationCreated()

	/**
	 * An organisation was updated.
	 *
	 * @param Entity $newOrganisation The organisation as stored now.
	 * @param Entity $oldOrganisation The organisation as it was before the write.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md#requirement-all-entity-mutations-must-dispatch-typed-php-events-via-ieventdispatcher
	 */
	public function organisationUpdated(Entity $newOrganisation, Entity $oldOrganisation): void {
		$this->dispatcher->dispatchTyped(
			new OrganisationUpdatedEvent(
				newOrganisation: self::narrow(entity: $newOrganisation, class: Organisation::class),
				oldOrganisation: self::narrow(entity: $oldOrganisation, class: Organisation::class)
			)
		);
	}//end organisationUpdated()

	/**
	 * An organisation was deleted.
	 *
	 * @param Entity $organisation The deleted organisation.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md#requirement-all-entity-mutations-must-dispatch-typed-php-events-via-ieventdispatcher
	 */
	public function organisationDeleted(Entity $organisation): void {
		$this->dispatcher->dispatchTyped(
			new OrganisationDeletedEvent(organisation: self::narrow(entity: $organisation, class: Organisation::class))
		);
	}//end organisationDeleted()

	/**
	 * A register was created.
	 *
	 * @param RegisterInterface $register The stored register.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md#requirement-all-entity-mutations-must-dispatch-typed-php-events-via-ieventdispatcher
	 */
	public function registerCreated(RegisterInterface $register): void {
		$this->dispatcher->dispatchTyped(new RegisterCreatedEvent(register: self::narrow(entity: $register, class: Register::class)));
	}//end registerCreated()

	/**
	 * A register was updated.
	 *
	 * @param RegisterInterface $newRegister The register as stored now.
	 * @param RegisterInterface $oldRegister The register as it was before the write.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md#requirement-all-entity-mutations-must-dispatch-typed-php-events-via-ieventdispatcher
	 */
	public function registerUpdated(RegisterInterface $newRegister, RegisterInterface $oldRegister): void {
		$this->dispatcher->dispatchTyped(
			new RegisterUpdatedEvent(
				newRegister: self::narrow(entity: $newRegister, class: Register::class),
				oldRegister: self::narrow(entity: $oldRegister, class: Register::class)
			)
		);
	}//end registerUpdated()

	/**
	 * A register was deleted.
	 *
	 * @param RegisterInterface $register The deleted register.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md#requirement-all-entity-mutations-must-dispatch-typed-php-events-via-ieventdispatcher
	 */
	public function registerDeleted(RegisterInterface $register): void {
		$this->dispatcher->dispatchTyped(new RegisterDeletedEvent(register: self::narrow(entity: $register, class: Register::class)));
	}//end registerDeleted()

	/**
	 * A schema was created.
	 *
	 * @param SchemaInterface $schema The stored schema.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md#requirement-all-entity-mutations-must-dispatch-typed-php-events-via-ieventdispatcher
	 */
	public function schemaCreated(SchemaInterface $schema): void {
		$this->dispatcher->dispatchTyped(new SchemaCreatedEvent(schema: self::narrow(entity: $schema, class: Schema::class)));
	}//end schemaCreated()

	/**
	 * A schema was updated.
	 *
	 * @param SchemaInterface $newSchema The schema as stored now.
	 * @param SchemaInterface $oldSchema The schema as it was before the write.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md#requirement-all-entity-mutations-must-dispatch-typed-php-events-via-ieventdispatcher
	 */
	public function schemaUpdated(SchemaInterface $newSchema, SchemaInterface $oldSchema): void {
		$this->dispatcher->dispatchTyped(
			new SchemaUpdatedEvent(
				newSchema: self::narrow(entity: $newSchema, class: Schema::class),
				oldSchema: self::narrow(entity: $oldSchema, class: Schema::class)
			)
		);
	}//end schemaUpdated()

	/**
	 * A schema was deleted.
	 *
	 * @param SchemaInterface $schema The deleted schema.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md#requirement-all-entity-mutations-must-dispatch-typed-php-events-via-ieventdispatcher
	 */
	public function schemaDeleted(SchemaInterface $schema): void {
		$this->dispatcher->dispatchTyped(new SchemaDeletedEvent(schema: self::narrow(entity: $schema, class: Schema::class)));
	}//end schemaDeleted()

	/**
	 * A source was created.
	 *
	 * @param Entity $source The stored source.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md#requirement-all-entity-mutations-must-dispatch-typed-php-events-via-ieventdispatcher
	 */
	public function sourceCreated(Entity $source): void {
		$this->dispatcher->dispatchTyped(new SourceCreatedEvent(source: self::narrow(entity: $source, class: Source::class)));
	}//end sourceCreated()

	/**
	 * A source was updated.
	 *
	 * @param Entity $newSource The source as stored now.
	 * @param Entity $oldSource The source as it was before the write.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md#requirement-all-entity-mutations-must-dispatch-typed-php-events-via-ieventdispatcher
	 */
	public function sourceUpdated(Entity $newSource, Entity $oldSource): void {
		$this->dispatcher->dispatchTyped(
			new SourceUpdatedEvent(
				newSource: self::narrow(entity: $newSource, class: Source::class),
				oldSource: self::narrow(entity: $oldSource, class: Source::class)
			)
		);
	}//end sourceUpdated()

	/**
	 * A source was deleted.
	 *
	 * @param Entity $source The deleted source.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md#requirement-all-entity-mutations-must-dispatch-typed-php-events-via-ieventdispatcher
	 */
	public function sourceDeleted(Entity $source): void {
		$this->dispatcher->dispatchTyped(new SourceDeletedEvent(source: self::narrow(entity: $source, class: Source::class)));
	}//end sourceDeleted()

	/**
	 * The decision metadata of an entity relation was updated.
	 *
	 * @param Entity $relation The relation as stored now.
	 * @param array<string, array{previous: mixed, new: mixed}> $changedFields Only the fields that changed.
	 * @param IUser|null $actingUser The user who decided; null for a system write.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md#requirement-all-entity-mutations-must-dispatch-typed-php-events-via-ieventdispatcher
	 */
	public function entityRelationDecisionUpdated(Entity $relation, array $changedFields, ?IUser $actingUser): void {
		$this->dispatcher->dispatchTyped(
			new EntityRelationDecisionUpdatedEvent(
				relation: self::narrow(entity: $relation, class: EntityRelation::class),
				changedFields: $changedFields,
				actingUser: $actingUser
			)
		);
	}//end entityRelationDecisionUpdated()

	/**
	 * A flow run reached a terminal status.
	 *
	 * @param string $runUuid The run's uuid.
	 * @param string $status The terminal status.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md#requirement-all-entity-mutations-must-dispatch-typed-php-events-via-ieventdispatcher
	 */
	public function flowRunTerminal(string $runUuid, string $status): void {
		$this->dispatcher->dispatchTyped(new FlowRunTerminalEvent(runUuid: $runUuid, status: $status));
	}//end flowRunTerminal()

	/**
	 * A task reached a terminal state.
	 *
	 * @param Entity $task The task as persisted.
	 * @param bool $committed Whether the write that made it terminal is already committed.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md#requirement-all-entity-mutations-must-dispatch-typed-php-events-via-ieventdispatcher
	 */
	public function taskTerminal(Entity $task, bool $committed): void {
		$this->dispatcher->dispatchTyped(new TaskTerminalEvent(task: self::narrow(entity: $task, class: Task::class), committed: $committed));
	}//end taskTerminal()

	/**
	 * The entity as the class its event carries, refused when a caller passed another class.
	 *
	 * @param object $entity The entity a mapper announced.
	 * @param class-string<T> $class The class the event carries.
	 *
	 * @return T
	 *
	 * @throws InvalidArgumentException When the entity is not of that class.
	 *
	 * @template T of object
	 */
	private static function narrow(object $entity, string $class): object {
		if ($entity instanceof $class === false) {
			throw new InvalidArgumentException('Expected ' . $class . ', got ' . get_class($entity) . '.');
		}

		return $entity;
	}//end narrow()
}//end class
