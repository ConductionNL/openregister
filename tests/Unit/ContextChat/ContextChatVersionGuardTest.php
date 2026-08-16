<?php

/**
 * Context Chat version-guard regression test.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\ContextChat
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\ContextChat;

use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Nothing the container resolves may name an `OCP\ContextChat` type.
 *
 * WHY THIS EXISTS.
 *
 * `OCP\ContextChat` is core Nextcloud API only from **NC 32**, and even on a
 * server at or above that floor it is absent whenever the ContextChat feature
 * itself is not present — it is not guaranteed by the Nextcloud version alone.
 * Naming one of those types in a constructor is therefore fatal on a supported
 * server — `SimpleContainer::resolve()` calls `new ReflectionClass()` on every
 * parameter type, and that call is the load.
 *
 * NOTE ON THE FLOOR. `appinfo/info.xml` declared `min-version="28"` when this
 * test was written; it now declares **32**. That does NOT retire this guard.
 * The guard is about the namespace being resolvable at all, which is a
 * property of the installed feature rather than of the server version, so the
 * eager-constructor shape stays fatal at any floor.
 *
 * Two classes had it, and the consequences differed only in blast radius:
 *
 *   ContextChatReindexCommand      listed in info.xml, so
 *                                  loadCommandsFromInfoXml() reflected it on
 *                                  EVERY occ invocation. Observed in CI on
 *                                  NC 31.0.14.1 during `occ app:enable`:
 *                                  Interface "OCP\ContextChat\IContentProvider"
 *                                  not found. Logged at level 3 while the
 *                                  enable still reported success — loud in the
 *                                  log, invisible in the exit code.
 *
 *   ContextChatSubmissionListener  registered for ObjectCreated/Updated/
 *                                  DeletedEvent, so it is resolved on EVERY
 *                                  object write. On NC 28-32 that made every
 *                                  create, update and delete throw, for a
 *                                  feature the instance is not using.
 *
 * WHAT THIS TEST CAN CHECK. It runs on a machine where `OCP\ContextChat`
 * probably DOES resolve, so it cannot reproduce the fatal. What it can do —
 * and what actually pins the regression — is assert the shape that causes it:
 * no constructor parameter of a container-resolved class may be typed to an
 * `OCP\ContextChat` name. That is a static property of the signature, true or
 * false regardless of the server this runs on.
 *
 * Lazy use inside a method body is fine and is the fix: both classes now
 * resolve through the container at call time, behind an `interface_exists()`
 * guard.
 *
 * @coversNothing
 */
class ContextChatVersionGuardTest extends TestCase {
	/**
	 * Classes the container resolves that sit near the Context Chat feature.
	 *
	 * `ContentProviderRegistrationListener` is deliberately absent: it is only
	 * ever resolved by dispatching `ContentProviderRegisterEvent`, which cannot
	 * fire on a server without Context Chat. `ContentProvider` itself is absent
	 * for the same reason it is the subject — it legitimately
	 * `implements IContentProvider`, and is only ever loaded behind a guard.
	 *
	 * @return array<string, array{0: class-string}>
	 */
	public static function containerResolvedClassProvider(): array {
		return [
			'reindex command (info.xml -> loadCommandsFromInfoXml)' => [
				\OCA\OpenRegister\Command\ContextChatReindexCommand::class,
			],
			'submission listener (object create/update/delete)' => [
				\OCA\OpenRegister\Listener\ContextChatSubmissionListener::class,
			],
		];
	}//end containerResolvedClassProvider()

	/**
	 * No constructor parameter may be typed to an OCP\ContextChat class.
	 *
	 * @param string $className Fully-qualified class name.
	 *
	 * @return void
	 *
	 * @dataProvider containerResolvedClassProvider
	 */
	public function testNoConstructorNamesAContextChatType(string $className): void {
		$constructor = (new ReflectionClass($className))->getConstructor();
		self::assertNotNull(actual: $constructor, message: $className . ' has no constructor');

		foreach ($constructor->getParameters() as $parameter) {
			$type = $parameter->getType();
			if ($type === null || $type->isBuiltin() === true) {
				continue;
			}

			$typeName = $type->getName();

			// TWO ways a parameter drags OCP\ContextChat in, and the second is
			// the one the first version of this test MISSED — it checked only
			// the type name, so re-adding `ContentProvider $contentProvider`
			// to the reindex command (the original defect, verbatim) still
			// passed. `ContentProvider` is an OCA class; what makes it fatal
			// is its HEADER: `implements OCP\ContextChat\IContentProvider`.
			//
			// 1. the parameter IS an OCP\ContextChat type (the listener).
			// 2. the parameter is any class whose ancestry names one, which
			// is the command's case.
			$offenders = [];
			if (str_contains($typeName, 'OCP\\ContextChat') === true) {
				$offenders[] = $typeName;
			}

			if (class_exists($typeName) === true || interface_exists($typeName) === true) {
				$implemented = class_implements($typeName);
				if ($implemented === false) {
					$implemented = [];
				}

				$parents = class_parents($typeName);
				if ($parents === false) {
					$parents = [];
				}

				$ancestry = array_merge($implemented, $parents);
				foreach ($ancestry as $ancestor) {
					if (str_contains($ancestor, 'OCP\\ContextChat') === true) {
						$offenders[] = $typeName . ' (implements ' . $ancestor . ')';
					}
				}
			}

			self::assertSame(
				expected: [],
				actual: $offenders,
				message: sprintf(
					'%s::__construct($%s) is typed to %s, which requires OCP\\ContextChat to '
					. 'load. That namespace is not guaranteed to be resolvable on a supported '
					. 'server — it is absent unless the ContextChat feature is installed — so '
					. 'the container resolving this class would fatal — '
					. 'SimpleContainer::resolve() does `new ReflectionClass()` on each parameter '
					. 'type, and that call is the load. Resolve it lazily inside the method body '
					. 'instead, behind an interface_exists() guard.',
					$className,
					$parameter->getName(),
					$typeName
				)
			);
		}//end foreach
	}//end testNoConstructorNamesAContextChatType()

	/**
	 * The reindex command still refuses to run when Context Chat is absent.
	 *
	 * The lazy resolve only helps if the execute path also checks. Without
	 * this, the command would resolve fine and then fatal one line later on
	 * `ContentProvider`.
	 *
	 * @return void
	 */
	public function testTheReindexCommandGuardsItsExecutePath(): void {
		$source = (string)file_get_contents(
			__DIR__ . '/../../../lib/Command/ContextChatReindexCommand.php'
		);

		self::assertStringContainsString(
			needle: "interface_exists('OCP\\\\ContextChat\\\\IContentProvider')",
			haystack: $source,
			message: 'execute() must check for the interface before resolving ContentProvider'
		);
	}//end testTheReindexCommandGuardsItsExecutePath()

	/**
	 * The submission listener guards both of its content-manager call paths.
	 *
	 * Both submitContentItem() and removeContentItem() touch
	 * `ContentProvider::` constants, and loading that class is fatal below
	 * NC 32 — so each needs its own guard, not just the shared resolver.
	 *
	 * @return void
	 */
	public function testTheSubmissionListenerGuardsBothCallPaths(): void {
		$source = (string)file_get_contents(
			__DIR__ . '/../../../lib/Listener/ContextChatSubmissionListener.php'
		);

		self::assertSame(
			expected: 2,
			actual: substr_count($source, '$contentManager = $this->contentManager();'),
			message: 'both submitContentItem() and removeContentItem() must resolve behind the guard'
		);
		self::assertStringContainsString(
			needle: "interface_exists('OCP\\\\ContextChat\\\\IContentManager')",
			haystack: $source,
			message: 'the lazy resolver must check the interface rather than assume it'
		);
	}//end testTheSubmissionListenerGuardsBothCallPaths()
}//end class
