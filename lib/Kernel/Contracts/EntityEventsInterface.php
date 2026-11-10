<?php

/**
 * EntityEventsInterface: the entity events the mappers announce.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Contract
 * @package  OCA\OpenRegister\Kernel\Contracts
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts;

use OCP\AppFramework\Db\Entity;

/**
 * Announces that an entity was created, updated, deleted or became terminal.
 *
 * The mappers in lib/Db call this after a write instead of naming an event class, so the
 * persistence layer depends on the Kernel only (ADR-013 Rule 1). The implementation lives in
 * Core (EntityEventPublisher) and dispatches the lib/Event classes, so a listener, and another
 * app that writes through a mapper, sees exactly the events it saw before.
 *
 * Non-core entities are typed as the OCP Entity; the implementation refuses an entity of the
 * wrong class, which only a programming error can pass.
 *
 * @spec openspec/specs/event-driven-architecture/spec.md#requirement-all-entity-mutations-must-dispatch-typed-php-events-via-ieventdispatcher
 */
interface EntityEventsInterface {

	/**
	 * An application was created.
	 *
	 * @param Entity $application The stored application.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md#requirement-all-entity-mutations-must-dispatch-typed-php-events-via-ieventdispatcher
	 */
	public function applicationCreated(Entity $application): void;

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
	public function applicationUpdated(Entity $newApplication, Entity $oldApplication): void;

	/**
	 * An application was deleted.
	 *
	 * @param Entity $application The deleted application.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md#requirement-all-entity-mutations-must-dispatch-typed-php-events-via-ieventdispatcher
	 */
	public function applicationDeleted(Entity $application): void;

	/**
	 * A configuration was created.
	 *
	 * @param Entity $configuration The stored configuration.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md#requirement-all-entity-mutations-must-dispatch-typed-php-events-via-ieventdispatcher
	 */
	public function configurationCreated(Entity $configuration): void;

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
	public function configurationUpdated(Entity $newConfiguration, Entity $oldConfiguration): void;

	/**
	 * A configuration was deleted.
	 *
	 * @param Entity $configuration The deleted configuration.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md#requirement-all-entity-mutations-must-dispatch-typed-php-events-via-ieventdispatcher
	 */
	public function configurationDeleted(Entity $configuration): void;

	/**
	 * An organisation was created.
	 *
	 * @param Entity $organisation The stored organisation.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md#requirement-all-entity-mutations-must-dispatch-typed-php-events-via-ieventdispatcher
	 */
	public function organisationCreated(Entity $organisation): void;

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
	public function organisationUpdated(Entity $newOrganisation, Entity $oldOrganisation): void;

	/**
	 * An organisation was deleted.
	 *
	 * @param Entity $organisation The deleted organisation.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md#requirement-all-entity-mutations-must-dispatch-typed-php-events-via-ieventdispatcher
	 */
	public function organisationDeleted(Entity $organisation): void;

	/**
	 * A register was created.
	 *
	 * @param RegisterInterface $register The stored register.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md#requirement-all-entity-mutations-must-dispatch-typed-php-events-via-ieventdispatcher
	 */
	public function registerCreated(RegisterInterface $register): void;

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
	public function registerUpdated(RegisterInterface $newRegister, RegisterInterface $oldRegister): void;

	/**
	 * A register was deleted.
	 *
	 * @param RegisterInterface $register The deleted register.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md#requirement-all-entity-mutations-must-dispatch-typed-php-events-via-ieventdispatcher
	 */
	public function registerDeleted(RegisterInterface $register): void;

	/**
	 * A schema was created.
	 *
	 * @param SchemaInterface $schema The stored schema.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md#requirement-all-entity-mutations-must-dispatch-typed-php-events-via-ieventdispatcher
	 */
	public function schemaCreated(SchemaInterface $schema): void;

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
	public function schemaUpdated(SchemaInterface $newSchema, SchemaInterface $oldSchema): void;

	/**
	 * A schema was deleted.
	 *
	 * @param SchemaInterface $schema The deleted schema.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md#requirement-all-entity-mutations-must-dispatch-typed-php-events-via-ieventdispatcher
	 */
	public function schemaDeleted(SchemaInterface $schema): void;

	/**
	 * A source was created.
	 *
	 * @param Entity $source The stored source.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md#requirement-all-entity-mutations-must-dispatch-typed-php-events-via-ieventdispatcher
	 */
	public function sourceCreated(Entity $source): void;

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
	public function sourceUpdated(Entity $newSource, Entity $oldSource): void;

	/**
	 * A source was deleted.
	 *
	 * @param Entity $source The deleted source.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md#requirement-all-entity-mutations-must-dispatch-typed-php-events-via-ieventdispatcher
	 */
	public function sourceDeleted(Entity $source): void;

	/**
	 * The decision metadata of an entity relation was updated.
	 *
	 * @param RelationDecisionUpdate $update The relation as stored now, only the fields that changed,
	 *                                       and the user who decided (null for a system write).
	 *
	 * @return void
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md#requirement-all-entity-mutations-must-dispatch-typed-php-events-via-ieventdispatcher
	 */
	public function entityRelationDecisionUpdated(RelationDecisionUpdate $update): void;

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
	public function flowRunTerminal(string $runUuid, string $status): void;

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
	public function taskTerminal(Entity $task, bool $committed): void;
}//end interface
