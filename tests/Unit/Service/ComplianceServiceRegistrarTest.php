<?php

/**
 * The Compliance area binds its own service interfaces, each once.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Kernel\Contracts\Core\CascadeRetentionCheckInterface;
use OCA\OpenRegister\Kernel\Contracts\Core\ObjectArchivalMetadataInterface;
use OCA\OpenRegister\Kernel\Contracts\Core\SearchTrailRecorderInterface;
use OCA\OpenRegister\Kernel\Contracts\Core\ReadPurposeGuardInterface;
use OCA\OpenRegister\Kernel\Contracts\Core\ReadableObjectHistoryInterface;
use OCA\OpenRegister\Service\Audit\AuditResealInterface;
use OCA\OpenRegister\Service\Audit\ObjectHistoryForReaders;
use OCA\OpenRegister\Service\Audit\ObjectHistoryForReadersInterface;
use OCA\OpenRegister\Service\Archival\ArchivalRetentionGuard;
use OCA\OpenRegister\Service\Audit\PurposeGuard;
use OCA\OpenRegister\Service\AuditHashService;
use OCA\OpenRegister\Service\ContractBindings;
use OCA\OpenRegister\Service\ComplianceServiceBindings;
use OCA\OpenRegister\Service\ComplianceServiceRegistrar;
use OCA\OpenRegister\Service\Gdpr\GdprRegistrar;
use OCA\OpenRegister\Service\Object\SearchQueryHandler;
use OCA\OpenRegister\Service\SearchTrailService;
use OCA\OpenRegister\Service\TmloService;
use OCA\OpenRegister\Tests\Unit\AppHost\RecordingRegistrationContext;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * @covers \OCA\OpenRegister\Service\ComplianceServiceRegistrar
 * @covers \OCA\OpenRegister\Service\ComplianceServiceBindings
 * @uses   \OCA\OpenRegister\Db\DbRegistrar
 * @uses   \OCA\OpenRegister\Service\Gdpr\GdprRegistrar
 */
final class ComplianceServiceRegistrarTest extends TestCase {

	/**
	 * The registrar aliases exactly the bindings of its map.
	 *
	 * @return void
	 */
	public function testTheRegistrarAliasesEveryBindingOfItsMap(): void {
		$context = new RecordingRegistrationContext();
		ComplianceServiceRegistrar::register(context: $context);

		$this->assertNotSame(expected: [], actual: ComplianceServiceRegistrar::BINDINGS);
		$this->assertSame(expected: ComplianceServiceRegistrar::BINDINGS, actual: array_column($context->aliases, 'target', 'alias'));
	}//end testTheRegistrarAliasesEveryBindingOfItsMap()

	/**
	 * Every bound class implements its interface.
	 *
	 * @return void
	 */
	public function testEveryBindingIsImplementedByItsClass(): void {
		foreach (ComplianceServiceRegistrar::BINDINGS as $interface => $class) {
			$this->assertTrue(condition: (new ReflectionClass($class))->implementsInterface($interface), message: $class . ' implements ' . $interface);
		}
	}//end testEveryBindingIsImplementedByItsClass()

	/**
	 * ContractBindings no longer binds any of these interfaces.
	 *
	 * @return void
	 */
	public function testContractBindingsDoesNotBindThemAgain(): void {
		$twice = array_intersect_key(ComplianceServiceRegistrar::BINDINGS, ContractBindings::BINDINGS);
		$this->assertSame(expected: [], actual: $twice);
	}//end testContractBindingsDoesNotBindThemAgain()

	/**
	 * register() registers exactly what the GDPR registrar registers, in its order, after the area's bindings.
	 *
	 * @return void
	 */
	public function testRegisterEndsWithTheGdprServices(): void {
		$entry = new RecordingRegistrationContext();
		ComplianceServiceRegistrar::register(context: $entry);
		$direct = new RecordingRegistrationContext();
		GdprRegistrar::register(context: $direct);

		$this->assertNotSame(expected: [], actual: $entry->services);
		$this->assertSame(expected: $direct->services, actual: $entry->services);
		$this->assertSame(expected: ComplianceServiceBindings::BINDINGS, actual: array_column($entry->aliases, 'target', 'alias'));
	}//end testRegisterEndsWithTheGdprServices()

	/**
	 * The object search records its trails through the Core port the search trail service answers.
	 *
	 * @return void
	 */
	public function testTheObjectSearchRecordsThroughThePort(): void {
		$this->assertSame(expected: SearchTrailService::class, actual: ComplianceServiceRegistrar::BINDINGS[SearchTrailRecorderInterface::class] ?? null);

		$parameter = null;
		foreach ((new ReflectionMethod(SearchQueryHandler::class, '__construct'))->getParameters() as $candidate) {
			if ($candidate->getName() === 'searchTrailService') {
				$parameter = $candidate;
			}
		}

		$this->assertNotNull($parameter);
		$type = $parameter->getType();
		$this->assertInstanceOf(ReflectionNamedType::class, $type);
		$this->assertSame(expected: SearchTrailRecorderInterface::class, actual: $type->getName());
	}//end testTheObjectSearchRecordsThroughThePort()

	/**
	 * The Core read purpose-guard port, which relation reads ask per table, is answered by the doelbinding guard.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/verwerkingsregister-api/spec.md
	 */
	public function testTheReadPurposeGuardPortIsAnsweredByThePurposeGuard(): void {
		$this->assertSame(expected: PurposeGuard::class, actual: (ComplianceServiceRegistrar::BINDINGS[ReadPurposeGuardInterface::class] ?? null));
	}//end testTheReadPurposeGuardPortIsAnsweredByThePurposeGuard()

	/**
	 * The Core readable-history port, which the object API asks for a reader's audit trail, is answered by the reader history.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/audit-trail-immutable/spec.md#requirement-the-history-of-one-object-is-readable-by-whoever-may-read-that-object
	 */
	public function testTheReadableHistoryPortIsAnsweredByTheReaderHistory(): void {
		$this->assertSame(expected: ObjectHistoryForReaders::class, actual: (ComplianceServiceRegistrar::BINDINGS[ReadableObjectHistoryInterface::class] ?? null));
		$this->assertTrue(is_subclass_of(ObjectHistoryForReadersInterface::class, ReadableObjectHistoryInterface::class));
	}//end testTheReadableHistoryPortIsAnsweredByTheReaderHistory()

	public function testTheCascadeAsksTheArchivalRetentionGuard(): void {
		$this->assertSame(expected: ArchivalRetentionGuard::class, actual: ComplianceServiceRegistrar::BINDINGS[CascadeRetentionCheckInterface::class]);
	}//end testTheCascadeAsksTheArchivalRetentionGuard()

	/**
	 * The save path's archival metadata port is answered by the TMLO service.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/tmlo-auto-populate/spec.md
	 */
	public function testTheArchivalMetadataPortIsAnsweredByTheTmloService(): void {
		$this->assertSame(expected: TmloService::class, actual: (ComplianceServiceRegistrar::BINDINGS[ObjectArchivalMetadataInterface::class] ?? null));
	}//end testTheArchivalMetadataPortIsAnsweredByTheTmloService()

	/**
	 * The anonymisation's reseal of the audit chain is answered by the audit hash service, bound in this area's map only.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/anonymising-as-an-archival-outcome/specs/retention-management/spec.md#requirement-anonymising-reaches-every-derived-copy-or-does-nothing-req-aao-003
	 */
	public function testTheAuditResealPortIsAnsweredByTheAuditHashService(): void {
		$this->assertSame(expected: AuditHashService::class, actual: (ComplianceServiceRegistrar::BINDINGS[AuditResealInterface::class] ?? null));
		$this->assertArrayNotHasKey(key: AuditResealInterface::class, array: ContractBindings::BINDINGS);
		$this->assertTrue(condition: is_subclass_of(object_or_class: AuditHashService::class, class: AuditResealInterface::class));
	}//end testTheAuditResealPortIsAnsweredByTheAuditHashService()
}//end class
