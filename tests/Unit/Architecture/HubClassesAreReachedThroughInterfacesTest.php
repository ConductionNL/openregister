<?php

/**
 * Callers reach the high fan-in classes through their interfaces.
 *
 * SIG's module coupling counts the files that depend on a module. The classes
 * below each had more than ten dependants; their callers now type against the
 * interface next to the class, so only construction, `instanceof`, static
 * access and the container binding name the class itself. This test keeps that
 * number at or below ten, the bound of SIG's lowest coupling band.
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

use OCA\OpenRegister\Db\DbRegistrar;
use OCA\OpenRegister\Files\FilesRegistrar;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Db\EntityRelationMapper;
use OCA\OpenRegister\Files\Db\EntityRelationMapperInterface;
use OCA\OpenRegister\Files\Db\FileMapper;
use OCA\OpenRegister\Files\Db\FileMapperInterface;
use OCA\OpenRegister\Db\FlowRunMapper;
use OCA\OpenRegister\Db\FlowRunMapperInterface;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Db\OrganisationMapperInterface;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Service\Calculation\CalculationEvaluator;
use OCA\OpenRegister\Service\Calculation\CalculationEvaluatorInterface;
use OCA\OpenRegister\Service\ConfigurationService;
use OCA\OpenRegister\Service\ConfigurationServiceInterface;
use OCA\OpenRegister\Service\ContractBindings;
use OCA\OpenRegister\Service\Flow\FlowRunService;
use OCA\OpenRegister\Service\Flow\FlowRunServiceInterface;
use OCA\OpenRegister\Service\Integration\ExternalIntegrationRouter;
use OCA\OpenRegister\Service\Integration\ExternalIntegrationRouterInterface;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\OpenRegister\Service\OrganisationServiceInterface;
use OCA\OpenRegister\Service\RetentionService;
use OCA\OpenRegister\Service\RetentionServiceInterface;
use OCA\OpenRegister\Service\SettingsService;
use OCA\OpenRegister\Service\SettingsServiceInterface;
use OCA\OpenRegister\Service\Task\TaskService;
use OCA\OpenRegister\Service\Task\TaskServiceInterface;
use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
final class HubClassesAreReachedThroughInterfacesTest extends TestCase {

	/**
	 * The app's lib directory.
	 *
	 * @var string
	 */
	private const LIB = __DIR__ . '/../../../lib';

	/**
	 * Class => the interface its callers type against.
	 *
	 * @var array<class-string, class-string>
	 */
	private const HUBS = [
		AuditTrailMapper::class => AuditTrailMapperInterface::class,
		CalculationEvaluator::class => CalculationEvaluatorInterface::class,
		ConfigurationService::class => ConfigurationServiceInterface::class,
		EntityRelationMapper::class => EntityRelationMapperInterface::class,
		ExternalIntegrationRouter::class => ExternalIntegrationRouterInterface::class,
		FileMapper::class => FileMapperInterface::class,
		FlowRunMapper::class => FlowRunMapperInterface::class,
		FlowRunService::class => FlowRunServiceInterface::class,
		\OCA\OpenRegister\Db\MagicMapper::class => \OCA\OpenRegister\Db\MagicMapperInterface::class,
		\OCA\OpenRegister\Service\Object\CacheHandler::class => \OCA\OpenRegister\Service\Object\CacheHandlerInterface::class,
		\OCA\OpenRegister\Service\Object\SaveObject::class => \OCA\OpenRegister\Service\Object\SaveObjectInterface::class,
		\OCA\OpenRegister\Service\Object\ValidateObject::class => \OCA\OpenRegister\Service\Object\ValidateObjectInterface::class,
		ObjectService::class => ObjectOperationsInterface::class,
		OrganisationMapper::class => OrganisationMapperInterface::class,
		OrganisationService::class => OrganisationServiceInterface::class,
		RegisterMapper::class => RegisterMapperInterface::class,
		RetentionService::class => RetentionServiceInterface::class,
		SchemaMapper::class => SchemaMapperInterface::class,
		SettingsService::class => SettingsServiceInterface::class,
		TaskService::class => TaskServiceInterface::class,
	];

	/**
	 * Each class implements its interface, and the container binds the interface to it.
	 *
	 * @return void
	 */
	public function testEveryInterfaceIsImplementedAndBound(): void {
		foreach (self::HUBS as $class => $interface) {
			$this->assertTrue(is_subclass_of($class, $interface), $class . ' implements ' . $interface);
			$this->assertSame($class, (ContractBindings::BINDINGS + DbRegistrar::BINDINGS + FilesRegistrar::BINDINGS)[$interface] ?? null, $interface . ' is bound');
		}
	}//end testEveryInterfaceIsImplementedAndBound()

	/**
	 * At most ten files in lib/ name each class itself.
	 *
	 * @return void
	 */
	public function testAtMostTenFilesNameTheClassItself(): void {
		foreach (array_keys(self::HUBS) as $class) {
			$referrers = $this->filesNaming(class: $class);
			$this->assertLessThanOrEqual(
				10,
				count($referrers),
				$class . ' is named by ' . implode(', ', $referrers) . '; type the caller against the interface'
			);
		}
	}//end testAtMostTenFilesNameTheClassItself()

	/**
	 * The lib/ files, other than the class and its interface, whose code names the class.
	 *
	 * An import, a fully qualified name, or the bare name in the class's own
	 * namespace counts; comments do not.
	 *
	 * @param string $class The fully qualified class name.
	 *
	 * @return array<int, string> Paths relative to lib/.
	 */
	private function filesNaming(string $class): array {
		$namespace = substr($class, 0, (int)strrpos($class, '\\'));
		$short     = substr($class, (int)strrpos($class, '\\') + 1);
		$found     = [];
		$files     = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::LIB));
		foreach ($files as $file) {
			$path = (string)$file;
			if (str_ends_with($path, '.php') === false
				|| in_array(basename($path, '.php'), [$short, $short . 'Interface'], true) === true
			) {
				continue;
			}

			$code = (string)preg_replace('#/\*.*?\*/|//[^\n]*#s', '', (string)file_get_contents($path));
			$sameNamespace = preg_match('/^namespace\s+' . preg_quote($namespace, '/') . ';/m', $code) === 1;
			$body = (string)preg_replace('/^namespace\s+[^;]+;/m', '', $code);
			$named = preg_match('/' . preg_quote($class, '/') . '(?![\w\\\\])/', $body) === 1
				|| ($sameNamespace === true && preg_match('/(?<![\w\\\\])' . $short . '(?!\w)/', $code) === 1);
			if ($named === true) {
				$found[] = substr($path, strlen(self::LIB) + 1);
			}
		}

		sort($found);
		return $found;
	}//end filesNaming()
}//end class
