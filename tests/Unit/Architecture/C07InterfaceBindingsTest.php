<?php

/**
 * The C07 Service classes the background jobs ran are reached through interfaces.
 *
 * Each class below was named by a background job. Each now has a narrow
 * interface next to it holding only the methods that job calls; the job types
 * against the interface and the class's own registrar (ContractBindings or
 * DbRegistrar) aliases the interface to the class, so the container hands out
 * the same shared instance.
 * A missing alias is a runtime failure of the job that no other unit test sees,
 * so this test pins the implementation and the binding, that no background
 * job names the class again, and, for the classes only jobs named, that no lib
 * file outside lib/Service does.
 *
 * The two fail-closed GDPR providers were named outside lib/Service only for
 * their id; that id is now a constant on the provider interface, which the
 * provider's own PROVIDER_ID repeats.
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

use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Db\DbRegistrar;
use OCA\OpenRegister\Db\SearchTrailCleanupInterface;
use OCA\OpenRegister\Db\SearchTrailMapper;
use OCA\OpenRegister\Service\Archival\ArchiveActionDateCalculator;
use OCA\OpenRegister\Service\Archival\ArchiveReferenceReaderInterface;
use OCA\OpenRegister\Service\AuditHashService;
use OCA\OpenRegister\Service\AuditSealInterface;
use OCA\OpenRegister\Service\Authorization\GroupReconciler;
use OCA\OpenRegister\Service\Authorization\GroupReconcilerInterface;
use OCA\OpenRegister\Service\AvgRetentionPassInterface;
use OCA\OpenRegister\Service\AvgRetentionService;
use OCA\OpenRegister\Service\ContractBindings;
use OCA\OpenRegister\Service\Credential\OAuth2CredentialSweepInterface;
use OCA\OpenRegister\Service\Credential\OAuth2RefreshService;
use OCA\OpenRegister\Service\Gdpr\DpiaPatternDetectionService;
use OCA\OpenRegister\Service\Gdpr\DpiaPatternDetectorInterface;
use OCA\OpenRegister\Service\Gdpr\Identity\IdentityVerifyProvider;
use OCA\OpenRegister\Service\Gdpr\Identity\NullIdentityVerifyProvider;
use OCA\OpenRegister\Service\Gdpr\Regulator\NullRegulatorEscalateProvider;
use OCA\OpenRegister\Service\Gdpr\Regulator\RegulatorEscalateProvider;
use OCA\OpenRegister\Service\Gdpr\Retention\RetentionSweepInterface;
use OCA\OpenRegister\Service\Gdpr\Retention\RetentionSweepService;
use OCA\OpenRegister\Service\Rbac\ExternalGrantLapseNotifier;
use OCA\OpenRegister\Service\Rbac\ExternalGrantLapseNotifierInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * @coversNothing
 */
final class C07InterfaceBindingsTest extends TestCase {

	/**
	 * The app's lib directory.
	 *
	 * @var string
	 */
	private const LIB = __DIR__ . '/../../../lib';

	/**
	 * Class => [the interface the background jobs type against, the registrar whose BINDINGS bind it].
	 *
	 * @var array<class-string, array{class-string, class-string}>
	 */
	private const CLASSES = [
		ArchiveActionDateCalculator::class => [ArchiveReferenceReaderInterface::class, ContractBindings::class],
		AuditHashService::class => [AuditSealInterface::class, ContractBindings::class],
		AuditTrailMapper::class => [AuditTrailMapperInterface::class, DbRegistrar::class],
		AvgRetentionService::class => [AvgRetentionPassInterface::class, ContractBindings::class],
		DpiaPatternDetectionService::class => [DpiaPatternDetectorInterface::class, ContractBindings::class],
		ExternalGrantLapseNotifier::class => [ExternalGrantLapseNotifierInterface::class, ContractBindings::class],
		GroupReconciler::class => [GroupReconcilerInterface::class, ContractBindings::class],
		OAuth2RefreshService::class => [OAuth2CredentialSweepInterface::class, ContractBindings::class],
		RetentionSweepService::class => [RetentionSweepInterface::class, ContractBindings::class],
		SearchTrailMapper::class => [SearchTrailCleanupInterface::class, DbRegistrar::class],
	];

	/**
	 * Classes no lib file outside lib/Service names any more.
	 *
	 * AuditHashService, AuditTrailMapper and SearchTrailMapper are still named by controllers,
	 * commands and repair steps outside this lane, so only the background jobs
	 * are pinned for them.
	 *
	 * @var list<class-string>
	 */
	private const HIDDEN = [
		ArchiveActionDateCalculator::class,
		AvgRetentionService::class,
		DpiaPatternDetectionService::class,
		ExternalGrantLapseNotifier::class,
		GroupReconciler::class,
		OAuth2RefreshService::class,
		RetentionSweepService::class,
	];

	/**
	 * Fail-closed provider => the interface whose NULL_PROVIDER_ID carries its id.
	 *
	 * @var array<class-string, class-string>
	 */
	private const NULL_PROVIDERS = [
		NullIdentityVerifyProvider::class => IdentityVerifyProvider::class,
		NullRegulatorEscalateProvider::class => RegulatorEscalateProvider::class,
	];

	/**
	 * One case per class.
	 *
	 * @return array<string, array{class-string, class-string}>
	 */
	public static function classes(): array {
		$cases = [];
		foreach (self::CLASSES as $class => [$contract]) {
			$cases[$class] = [$class, $contract];
		}

		return $cases;
	}//end classes()

	/**
	 * The class implements its interface, and its registrar binds the interface to it.
	 *
	 * @param class-string $class    The class.
	 * @param class-string $contract Its interface.
	 *
	 * @return void
	 */
	#[DataProvider('classes')]
	public function testTheInterfaceIsImplementedAndBound(string $class, string $contract): void {
		$this->assertTrue(condition: interface_exists($contract), message: $contract . ' exists');
		$this->assertContains(needle: $contract, haystack: class_implements($class), message: $class . ' implements ' . $contract);
		$bindings = constant(self::CLASSES[$class][1] . '::BINDINGS');
		$this->assertSame(expected: $class, actual: ($bindings[$contract] ?? null), message: $contract . ' is bound');
	}//end testTheInterfaceIsImplementedAndBound()

	/**
	 * No background job names the class in code.
	 *
	 * @param class-string $class    The class.
	 * @param class-string $contract Its interface.
	 *
	 * @return void
	 */
	#[DataProvider('classes')]
	public function testNoBackgroundJobNamesTheClass(string $class, string $contract): void {
		$this->assertSame(
			expected: [],
			actual: $this->filesNaming(class: $class, prefix: 'BackgroundJob/'),
			message: 'Type against ' . $contract . ' instead.'
		);
	}//end testNoBackgroundJobNamesTheClass()

	/**
	 * No lib file outside lib/Service names a hidden class in code.
	 *
	 * @return void
	 */
	public function testNoFileOutsideServiceNamesAHiddenClass(): void {
		foreach (self::HIDDEN as $class) {
			$this->assertSame(
				expected: [],
				actual: $this->filesNaming(class: $class, prefix: null),
				message: 'Type against ' . self::CLASSES[$class][0] . ' instead.'
			);
		}
	}//end testNoFileOutsideServiceNamesAHiddenClass()

	/**
	 * The lib files naming the class: those under the prefix, or with no prefix, every file outside lib/Service.
	 *
	 * @param class-string $class  The class.
	 * @param string|null  $prefix The path prefix under lib/, or null for outside lib/Service.
	 *
	 * @return list<string>
	 */
	private function filesNaming(string $class, ?string $prefix): array {
		$naming = [];
		foreach ($this->libFiles() as $relative => $path) {
			$inScope = ($prefix === null && str_starts_with($relative, 'Service/') === false)
				|| ($prefix !== null && str_starts_with($relative, $prefix) === true);
			if ($inScope === true && $this->namesClass(source: (string) file_get_contents($path), class: $class) === true) {
				$naming[] = $relative;
			}
		}

		return $naming;
	}//end filesNaming()

	/**
	 * One case per fail-closed provider.
	 *
	 * @return array<string, array{class-string, class-string}>
	 */
	public static function nullProviders(): array {
		$cases = [];
		foreach (self::NULL_PROVIDERS as $class => $contract) {
			$cases[$class] = [$class, $contract];
		}

		return $cases;
	}//end nullProviders()

	/**
	 * The provider's id is the interface constant, and no lib file outside lib/Service names the provider.
	 *
	 * @param class-string $class    The provider.
	 * @param class-string $contract Its interface.
	 *
	 * @return void
	 */
	#[DataProvider('nullProviders')]
	public function testTheNullProviderIdLivesOnTheInterface(string $class, string $contract): void {
		$this->assertContains(needle: $contract, haystack: class_implements($class), message: $class . ' implements ' . $contract);
		$this->assertSame(expected: constant($contract . '::NULL_PROVIDER_ID'), actual: constant($class . '::PROVIDER_ID'));
		$this->assertSame(expected: constant($contract . '::NULL_PROVIDER_ID'), actual: (new $class())->getProviderId());
		$this->assertSame(
			expected: [],
			actual: $this->filesNaming(class: $class, prefix: null),
			message: 'Use ' . $contract . '::NULL_PROVIDER_ID instead.'
		);
	}//end testTheNullProviderIdLivesOnTheInterface()

	/**
	 * Every lib PHP file, keyed by its path under lib/, sorted.
	 *
	 * @return array<string, string>
	 */
	private function libFiles(): array {
		$lib   = (string) realpath(self::LIB);
		$files = [];
		foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($lib)) as $file) {
			if ($file->getExtension() === 'php') {
				$files[substr($file->getPathname(), strlen($lib) + 1)] = $file->getPathname();
			}
		}

		ksort($files);
		return $files;
	}//end libFiles()

	/**
	 * Whether code (not comments) in this source names the class.
	 *
	 * @param string       $source The PHP source.
	 * @param class-string $class  The class.
	 *
	 * @return bool
	 */
	private function namesClass(string $source, string $class): bool {
		$short     = substr($class, (int) strrpos($class, '\\') + 1);
		$namespace = substr($class, 0, (int) strrpos($class, '\\'));
		$bare      = str_contains($source, 'use ' . $class . ';') || str_contains($source, 'namespace ' . $namespace . ';');
		foreach (token_get_all($source) as $token) {
			if (is_array($token) === false) {
				continue;
			}

			if ((in_array($token[0], [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true) === true && ltrim($token[1], '\\') === $class)
				|| ($bare === true && $token[0] === T_STRING && $token[1] === $short)
			) {
				return true;
			}
		}

		return false;
	}//end namesClass()
}//end class
