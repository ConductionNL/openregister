<?php

/**
 * Lane D9: concrete classes outside lib/Service and lib/Db are reached through
 * the interface next to them.
 *
 * For each class below: it implements its interface, its component's registrar
 * binds that interface to it (a missing binding is a runtime 500 that no other
 * unit test catches), and no lib/ file in another component names the class
 * itself, apart from the callers listed as left on purpose.
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

use OCA\OpenRegister\AppHost\AppHostRegistrar;
use OCA\OpenRegister\AppHost\Scheduling\ScheduleFlowStore;
use OCA\OpenRegister\AppHost\Scheduling\ScheduleFlowStoreInterface;
use OCA\OpenRegister\AppHost\Scheduling\ScheduleManifestLoader;
use OCA\OpenRegister\AppHost\Scheduling\ScheduleManifestLoaderInterface;
use OCA\OpenRegister\BulkAction\ApplyRuleAction;
use OCA\OpenRegister\BulkAction\ApplyRuleActionInterface;
use OCA\OpenRegister\BulkAction\AssignAction;
use OCA\OpenRegister\BulkAction\AssignActionInterface;
use OCA\OpenRegister\BulkAction\BulkActionRegistrar;
use OCA\OpenRegister\BulkAction\RestorePriorValuesAction;
use OCA\OpenRegister\BulkAction\RestorePriorValuesActionInterface;
use OCA\OpenRegister\BulkAction\RunActionAction;
use OCA\OpenRegister\BulkAction\RunActionActionInterface;
use OCA\OpenRegister\BulkAction\SetPropertiesAction;
use OCA\OpenRegister\BulkAction\SetPropertiesActionInterface;
use OCA\OpenRegister\ContextChat\ContentProvider;
use OCA\OpenRegister\ContextChat\ContentProviderInterface;
use OCA\OpenRegister\ContextChat\ContextChatRegistrar;
use OCA\OpenRegister\Files\FilesRegistrar;
use OCA\OpenRegister\Files\Service\RiskLevelService;
use OCA\OpenRegister\Files\Service\RiskLevelServiceInterface;
use OCA\OpenRegister\Files\Service\UploadService;
use OCA\OpenRegister\Kernel\Contracts\UploadServiceInterface;
use OCA\OpenRegister\Notifications\NotificationsRegistrar;
use OCA\OpenRegister\Notifications\Service\Webhook\WebhookInterceptionCache;
use OCA\OpenRegister\Notifications\Service\Webhook\WebhookInterceptionCacheInterface;
use OCA\OpenRegister\Search\SearchRegistrar;
use OCA\OpenRegister\Search\Service\Aggregation\TimeseriesRequestValidator;
use OCA\OpenRegister\Search\Service\Aggregation\TimeseriesRequestValidatorInterface;
use OCA\OpenRegister\Search\Service\Geo\GeoFilterApplier;
use OCA\OpenRegister\Search\Service\Geo\GeoFilterApplierInterface;
use OCA\OpenRegister\Search\Service\Geo\GeoFilterParser;
use OCA\OpenRegister\Search\Service\Geo\GeoFilterParserInterface;
use OCA\OpenRegister\Search\Service\Quality\UniqueHintAnnotationValidator;
use OCA\OpenRegister\Search\Service\Quality\UniqueHintAnnotationValidatorInterface;
use OCA\OpenRegister\Search\Service\Search\PlaceholderResolver;
use OCA\OpenRegister\Search\Service\Search\PlaceholderResolverInterface;
use OCA\OpenRegister\Search\Service\VectorizationService;
use OCA\OpenRegister\Search\Service\VectorizationServiceInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
final class D9InterfaceBindingsTest extends TestCase {

	/**
	 * The app's lib directory.
	 *
	 * @var string
	 */
	private const LIB = __DIR__ . '/../../../lib';

	/**
	 * Class => [its interface, the registrar whose BINDINGS bind it, lib/ paths that may still name the class].
	 *
	 * @var array<class-string, array{0: class-string, 1: class-string, 2: array<int, string>}>
	 */
	private const HIDDEN = [
		ApplyRuleAction::class => [
			ApplyRuleActionInterface::class,
			BulkActionRegistrar::class,
			[],
		],
		AssignAction::class => [
			AssignActionInterface::class,
			BulkActionRegistrar::class,
			[],
		],
		ContentProvider::class => [
			ContentProviderInterface::class,
			ContextChatRegistrar::class,
			[],
		],
		GeoFilterApplier::class => [
			GeoFilterApplierInterface::class,
			SearchRegistrar::class,
			[],
		],
		GeoFilterParser::class => [
			GeoFilterParserInterface::class,
			SearchRegistrar::class,
			[],
		],
		PlaceholderResolver::class => [
			PlaceholderResolverInterface::class,
			SearchRegistrar::class,
			[
				'lib/Service/Object/SaveObject.php',
			],
		],
		RestorePriorValuesAction::class => [
			RestorePriorValuesActionInterface::class,
			BulkActionRegistrar::class,
			[],
		],
		RiskLevelService::class => [
			RiskLevelServiceInterface::class,
			FilesRegistrar::class,
			[],
		],
		RunActionAction::class => [
			RunActionActionInterface::class,
			BulkActionRegistrar::class,
			[],
		],
		ScheduleFlowStore::class => [
			ScheduleFlowStoreInterface::class,
			AppHostRegistrar::class,
			[],
		],
		ScheduleManifestLoader::class => [
			ScheduleManifestLoaderInterface::class,
			AppHostRegistrar::class,
			[],
		],
		SetPropertiesAction::class => [
			SetPropertiesActionInterface::class,
			BulkActionRegistrar::class,
			[],
		],
		TimeseriesRequestValidator::class => [
			TimeseriesRequestValidatorInterface::class,
			SearchRegistrar::class,
			[],
		],
		UniqueHintAnnotationValidator::class => [
			UniqueHintAnnotationValidatorInterface::class,
			SearchRegistrar::class,
			[],
		],
		UploadService::class => [
			UploadServiceInterface::class,
			FilesRegistrar::class,
			[],
		],
		VectorizationService::class => [
			VectorizationServiceInterface::class,
			SearchRegistrar::class,
			[],
		],
		WebhookInterceptionCache::class => [
			WebhookInterceptionCacheInterface::class,
			NotificationsRegistrar::class,
			[],
		],
	];

	/**
	 * One row per hidden class.
	 *
	 * @return array<string, array{0: class-string, 1: array{0: class-string, 1: class-string, 2: array<int, string>}}>
	 */
	public static function hiddenClasses(): array {
		$rows = [];
		foreach (self::HIDDEN as $class => $spec) {
			$rows[$class] = [$class, $spec];
		}

		return $rows;
	}//end hiddenClasses()

	/**
	 * The class implements its interface.
	 *
	 * @param class-string $class The hidden class.
	 * @param array{0: class-string, 1: class-string, 2: array<int, string>} $spec Its interface, registrar and left callers.
	 *
	 * @return void
	 */
	#[DataProvider('hiddenClasses')]
	public function testClassImplementsItsInterface(string $class, array $spec): void {
		$this->assertContains($spec[0], (array)class_implements($class), $class . ' implements ' . $spec[0]);
	}//end testClassImplementsItsInterface()

	/**
	 * The component's registrar binds the interface to the class.
	 *
	 * @param class-string $class The hidden class.
	 * @param array{0: class-string, 1: class-string, 2: array<int, string>} $spec Its interface, registrar and left callers.
	 *
	 * @return void
	 */
	#[DataProvider('hiddenClasses')]
	public function testRegistrarBindsTheInterface(string $class, array $spec): void {
		$bindings = constant($spec[1] . '::BINDINGS');
		$this->assertSame($class, ($bindings[$spec[0]] ?? null), $spec[1] . '::BINDINGS binds ' . $spec[0]);
	}//end testRegistrarBindsTheInterface()

	/**
	 * No lib/ file in another component names the class, apart from the callers left on purpose.
	 *
	 * @param class-string $class The hidden class.
	 * @param array{0: class-string, 1: class-string, 2: array<int, string>} $spec Its interface, registrar and left callers.
	 *
	 * @return void
	 */
	#[DataProvider('hiddenClasses')]
	public function testNoOtherComponentNamesTheClass(string $class, array $spec): void {
		$namers = array_values(array_diff($this->namersOutsideComponent(class: $class), $spec[2]));
		$this->assertSame([], $namers, $class . ' is named outside its component; type the caller against ' . $spec[0]);
	}//end testNoOtherComponentNamesTheClass()

	/**
	 * The lib/ files outside the class's own component whose code names the class.
	 *
	 * @param class-string $class The class.
	 *
	 * @return array<int, string> Paths relative to the app root, sorted.
	 */
	private function namersOutsideComponent(string $class): array {
		$component = 'lib/' . explode('\\', $class)[2] . '/';
		$found     = [];
		foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::LIB, \FilesystemIterator::SKIP_DOTS)) as $file) {
			$relative = 'lib/' . substr((string)$file, strlen(self::LIB) + 1);
			if (str_ends_with($relative, '.php') === true && str_starts_with($relative, $component) === false
				&& $this->codeNames(path: (string)$file, class: $class) === true
			) {
				$found[] = $relative;
			}
		}

		sort($found);
		return $found;
	}//end namersOutsideComponent()

	/**
	 * Whether the file's code, comments aside, names the class by a qualified or fully qualified name.
	 *
	 * A file in another component cannot share the class's namespace, so a bare
	 * name never resolves to it there.
	 *
	 * @param string       $path  The file.
	 * @param class-string $class The class.
	 *
	 * @return bool
	 */
	private function codeNames(string $path, string $class): bool {
		foreach (token_get_all((string)file_get_contents($path)) as $token) {
			if (is_array($token) === true
				&& in_array($token[0], [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true) === true
				&& ltrim($token[1], '\\') === $class
			) {
				return true;
			}
		}

		return false;
	}//end codeNames()
}//end class
