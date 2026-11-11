<?php

/**
 * OpenRegister ReferenceOptionsAnswer
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller\Support;


use Closure;
use OCA\OpenRegister\Kernel\Contracts\ListQuery;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Service\Schemas\ReferenceFilterException;
use OCA\OpenRegister\Service\Schemas\ReferenceOptionsReaderInterface;
use OCA\OpenRegister\Service\Schemas\ReferenceOptionsReaders;
use OCA\OpenRegister\Service\Schemas\ReferencePick;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Answers which objects a reference field may offer, given the record and its draft.
 *
 * @spec openspec/changes/fields-a-user-adds-and-choices-a-record-narrows/specs/runtime-schema-api/spec.md
 */
final class ReferenceOptionsAnswer {
	/**
	 * The plan reader.
	 *
	 * @var ReferenceOptionsReaderInterface
	 */
	private readonly ReferenceOptionsReaderInterface $reader;

	/**
	 * Hold the request and the services.
	 *
	 * @param IRequest                  $request The request.
	 * @param LoggerInterface           $logger  The logger.
	 * @param Closure                   $lookup  Reads the stored record of an ask.
	 * @param ObjectOperationsInterface $search  Searches the referenced schema.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/fields-a-user-adds-and-choices-a-record-narrows/specs/runtime-schema-api/spec.md
	 */
	public function __construct(
		private readonly IRequest $request,
		private readonly LoggerInterface $logger,
		private readonly Closure $lookup,
		private readonly ObjectOperationsInterface $search,
	) {
		$this->reader = ReferenceOptionsReaders::create();
	}//end __construct()

	/**
	 * The options for a property of this schema.
	 *
	 * @param ReferenceOptionsAsk $ask    The record and property.
	 * @param Schema              $schema The record's schema.
	 *
	 * @return JSONResponse The options, or why there are none.
	 *
	 * @throws MultipleObjectsReturnedException When the identifier matches several records.
	 *
	 * @spec openspec/changes/fields-a-user-adds-and-choices-a-record-narrows/specs/runtime-schema-api/spec.md
	 */
	public function answer(ReferenceOptionsAsk $ask, Schema $schema): JSONResponse {
		$record = $this->withDraft(record: $this->storedRecord(ask: $ask));

		try {
			$plan = $this->reader->plan(schema: $schema, pick: new ReferencePick(property: $ask->property, record: $record));
		} catch (ReferenceFilterException $e) {
			return new JSONResponse(data: ['message' => $e->getMessage()], statusCode: 422);
		}

		if ($this->reader->isAnswerable(plan: $plan) === false) {
			return $this->awaitingChoice(plan: $plan);
		}

		if (is_string(($plan['target']['schema'] ?? null)) === false) {
			return new JSONResponse(data: ['message' => sprintf('\'%s\' does not name a schema to read options from.', $ask->property)], statusCode: 422);
		}

		return $this->searched(ask: $ask, plan: $plan);
	}//end answer()

	/**
	 * The record as it stands, or nothing when it cannot be read.
	 *
	 * @param ReferenceOptionsAsk $ask The record.
	 *
	 * @return array<string, mixed> The stored object data.
	 *
	 * @throws MultipleObjectsReturnedException When the identifier matches several records.
	 */
	private function storedRecord(ReferenceOptionsAsk $ask): array {
		try {
			$stored = ($this->lookup)($ask);
		} catch (MultipleObjectsReturnedException $e) {
			throw $e;
		} catch (\Throwable $e) {
			$context = ['file' => __FILE__, 'line' => __LINE__, 'id' => $ask->id, 'error' => $e->getMessage()];
			$this->logger->debug(message: '[ObjectsController] No stored record for a reference-options read; using the draft alone', context: $context);
			return [];
		}

		return ($stored?->getObject() ?? []);
	}//end storedRecord()

	/**
	 * The record with the caller's unsaved draft laid over it.
	 *
	 * @param array<string, mixed> $record The stored data.
	 *
	 * @return array<string, mixed> The data the choices depend on.
	 */
	private function withDraft(array $record): array {
		$draft = $this->request->getParam('_draft');
		if (is_array($draft) === true) {
			return array_merge($record, $draft);
		}

		return $record;
	}//end withDraft()

	/**
	 * The answer while the field another one depends on has no value yet.
	 *
	 * @param array<string, mixed> $plan The reader's plan.
	 *
	 * @return JSONResponse No options, naming what to choose first.
	 */
	private function awaitingChoice(array $plan): JSONResponse {
		$message = sprintf('Choose %s first; there are no options until then.', implode(' and ', $plan['needs']));

		return new JSONResponse(
			data: ['results' => [], 'total' => 0, 'needs' => $plan['needs'], 'filter' => $plan['filter'], 'message' => $message]
		);
	}//end awaitingChoice()

	/**
	 * The options found in the referenced schema.
	 *
	 * @param ReferenceOptionsAsk  $ask  The record and property.
	 * @param array<string, mixed> $plan The reader's plan.
	 *
	 * @return JSONResponse The options, or a 500 when the search failed.
	 */
	private function searched(ReferenceOptionsAsk $ask, array $plan): JSONResponse {
		try {
			$options = $this->options(ask: $ask, plan: $plan);
		} catch (\Throwable $e) {
			$context = ['file' => __FILE__, 'line' => __LINE__, 'property' => $ask->property, 'error' => $e->getMessage()];
			$this->logger->warning(message: '[ObjectsController] A reference-options read failed', context: $context);
			return new JSONResponse(data: ['message' => 'The options for this field could not be read.'], statusCode: 500);
		}

		$options['filtered'] = $plan['filtered'];
		$options['filter'] = $plan['filter'];
		$options['needs'] = [];

		return new JSONResponse(data: $options);
	}//end searched()

	/**
	 * Search the REFERENCED register and schema.
	 *
	 * Without pointing the service there the search would run against the
	 * record's own schema and answer a confidently wrong list. `_rbac` stays on:
	 * a picker is not a way to see objects you may not see.
	 *
	 * @param ReferenceOptionsAsk  $ask  The record and property.
	 * @param array<string, mixed> $plan The reader's plan.
	 *
	 * @return array<string, mixed> The paginated options.
	 */
	private function options(ReferenceOptionsAsk $ask, array $plan): array {
		$limit = $this->reader->limitFor(requested: $this->request->getParam('_limit'));
		$offset = (int)($this->request->getParam('_offset') ?? 0);
		$query = $this->reader->queryFor(plan: $plan, page: new ListQuery(limit: $limit, offset: $offset));

		$this->search->setRegister(($plan['target']['register'] ?? $ask->register));
		$this->search->setSchema($plan['target']['schema']);

		return $this->search->searchObjectsPaginated(query: $query);
	}//end options()
}//end class
