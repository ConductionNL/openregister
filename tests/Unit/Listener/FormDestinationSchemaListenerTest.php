<?php

/**
 * A schema write that is not a schema API request re-checks the forms that submit into it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-saving-a-schema-must-re-check-the-forms-that-submit-into-it
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Listener;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Event\SchemaUpdatedEvent;
use OCA\OpenRegister\Listener\FormDestinationSchemaListener;
use OCA\OpenRegister\Service\Form\FormDependentsCheck;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenRegister\Listener\FormDestinationSchemaListener
 * @uses \OCA\OpenRegister\Event\SchemaUpdatedEvent
 * @uses \OCA\OpenRegister\Db\Schema
 */
class FormDestinationSchemaListenerTest extends TestCase {

	private ContainerInterface&MockObject $container;

	private LoggerInterface&MockObject $logger;

	private FormDependentsCheck&MockObject $check;

	private FormDestinationSchemaListener $listener;

	protected function setUp(): void {
		parent::setUp();

		$this->check = $this->createMock(FormDependentsCheck::class);
		$this->container = $this->createMock(ContainerInterface::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->listener = new FormDestinationSchemaListener($this->container, $this->logger);
	}//end setUp()

	/**
	 * A schema.
	 *
	 * @param array<string, mixed> $properties The properties.
	 * @param array<int, string>   $required   The required list.
	 *
	 * @return Schema The schema.
	 */
	private function schema(array $properties, array $required = []): Schema {
		$schema = new Schema();
		$schema->setId(12);
		$schema->setSlug('aanvraag');
		$schema->setProperties($properties);
		$schema->setRequired($required);

		return $schema;
	}//end schema()

	/**
	 * The event of a write that adds a required property.
	 *
	 * @return SchemaUpdatedEvent The event.
	 */
	private function addsARequiredProperty(): SchemaUpdatedEvent {
		return new SchemaUpdatedEvent(
			newSchema: $this->schema(properties: ['naam' => ['type' => 'string'], 'bsn' => ['type' => 'string']], required: ['bsn']),
			oldSchema: $this->schema(properties: ['naam' => ['type' => 'string']])
		);
	}//end addsARequiredProperty()

	public function testAWriteThatChangedNeitherPropertiesNorRequiredNeverBuildsTheCheck(): void {
		$this->container->expects($this->never())->method('get');

		$properties = ['naam' => ['type' => 'string'], 'bsn' => ['type' => 'string']];
		$this->listener->handle(
			new SchemaUpdatedEvent(
				newSchema: $this->schema(properties: array_reverse($properties, true), required: ['bsn']),
				oldSchema: $this->schema(properties: $properties, required: ['bsn'])
			)
		);
	}//end testAWriteThatChangedNeitherPropertiesNorRequiredNeverBuildsTheCheck()

	public function testAnImportThatAddsARequiredPropertyUnpublishesTheBrokenForms(): void {
		$assessment = ['refused' => false, 'schema' => 'aanvraag', 'affected' => [['id' => 'form-1']]];
		$this->container->method('get')->with(FormDependentsCheck::class)->willReturn($this->check);
		$this->check->method('isClaimed')->willReturn(false);
		$this->check->expects($this->once())->method('assess')->willReturn($assessment);
		$this->check->expects($this->once())->method('apply')->with($assessment);

		$this->listener->handle($this->addsARequiredProperty());
	}//end testAnImportThatAddsARequiredPropertyUnpublishesTheBrokenForms()

	public function testARefuseSettingCannotBeHonouredWithoutAResponseAndSaysSo(): void {
		$this->container->method('get')->willReturn($this->check);
		$this->check->method('isClaimed')->willReturn(false);
		$this->check->method('assess')->willReturn(['refused' => true, 'schema' => 'aanvraag', 'affected' => [['id' => 'form-1']]]);
		$this->logger->expects($this->once())->method('warning')->with($this->stringContains('has no response to refuse'), $this->anything());
		$this->check->expects($this->once())->method('apply')->with(['refused' => false, 'schema' => 'aanvraag', 'affected' => [['id' => 'form-1']]]);

		$this->listener->handle($this->addsARequiredProperty());
	}//end testARefuseSettingCannotBeHonouredWithoutAResponseAndSaysSo()

	public function testASaveTheSchemaApiClaimedIsLeftToIt(): void {
		$this->container->method('get')->willReturn($this->check);
		$this->check->method('isClaimed')->with(12)->willReturn(true);
		$this->check->expects($this->never())->method('assess');
		$this->check->expects($this->never())->method('apply');

		$this->listener->handle($this->addsARequiredProperty());
	}//end testASaveTheSchemaApiClaimedIsLeftToIt()

	public function testNothingIsUnpublishedWhenNoFormIsBroken(): void {
		$this->container->method('get')->willReturn($this->check);
		$this->check->method('isClaimed')->willReturn(false);
		$this->check->method('assess')->willReturn(['refused' => false, 'schema' => 'aanvraag', 'affected' => []]);
		$this->check->expects($this->never())->method('apply');

		$this->listener->handle($this->addsARequiredProperty());
	}//end testNothingIsUnpublishedWhenNoFormIsBroken()

	public function testACheckThatCannotBeBuiltIsLoggedAndDoesNotUndoTheWrite(): void {
		$this->container->method('get')->willThrowException(new \RuntimeException('container failure'));
		$this->logger->expects($this->once())->method('warning')->with($this->stringContains('could not be re-checked'), $this->anything());

		$this->listener->handle($this->addsARequiredProperty());
	}//end testACheckThatCannotBeBuiltIsLoggedAndDoesNotUndoTheWrite()

	public function testAnotherEventIsIgnored(): void {
		$this->container->expects($this->never())->method('get');

		$this->listener->handle(new Event());
	}//end testAnotherEventIsIgnored()
}//end class
