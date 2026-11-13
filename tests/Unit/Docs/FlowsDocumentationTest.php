<?php

/**
 * Doc-lint: docs/features/flows.md enumerates exactly what the engine ships.
 *
 * The flow documentation lists two things a reader relies on verbatim: the
 * trigger event catalogue and the built-in step ids. Both are hand-written
 * prose, so both drift the moment the code moves and nobody edits the page.
 * This test makes that drift a red build instead of a wrong page:
 *
 *  - the event ids in the page's catalogue table equal the ids in
 *    `EventCatalogService::CATALOG`, read from the constant itself;
 *  - every `openregister.*` node id the built-in registration listener
 *    registers is named in the page, and every `openregister.*` id the page
 *    names is one the listener registers;
 *  - the feature index links the page.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Docs
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-engine-docs/specs/flow-engine-docs/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Docs;

use OCA\OpenRegister\Listener\FlowNodeRegistrationListener;
use OCA\OpenRegister\Service\Flow\EventCatalogService;
use OCA\OpenRegister\Service\Flow\IFlowNode;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionNamedType;

/**
 * The flow documentation names the shipped event catalogue and node palette exactly.
 */
#[CoversNothing]
class FlowsDocumentationTest extends TestCase {
	/**
	 * Namespace of the bundles the registration listener is composed of.
	 *
	 * @var string
	 */
	private const BUNDLE_NAMESPACE = 'OCA\\OpenRegister\\Listener\\FlowNodes\\';

	/**
	 * Marker opening the event catalogue table in flows.md.
	 *
	 * @var string
	 */
	private const EVENTS_START = '<!-- flow-event-catalogue:start -->';

	/**
	 * Marker closing the event catalogue table in flows.md.
	 *
	 * @var string
	 */
	private const EVENTS_END = '<!-- flow-event-catalogue:end -->';

	/**
	 * Absolute path of a file under docs/features.
	 *
	 * @param string $name The file name.
	 *
	 * @return string The path.
	 */
	private static function featureDoc(string $name): string {
		return dirname(__DIR__, 3) . '/docs/features/' . $name;
	}

	/**
	 * The contents of flows.md.
	 *
	 * @return string The markdown.
	 */
	private function flowsDoc(): string {
		$path = self::featureDoc(name: 'flows.md');
		$this->assertFileExists($path, 'docs/features/flows.md must exist.');

		return (string)file_get_contents($path);
	}

	/**
	 * The event ids in EventCatalogService::CATALOG, read from the constant.
	 *
	 * @return list<string> The ids, sorted.
	 */
	private static function catalogueEventIds(): array {
		$catalog = (new ReflectionClass(EventCatalogService::class))->getConstant('CATALOG');
		$ids     = array_map(static fn (array $entry): string => $entry['id'], (array)$catalog);
		sort($ids);

		return $ids;
	}

	/**
	 * The event ids in the first column of the page's catalogue table.
	 *
	 * @param string $doc The markdown.
	 *
	 * @return list<string> The ids, sorted.
	 */
	private function documentedEventIds(string $doc): array {
		$start = strpos($doc, self::EVENTS_START);
		$end   = strpos($doc, self::EVENTS_END);
		$this->assertNotFalse($start, 'flows.md must carry the ' . self::EVENTS_START . ' marker.');
		$this->assertNotFalse($end, 'flows.md must carry the ' . self::EVENTS_END . ' marker.');
		$this->assertGreaterThan($start, $end, 'The event catalogue markers are out of order.');

		$block = substr($doc, (int)$start, ((int)$end - (int)$start));
		preg_match_all('/^\|\s*`([^`]+)`\s*\|/m', $block, $matches);
		$ids = $matches[1];
		sort($ids);

		return $ids;
	}

	/**
	 * Every node id the built-in registration listener registers.
	 *
	 * Walks the listener's constructor the way the container does: a bundle
	 * parameter is descended into, a node interface is resolved to the class
	 * the container binds it to (the interface name without `Interface`). The
	 * node is built without its constructor, since `getId()` returns a
	 * constant and needs none of its collaborators.
	 *
	 * @return list<string> The ids, sorted.
	 */
	private function registeredNodeIds(): array {
		$ids = [];
		$this->collectNodeIds(class: FlowNodeRegistrationListener::class, ids: $ids);
		$ids = array_values(array_unique($ids));
		sort($ids);

		return $ids;
	}

	/**
	 * Add the node ids reachable from one class's constructor.
	 *
	 * @param string       $class The listener or bundle class.
	 * @param list<string> $ids   The ids found so far.
	 *
	 * @return void
	 */
	private function collectNodeIds(string $class, array &$ids): void {
		$constructor = (new ReflectionClass($class))->getConstructor();
		$this->assertNotNull($constructor, $class . ' has no constructor to walk.');

		foreach ($constructor->getParameters() as $parameter) {
			$type = $parameter->getType();
			$this->assertInstanceOf(ReflectionNamedType::class, $type, $class . '::$' . $parameter->getName() . ' has no single type.');
			$name = $type->getName();

			if (str_starts_with($name, self::BUNDLE_NAMESPACE) === true) {
				$this->collectNodeIds(class: $name, ids: $ids);
				continue;
			}

			$this->assertTrue(is_subclass_of($name, IFlowNode::class), $name . ' is neither a node bundle nor a flow node.');
			$concrete = preg_replace('/Interface$/', '', $name);
			$this->assertTrue(class_exists((string)$concrete), 'No concrete node class for ' . $name . '.');

			$node = (new ReflectionClass((string)$concrete))->newInstanceWithoutConstructor();
			$this->assertInstanceOf(IFlowNode::class, $node);
			$ids[] = $node->getId();
		}
	}

	/**
	 * Every `openregister.*` id the page mentions.
	 *
	 * @param string $doc The markdown.
	 *
	 * @return list<string> The ids, sorted.
	 */
	private static function documentedNodeIds(string $doc): array {
		preg_match_all('#(?<![A-Za-z0-9_./-])openregister\.[a-z][a-z0-9-]*#', $doc, $matches);
		$ids = array_values(array_unique($matches[0]));
		sort($ids);

		return $ids;
	}

	public function testDocumentedEventIdsEqualTheCatalogue(): void {
		$catalogue  = self::catalogueEventIds();
		$documented = $this->documentedEventIds(doc: $this->flowsDoc());

		$this->assertNotEmpty($catalogue);
		$this->assertSame(
			$catalogue,
			$documented,
			'The event table in docs/features/flows.md must list exactly the ids in EventCatalogService::CATALOG. '
			. 'Missing from the doc: ' . implode(', ', array_diff($catalogue, $documented)) . '. '
			. 'Not in the catalogue: ' . implode(', ', array_diff($documented, $catalogue)) . '.'
		);
	}

	public function testEveryRegisteredNodeIsDocumented(): void {
		$registered = $this->registeredNodeIds();
		$documented = self::documentedNodeIds(doc: $this->flowsDoc());

		$this->assertNotEmpty($registered);
		$missing = array_values(array_diff($registered, $documented));
		$this->assertSame([], $missing, 'Built-in node ids not documented in docs/features/flows.md: ' . implode(', ', $missing));
	}

	public function testEveryDocumentedNodeIsRegistered(): void {
		$registered = $this->registeredNodeIds();
		$documented = self::documentedNodeIds(doc: $this->flowsDoc());

		$unknown = array_values(array_diff($documented, $registered));
		$this->assertSame([], $unknown, 'docs/features/flows.md names openregister.* ids the registry does not ship: ' . implode(', ', $unknown));
	}

	public function testFeatureIndexLinksTheFlowsPage(): void {
		$readme = (string)file_get_contents(self::featureDoc(name: 'README.md'));

		$this->assertStringContainsString('](flows.md)', $readme, 'docs/features/README.md must link flows.md.');
	}
}
