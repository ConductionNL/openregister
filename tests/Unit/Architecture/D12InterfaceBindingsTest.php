<?php

/**
 * Service classes lane D12 hid are reached through their interfaces.
 *
 * Controllers, listeners, background jobs, repair steps and other components
 * used to name these concrete Service classes directly. Each now has an
 * interface next to it; outside callers type against and resolve that
 * interface, and the component's registrar aliases it to the class. A missing
 * alias is a runtime 500 that no other unit test sees, so this test pins the
 * binding as well as the implementation and the callers.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Architecture
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Architecture;

use OCA\OpenRegister\Service\Case\CasePlanItemTimers;
use OCA\OpenRegister\Service\Case\CasePlanItemTimersInterface;
use OCA\OpenRegister\Service\Case\CasePlanService;
use OCA\OpenRegister\Service\Case\CasePlanServiceInterface;
use OCA\OpenRegister\Service\Case\CasePlanTimerRearm;
use OCA\OpenRegister\Service\Case\CasePlanTimerRearmInterface;
use OCA\OpenRegister\Service\Case\Nodes\CaseAdvanceNode;
use OCA\OpenRegister\Service\Case\Nodes\CaseAdvanceNodeInterface;
use OCA\OpenRegister\Service\Case\Nodes\CaseOpenNode;
use OCA\OpenRegister\Service\Case\Nodes\CaseOpenNodeInterface;
use OCA\OpenRegister\Service\Case\ZaaktypeCaseSkeletonMapper;
use OCA\OpenRegister\Service\Case\ZaaktypeCaseSkeletonMapperInterface;
use OCA\OpenRegister\Service\ContractBindings;
use OCA\OpenRegister\Service\Flow\FlowLocator;
use OCA\OpenRegister\Service\Flow\FlowLocatorInterface;
use OCA\OpenRegister\Service\Flow\FlowNodeRegistry;
use OCA\OpenRegister\Service\Flow\FlowNodeRegistryInterface;
use OCA\OpenRegister\Service\Flow\FlowRunMigrationService;
use OCA\OpenRegister\Service\Flow\FlowRunMigrationServiceInterface;
use OCA\OpenRegister\Service\Flow\FlowService;
use OCA\OpenRegister\Service\Flow\FlowServiceInterface;
use OCA\OpenRegister\Service\Flow\FlowTriggerValidator;
use OCA\OpenRegister\Service\Flow\FlowTriggerValidatorInterface;
use OCA\OpenRegister\Service\Flow\FlowVersionService;
use OCA\OpenRegister\Service\Flow\FlowVersionServiceInterface;
use OCA\OpenRegister\Service\Flow\Nodes\EndNode;
use OCA\OpenRegister\Service\Flow\Nodes\EndNodeInterface;
use OCA\OpenRegister\Service\Flow\Nodes\LoopNode;
use OCA\OpenRegister\Service\Flow\Nodes\LoopNodeInterface;
use OCA\OpenRegister\Service\Flow\Nodes\SendEmailNode;
use OCA\OpenRegister\Service\Flow\Nodes\SendEmailNodeInterface;
use OCA\OpenRegister\Service\Flow\Nodes\SendNotificationNode;
use OCA\OpenRegister\Service\Flow\Nodes\SendNotificationNodeInterface;
use OCA\OpenRegister\Service\Flow\Nodes\SendTalkMessageNode;
use OCA\OpenRegister\Service\Flow\Nodes\SendTalkMessageNodeInterface;
use OCA\OpenRegister\Service\Flow\Nodes\TriggerManualNode;
use OCA\OpenRegister\Service\Flow\Nodes\TriggerManualNodeInterface;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionBulk;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionBulkInterface;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionDefinitions;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionDefinitionsInterface;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionList;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionListInterface;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionRunner;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionRunnerInterface;
use OCA\OpenRegister\Service\Operations\ConsistencyCheckService;
use OCA\OpenRegister\Service\Operations\ConsistencyCheckServiceInterface;
use OCA\OpenRegister\Service\Operations\ConsistencyRepairService;
use OCA\OpenRegister\Service\Operations\ConsistencyRepairServiceInterface;
use OCA\OpenRegister\Service\Operations\JobRunRecorder;
use OCA\OpenRegister\Service\Operations\JobRunRecorderInterface;
use OCA\OpenRegister\Service\Operations\JobScheduleService;
use OCA\OpenRegister\Service\Operations\JobScheduleServiceInterface;
use OCA\OpenRegister\Service\Operations\MaintenanceModeService;
use OCA\OpenRegister\Service\Operations\MaintenanceModeServiceInterface;
use OCA\OpenRegister\Service\Operations\SupportBundleService;
use OCA\OpenRegister\Service\Operations\SupportBundleServiceInterface;
use OCA\OpenRegister\Service\Rules\ConditionTracer;
use OCA\OpenRegister\Service\Rules\ConditionTracerInterface;
use OCA\OpenRegister\Service\Rules\RuleEnablementService;
use OCA\OpenRegister\Service\Rules\RuleEnablementServiceInterface;
use OCA\OpenRegister\Service\Rules\RuleInventoryService;
use OCA\OpenRegister\Service\Rules\RuleInventoryServiceInterface;
use OCA\OpenRegister\Service\Rules\RuleReplayService;
use OCA\OpenRegister\Service\Rules\RuleReplayServiceInterface;
use OCA\OpenRegister\Service\Rules\RuleRunRecorder;
use OCA\OpenRegister\Service\Rules\RuleRunRecorderInterface;
use OCA\OpenRegister\Service\Rules\RuleTrialService;
use OCA\OpenRegister\Service\Rules\RuleTrialServiceInterface;
use OCA\OpenRegister\Service\Schemas\ExtendingFormDeclaration;
use OCA\OpenRegister\Service\Schemas\ExtendingFormDeclarationInterface;
use OCA\OpenRegister\Service\Schemas\FacetResultCacheClearer;
use OCA\OpenRegister\Service\Schemas\FacetResultCacheClearerInterface;
use OCA\OpenRegister\Service\Schemas\PropertyVocabulary;
use OCA\OpenRegister\Service\Schemas\PropertyVocabularyInterface;
use OCA\OpenRegister\Service\Schemas\SchemaCacheHandler;
use OCA\OpenRegister\Service\Schemas\SchemaCacheHandlerInterface;
use OCA\OpenRegister\Service\Schemas\SemanticRoleHandler;
use OCA\OpenRegister\Service\Schemas\SemanticRoleHandlerInterface;
use OCA\OpenRegister\Service\Schemas\UniqueConstraintEvaluator;
use OCA\OpenRegister\Service\Schemas\UniqueConstraintEvaluatorInterface;
use OCA\OpenRegister\Service\Task\TaskFormCompletion;
use OCA\OpenRegister\Service\Task\TaskFormCompletionInterface;
use OCA\OpenRegister\Service\Task\TaskFormResolver;
use OCA\OpenRegister\Service\Task\TaskFormResolverInterface;
use OCA\OpenRegister\Service\Task\TaskProjectionService;
use OCA\OpenRegister\Service\Task\TaskProjectionServiceInterface;
use OCA\OpenRegister\Service\Task\TaskSubjectLocator;
use OCA\OpenRegister\Service\Task\TaskSubjectLocatorInterface;
use OCA\OpenRegister\Service\Task\TaskVtodoWriteBackGate;
use OCA\OpenRegister\Service\Task\TaskVtodoWriteBackGateInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * @coversNothing
 */
final class D12InterfaceBindingsTest extends TestCase {

	/**
	 * The app's lib directory.
	 *
	 * @var string
	 */
	private const LIB = __DIR__ . '/../../../lib';

	/**
	 * Class => [the interface outside callers type against, the registrar binding it].
	 *
	 * @var array<class-string, array{class-string, class-string}>
	 */
	private const CLASSES = [
		CaseAdvanceNode::class => [CaseAdvanceNodeInterface::class, ContractBindings::class],
		CaseOpenNode::class => [CaseOpenNodeInterface::class, ContractBindings::class],
		CasePlanItemTimers::class => [CasePlanItemTimersInterface::class, ContractBindings::class],
		CasePlanService::class => [CasePlanServiceInterface::class, ContractBindings::class],
		CasePlanTimerRearm::class => [CasePlanTimerRearmInterface::class, ContractBindings::class],
		ConditionTracer::class => [ConditionTracerInterface::class, ContractBindings::class],
		ConsistencyCheckService::class => [ConsistencyCheckServiceInterface::class, ContractBindings::class],
		ConsistencyRepairService::class => [ConsistencyRepairServiceInterface::class, ContractBindings::class],
		EndNode::class => [EndNodeInterface::class, ContractBindings::class],
		ExtendingFormDeclaration::class => [ExtendingFormDeclarationInterface::class, ContractBindings::class],
		FacetResultCacheClearer::class => [FacetResultCacheClearerInterface::class, ContractBindings::class],
		FlowLocator::class => [FlowLocatorInterface::class, ContractBindings::class],
		FlowNodeRegistry::class => [FlowNodeRegistryInterface::class, ContractBindings::class],
		FlowRunMigrationService::class => [FlowRunMigrationServiceInterface::class, ContractBindings::class],
		FlowService::class => [FlowServiceInterface::class, ContractBindings::class],
		FlowTriggerValidator::class => [FlowTriggerValidatorInterface::class, ContractBindings::class],
		FlowVersionService::class => [FlowVersionServiceInterface::class, ContractBindings::class],
		JobRunRecorder::class => [JobRunRecorderInterface::class, ContractBindings::class],
		JobScheduleService::class => [JobScheduleServiceInterface::class, ContractBindings::class],
		LoopNode::class => [LoopNodeInterface::class, ContractBindings::class],
		MaintenanceModeService::class => [MaintenanceModeServiceInterface::class, ContractBindings::class],
		ObjectActionBulk::class => [ObjectActionBulkInterface::class, ContractBindings::class],
		ObjectActionDefinitions::class => [ObjectActionDefinitionsInterface::class, ContractBindings::class],
		ObjectActionList::class => [ObjectActionListInterface::class, ContractBindings::class],
		ObjectActionRunner::class => [ObjectActionRunnerInterface::class, ContractBindings::class],
		PropertyVocabulary::class => [PropertyVocabularyInterface::class, ContractBindings::class],
		RuleEnablementService::class => [RuleEnablementServiceInterface::class, ContractBindings::class],
		RuleInventoryService::class => [RuleInventoryServiceInterface::class, ContractBindings::class],
		RuleReplayService::class => [RuleReplayServiceInterface::class, ContractBindings::class],
		RuleRunRecorder::class => [RuleRunRecorderInterface::class, ContractBindings::class],
		RuleTrialService::class => [RuleTrialServiceInterface::class, ContractBindings::class],
		SchemaCacheHandler::class => [SchemaCacheHandlerInterface::class, ContractBindings::class],
		SemanticRoleHandler::class => [SemanticRoleHandlerInterface::class, ContractBindings::class],
		SendEmailNode::class => [SendEmailNodeInterface::class, ContractBindings::class],
		SendNotificationNode::class => [SendNotificationNodeInterface::class, ContractBindings::class],
		SendTalkMessageNode::class => [SendTalkMessageNodeInterface::class, ContractBindings::class],
		SupportBundleService::class => [SupportBundleServiceInterface::class, ContractBindings::class],
		TaskFormCompletion::class => [TaskFormCompletionInterface::class, ContractBindings::class],
		TaskFormResolver::class => [TaskFormResolverInterface::class, ContractBindings::class],
		TaskProjectionService::class => [TaskProjectionServiceInterface::class, ContractBindings::class],
		TaskSubjectLocator::class => [TaskSubjectLocatorInterface::class, ContractBindings::class],
		TaskVtodoWriteBackGate::class => [TaskVtodoWriteBackGateInterface::class, ContractBindings::class],
		TriggerManualNode::class => [TriggerManualNodeInterface::class, ContractBindings::class],
		UniqueConstraintEvaluator::class => [UniqueConstraintEvaluatorInterface::class, ContractBindings::class],
		ZaaktypeCaseSkeletonMapper::class => [ZaaktypeCaseSkeletonMapperInterface::class, ContractBindings::class],
	];

	/**
	 * Outside files still allowed to name the class, and why.
	 *
	 * ExtendingFormDeclaration and UniqueConstraintEvaluator: both callers are
	 * Db code that constructs the class with `new` (the schema entity and a
	 * schema-mapper refusal helper are not container-built); they are left as
	 * they are. MaintenanceModeMiddleware is touched by an open pull request,
	 * so it is left until that lands.
	 *
	 * @var array<class-string, list<string>>
	 */
	private const KEPT_CALLERS = [
		ExtendingFormDeclaration::class => ['Db/SchemaMapper/PropertyDeclarationRefusals.php'],
		MaintenanceModeService::class => ['Middleware/MaintenanceModeMiddleware.php'],
		UniqueConstraintEvaluator::class => ['Db/Schema.php'],
	];

	/**
	 * The class, its interface and its registrar, one case per class.
	 *
	 * @return array<string, array{class-string, class-string, class-string}>
	 */
	public static function classes(): array {
		$cases = [];
		foreach (self::CLASSES as $class => [$contract, $registrar]) {
			$cases[$class] = [$class, $contract, $registrar];
		}

		return $cases;
	}//end classes()

	/**
	 * The class implements its interface.
	 *
	 * @param class-string $class    The class.
	 * @param class-string $contract Its interface.
	 *
	 * @return void
	 */
	#[DataProvider('classes')]
	public function testTheClassImplementsItsInterface(string $class, string $contract): void {
		$this->assertTrue(interface_exists($contract), $contract . ' exists');
		$this->assertContains($contract, class_implements($class), $class . ' implements ' . $contract);
	}//end testTheClassImplementsItsInterface()

	/**
	 * The interface is bound to the class in its registrar's BINDINGS.
	 *
	 * @param class-string $class     The class.
	 * @param class-string $contract  Its interface.
	 * @param class-string $registrar The registrar that binds it.
	 *
	 * @return void
	 */
	#[DataProvider('classes')]
	public function testTheInterfaceIsBound(string $class, string $contract, string $registrar): void {
		$bindings = constant($registrar . '::BINDINGS');
		$this->assertArrayHasKey($contract, $bindings);
		$this->assertSame($class, $bindings[$contract]);
	}//end testTheInterfaceIsBound()

	/**
	 * The registrar lives in the class's own component.
	 *
	 * @param class-string $class     The class.
	 * @param class-string $contract  Its interface.
	 * @param class-string $registrar The registrar that binds it.
	 *
	 * @return void
	 */
	#[DataProvider('classes')]
	public function testTheRegistrarIsInTheComponent(string $class, string $contract, string $registrar): void {
		$this->assertSame(explode('\\', $class)[2], explode('\\', $registrar)[2], $contract);
	}//end testTheRegistrarIsInTheComponent()

	/**
	 * No lib file outside the class's component names the class.
	 *
	 * @param class-string $class    The class.
	 * @param class-string $contract Its interface.
	 *
	 * @return void
	 */
	#[DataProvider('classes')]
	public function testNoOutsideFileNamesTheClass(string $class, string $contract): void {
		$naming = [];
		foreach ($this->outsideFiles(class: $class) as $relative => $path) {
			if ($this->namesClass(source: (string) file_get_contents($path), class: $class) === true) {
				$naming[] = $relative;
			}
		}

		$this->assertSame(self::KEPT_CALLERS[$class] ?? [], $naming, 'Type against ' . $contract . ' instead.');
	}//end testNoOutsideFileNamesTheClass()

	/**
	 * Every lib PHP file outside the component of the class, keyed by its path under lib/.
	 *
	 * @param class-string $class The class.
	 *
	 * @return array<string, string>
	 */
	private function outsideFiles(string $class): array {
		$component = explode('\\', $class)[2];
		$lib       = (string) realpath(self::LIB);
		$files     = [];
		foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($lib)) as $file) {
			$relative = substr($file->getPathname(), strlen($lib) + 1);
			if ($file->getExtension() === 'php' && str_starts_with($relative, $component . '/') === false) {
				$files[$relative] = $file->getPathname();
			}
		}

		ksort($files);
		return $files;
	}//end outsideFiles()

	/**
	 * Whether code (not comments) in this source names the class.
	 *
	 * @param string       $source The PHP source.
	 * @param class-string $class  The class.
	 *
	 * @return bool
	 */
	private function namesClass(string $source, string $class): bool {
		$short    = substr($class, (int) strrpos($class, '\\') + 1);
		$imported = str_contains($source, 'use ' . $class . ';');
		foreach (token_get_all($source) as $token) {
			if (is_array($token) === false) {
				continue;
			}

			if ($this->tokenNames(token: $token, class: $class) === true
				|| ($imported === true && $token[0] === T_STRING && $token[1] === $short)
			) {
				return true;
			}
		}

		return false;
	}//end namesClass()

	/**
	 * Whether a qualified-name or string-literal token is the class.
	 *
	 * @param array{int, string, int} $token The token.
	 * @param class-string            $class The class.
	 *
	 * @return bool
	 */
	private function tokenNames(array $token, string $class): bool {
		if ($token[0] === T_CONSTANT_ENCAPSED_STRING) {
			return str_replace('\\\\', '\\', trim($token[1], '\'"')) === $class;
		}

		if (in_array($token[0], [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true) === false) {
			return false;
		}

		return ltrim($token[1], '\\') === $class;
	}//end tokenNames()
}//end class
