<?php

/**
 * An agent's chat finds only objects inside the views it is granted.
 *
 * Runs the real ContextRetrievalHandler with a real Agent entity and real
 * ObjectEntity rows; the search services are doubles that answer the way the
 * real ones do (vector hits with object metadata, a view-scoped searchObjects).
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Chat
 * @author   Conduction Development Team <dev@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/openregister
 *
 * @spec openspec/specs/agent-tool-governance/spec.md#requirement-an-agent-reads-only-the-views-it-is-granted
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Chat;

use OCA\OpenRegister\Db\Agent;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Chat\ContextRetrievalHandler;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\Vectorization\VectorEmbeddings;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ContextRetrievalAgentViewsTest extends TestCase {

	/** @var VectorEmbeddings&MockObject */
	private VectorEmbeddings $vectors;

	/** @var ObjectService&MockObject */
	private ObjectService $objects;

	/** @var array<int, array<string, mixed>> The view-scoped searches the handler made. */
	private array $scopedCalls = [];

	protected function setUp(): void {
		$this->vectors = $this->createMock(VectorEmbeddings::class);
		$this->objects = $this->createMock(ObjectService::class);
		$this->objects->method('searchObjectsPaginated')->willReturn(['results' => []]);

		// Two object hits and one file hit, as the vector store returns them.
		$this->vectors->method('semanticSearch')->willReturn(
			[
				$this->hit(uuid: 'in-view', title: 'Open case'),
				$this->hit(uuid: 'outside', title: 'Closed case'),
				['entity_id' => '77', 'entity_type' => 'file', 'chunk_text' => 'a file', 'metadata' => ['file_id' => 77, 'file_name' => 'memo.pdf']],
			]
		);

		// The view `open-cases` holds only the object `in-view`.
		$this->objects->method('searchObjects')->willReturnCallback(
			function (array $query=[], bool $_rbac=true, bool $_multitenancy=true, ?array $ids=null, ?string $uses=null, ?array $views=null, bool $_viewScopeRequired=false): array {
				$this->scopedCalls[] = ['ids' => $ids, 'views' => $views, 'required' => $_viewScopeRequired, 'rbac' => $_rbac];
				$found = [];
				foreach (($ids ?? []) as $uuid) {
					if ($uuid === 'in-view' && in_array('open-cases', ($views ?? []), true) === true) {
						$entity = new ObjectEntity();
						$entity->setUuid('in-view');
						$found[] = $entity;
					}
				}

				return $found;
			}
		);
	}//end setUp()

	/**
	 * A vector hit for an object.
	 *
	 * @param string $uuid  The object uuid.
	 * @param string $title The object title.
	 *
	 * @return array<string, mixed>
	 */
	private function hit(string $uuid, string $title): array {
		return [
			'entity_id' => $uuid,
			'entity_type' => 'object',
			'chunk_text' => $title,
			'similarity' => 0.9,
			'metadata' => ['uuid' => $uuid, 'object_title' => $title, 'register_id' => 1, 'schema_id' => 2],
		];
	}//end hit()

	/**
	 * An agent searching semantically, with the given views.
	 *
	 * @param array|null $views The agent's views.
	 *
	 * @return Agent
	 */
	private function agent(?array $views): Agent {
		$agent = new Agent();
		$agent->setRagSearchMode('semantic');
		$agent->setRagNumSources(5);
		$agent->setViews($views);

		return $agent;
	}//end agent()

	/**
	 * The object uuids among the retrieved sources.
	 *
	 * @param array $context The handler's answer.
	 *
	 * @return string[]
	 */
	private function objectUuids(array $context): array {
		$uuids = [];
		foreach ($context['sources'] as $source) {
			if ($source['type'] === 'object') {
				$uuids[] = $source['uuid'];
			}
		}

		return $uuids;
	}//end objectUuids()

	/**
	 * Retrieve context for an agent.
	 *
	 * @param Agent $agent         The agent.
	 * @param array $selectedViews Views the user picked in the chat.
	 *
	 * @return array
	 */
	private function retrieve(Agent $agent, array $selectedViews=[]): array {
		$handler = new ContextRetrievalHandler(
			vectorService: $this->vectors,
			objectService: $this->objects,
			logger: $this->createMock(LoggerInterface::class)
		);

		return $handler->retrieveContext(query: 'case', agent: $agent, selectedViews: $selectedViews);
	}//end retrieve()

	/**
	 * Scenario: an object outside the agent's views is not found.
	 *
	 * @return void
	 */
	public function testAnObjectOutsideTheAgentsViewsIsNotFound(): void {
		$context = $this->retrieve(agent: $this->agent(views: ['open-cases']));

		$this->assertSame(['in-view'], $this->objectUuids(context: $context));
		$this->assertStringNotContainsString('Closed case', $context['text']);
	}//end testAnObjectOutsideTheAgentsViewsIsNotFound()

	/**
	 * The membership check is the view-scoped search, with RBAC on and the view as a required bound.
	 *
	 * @return void
	 */
	public function testMembershipIsAskedOfTheViewScopedSearch(): void {
		$this->retrieve(agent: $this->agent(views: ['open-cases']));

		$this->assertCount(1, $this->scopedCalls);
		$this->assertSame(['in-view', 'outside'], $this->scopedCalls[0]['ids']);
		$this->assertSame(['open-cases'], $this->scopedCalls[0]['views']);
		$this->assertTrue($this->scopedCalls[0]['required']);
		$this->assertTrue($this->scopedCalls[0]['rbac']);
	}//end testMembershipIsAskedOfTheViewScopedSearch()

	/**
	 * Files are not objects of a view, so the view limit leaves them alone.
	 *
	 * @return void
	 */
	public function testFilesAreNotLimitedByViews(): void {
		$context = $this->retrieve(agent: $this->agent(views: ['open-cases']));

		$types = array_column($context['sources'], 'type');
		$this->assertContains('file', $types);
	}//end testFilesAreNotLimitedByViews()

	/**
	 * An agent without views keeps its user's scope: no view search, both objects found.
	 *
	 * @return void
	 */
	public function testAnAgentWithoutViewsFindsWhatItsUserCanRead(): void {
		$context = $this->retrieve(agent: $this->agent(views: null));

		$this->assertSame(['in-view', 'outside'], $this->objectUuids(context: $context));
		$this->assertSame([], $this->scopedCalls);
	}//end testAnAgentWithoutViewsFindsWhatItsUserCanRead()

	/**
	 * A chat that picks a view the agent is not granted finds no objects, not all of them.
	 *
	 * @return void
	 */
	public function testPickingAViewOutsideTheGrantFindsNoObjects(): void {
		$context = $this->retrieve(agent: $this->agent(views: ['open-cases']), selectedViews: ['other-view']);

		$this->assertSame([], $this->objectUuids(context: $context));
	}//end testPickingAViewOutsideTheGrantFindsNoObjects()

	/**
	 * When the view search fails, the objects are withheld rather than served unscoped.
	 *
	 * @return void
	 */
	public function testAFailingViewSearchWithholdsTheObjects(): void {
		$objects = $this->createMock(ObjectService::class);
		$objects->method('searchObjectsPaginated')->willReturn(['results' => []]);
		$objects->method('searchObjects')->willThrowException(new \Exception('view not found'));
		$this->objects = $objects;

		$context = $this->retrieve(agent: $this->agent(views: ['open-cases']));

		$this->assertSame([], $this->objectUuids(context: $context));
	}//end testAFailingViewSearchWithholdsTheObjects()
}//end class
