<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;

/**
 * Every method a test double adds must be one the real class can answer.
 *
 * `addMethods()` exists for magic `__call` methods: it CREATES the named
 * method on the mock, so the real class is never consulted. That makes it the
 * one mock builder call that can manufacture an accessor nothing answers, and
 * a suite full of such doubles passes while the production path 500s. Measured
 * cases in this repo: `ContactLinkMapper::find()` (openregister#3693),
 * `ObjectEntity::getOrganization()` with a z, `WebhookLog::getWebhookId()`,
 * `Schema::getTags()` and `Schema::getRegister()`, `Register::getName()`.
 *
 * The rule this test enforces:
 *
 *  - On an entity (a class with `__call`), an added `getX`/`setX`/`isX`/`hasX`
 *    must have a real backing property `x`, because that is all
 *    `Entity::__call` will answer; anything else throws
 *    "x is not a valid attribute".
 *  - On a class WITHOUT `__call` there is nothing to answer an added method at
 *    all, so adding one is always wrong.
 *
 * PHPUnit already refuses `addMethods()` for a method the class declares
 * (CannotUseAddMethodsException), so the declared case needs no check here.
 */
class MockedCallSurfaceTest extends TestCase {

	/**
	 * Every addMethods() name in the suite resolves on the real class.
	 *
	 * @return void
	 */
	public function testEveryAddedMockMethodIsAnswerableByTheRealClass(): void {
		$offences = [];

		foreach ($this->addMethodsSites() as $site) {
			[$file, $line, $class, $methods] = $site;

			$reflection = new ReflectionClass($class);
			$hasCall = $reflection->hasMethod('__call');
			$properties = $this->propertyNames($reflection);

			foreach ($methods as $method) {
				if ($hasCall === false) {
					$offences[] = sprintf(
						'%s:%d %s::%s() — the class has no __call(), so nothing answers this method',
						$file,
						$line,
						$reflection->getShortName(),
						$method
					);
					continue;
				}

				if ($this->backingProperty($method, $properties) === null) {
					$offences[] = sprintf(
						'%s:%d %s::%s() — no property backs this accessor, so Entity::__call throws',
						$file,
						$line,
						$reflection->getShortName(),
						$method
					);
				}
			}
		}

		$this->assertSame(
			[],
			$offences,
			"A test double added methods the real class cannot answer:\n" . implode("\n", $offences)
		);
	}//end testEveryAddedMockMethodIsAnswerableByTheRealClass()

	/**
	 * The scanner sees the doubles that are actually there.
	 *
	 * Without this the test above passes just as loudly when the regex stops
	 * matching and nothing is scanned at all.
	 *
	 * @return void
	 */
	public function testTheScannerFindsDoublesToCheck(): void {
		$this->assertGreaterThan(
			10,
			count($this->addMethodsSites()),
			'The addMethods() scanner found almost nothing, so it is measuring itself, not the suite'
		);
	}//end testTheScannerFindsDoublesToCheck()

	/**
	 * The name of the property an accessor reads, or null when none does.
	 *
	 * @param string        $method     The accessor name.
	 * @param array<string> $properties The real class's property names.
	 *
	 * @return string|null The backing property name.
	 */
	private function backingProperty(string $method, array $properties): ?string {
		foreach (['get' => 3, 'set' => 3, 'is' => 2, 'has' => 3] as $prefix => $length) {
			if (str_starts_with($method, $prefix) === false) {
				continue;
			}

			$candidate = lcfirst(substr($method, $length));
			if (in_array($candidate, $properties, true) === true) {
				return $candidate;
			}
		}

		return null;
	}//end backingProperty()

	/**
	 * Every property name on a class and its ancestors.
	 *
	 * @param ReflectionClass<object> $reflection The class.
	 *
	 * @return array<string> The property names.
	 */
	private function propertyNames(ReflectionClass $reflection): array {
		$names = [];
		$current = $reflection;

		while ($current !== false) {
			foreach (
				$current->getProperties(
					ReflectionProperty::IS_PUBLIC | ReflectionProperty::IS_PROTECTED | ReflectionProperty::IS_PRIVATE
				) as $property
			) {
				$names[] = $property->getName();
			}

			$current = $current->getParentClass();
		}

		return array_values(array_unique($names));
	}//end propertyNames()

	/**
	 * Every addMethods() call in the suite, with the class it doubles.
	 *
	 * Only classes owned by this app are returned: a double of an OCP
	 * interface answers to Nextcloud's shape, not ours.
	 *
	 * @return array<array{0: string, 1: int, 2: class-string, 3: array<string>}>
	 */
	private function addMethodsSites(): array {
		$sites = [];
		$root = dirname(__DIR__);

		$files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
		foreach ($files as $file) {
			if ($file->isFile() === false || $file->getExtension() !== 'php') {
				continue;
			}

			$source = (string)file_get_contents($file->getPathname());
			if (str_contains($source, 'addMethods(') === false) {
				continue;
			}

			$uses = [];
			preg_match_all('/^use\s+([\w\\\\]+);/m', $source, $useMatches);
			foreach ($useMatches[1] as $used) {
				$parts = explode('\\', $used);
				$uses[end($parts)] = $used;
			}

			preg_match_all('/addMethods\(\s*(\[[^\]]*\])\s*\)/s', $source, $callMatches, PREG_OFFSET_CAPTURE);
			foreach ($callMatches[1] as $index => $match) {
				preg_match_all("/'([^']+)'/", $match[0], $methodMatches);
				if ($methodMatches[1] === []) {
					continue;
				}

				$offset = $callMatches[0][$index][1];
				$builderAt = strrpos(substr($source, 0, $offset), 'getMockBuilder(');
				if ($builderAt === false) {
					continue;
				}

				if (preg_match('/getMockBuilder\(\s*([\\\\\w]+)::class/', substr($source, $builderAt, 200), $classMatch) !== 1) {
					continue;
				}

				$short = ltrim($classMatch[1], '\\');
				$class = ($uses[$short] ?? $short);
				if (str_starts_with($class, 'OCA\\OpenRegister\\') === false || class_exists($class) === false) {
					continue;
				}

				$sites[] = [
					str_replace($root . '/', '', $file->getPathname()),
					(substr_count(substr($source, 0, $offset), "\n") + 1),
					$class,
					$methodMatches[1],
				];
			}
		}

		return $sites;
	}//end addMethodsSites()
}//end class
