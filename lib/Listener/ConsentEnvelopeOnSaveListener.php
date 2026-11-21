<?php

/**
 * OpenRegister ConsentEnvelopeOnSaveListener
 *
 * Subscribes to ObjectCreatingEvent + ObjectUpdatingEvent. For each
 * `x-openregister-consent` property declared on the schema, fills the
 * read-only evidentiary fields on every newly appended array entry and
 * refuses any write that mutates or drops an already-persisted entry.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Listener
 * @package  OCA\OpenRegister\Listener
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/consent-evidence-envelope/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\OpenRegister\Listener\Support\ConsentSaveContext;
use OCA\OpenRegister\Service\Consent\ConsentEnvelopeEvaluatorInterface;
use OCA\OpenRegister\Service\Consent\ConsentEnvelopeEvaluators;
use OCA\OpenRegister\Service\Consent\ConsentEvidenceSource;
use OCA\OpenRegister\Service\Consent\ConsentPropertyWrite;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Fills evidence and enforces append-only on every `x-openregister-consent` property.
 *
 * @template-implements IEventListener<ObjectCreatingEvent|ObjectUpdatingEvent>
 */
class ConsentEnvelopeOnSaveListener implements IEventListener {

	/**
	 * The error code a refused write carries, so a client can branch on it.
	 *
	 * @var string
	 */
	public const ERROR_CODE = 'consent-envelope-mutated';

	/**
	 * The dialect key a schema property carries.
	 *
	 * @var string
	 */
	private const ANNOTATION_KEY = 'x-openregister-consent';

	/**
	 * Pure property-level evaluator (append-only check + evidence fill). No
	 * Nextcloud dependency, so it needs no DI registration — this listener
	 * owns the one instance it needs.
	 *
	 * @var ConsentEnvelopeEvaluatorInterface
	 */
	private readonly ConsentEnvelopeEvaluatorInterface $evaluator;

	/**
	 * Constructor.
	 *
	 * @param SchemaMapperInterface $schemas Resolves the schema an object belongs to.
	 * @param IUserSession $userSession Current user session (acting identity).
	 * @param IRequest $request Current request (IP + user agent).
	 * @param LoggerInterface $logger The logger.
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) `ConsentEnvelopeEvaluators::evaluator()` builds the pure
	 *                                       evaluator this listener owns, as `new` did before; the
	 *                                       evaluator has no Nextcloud dependency to inject.
	 */
	public function __construct(
		private readonly SchemaMapperInterface $schemas,
		private readonly IUserSession $userSession,
		private readonly IRequest $request,
		private readonly LoggerInterface $logger,
	) {
		$this->evaluator = ConsentEnvelopeEvaluators::evaluator();
	}//end __construct()

	/**
	 * Handle the inbound event.
	 *
	 * @param Event $event The inbound event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/consent-evidence-envelope/spec.md
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectCreatingEvent) {
			$this->evaluate(event: $event, newObject: $event->getObject());
			return;
		}

		if ($event instanceof ObjectUpdatingEvent) {
			$this->evaluate(event: $event, newObject: $event->getNewObject());
		}
	}//end handle()

	/**
	 * Evaluate every `x-openregister-consent` property for one write.
	 *
	 * @param ObjectCreatingEvent|ObjectUpdatingEvent $event The write event (refused via `setErrors()`+`stopPropagation()`).
	 * @param ObjectEntity $newObject The object as the caller submitted it.
	 *
	 * @return void
	 */
	private function evaluate(ObjectCreatingEvent|ObjectUpdatingEvent $event, ObjectEntity $newObject): void {
		try {
			$context = $this->contextOf(event: $event, newObject: $newObject);
			if ($context === null) {
				return;
			}

			// A refusal returns null too: the event already carries the reason.
			$changed = $this->applyConsentProperties(context: $context);
			if ($changed !== null) {
				// Mutate the entity directly, the same idiom
				// CalculationOnSaveListener uses (`$object->setObject($data)`),
				// rather than the separate setModifiedData()/MagicMapper-merge
				// path: this listener has already assembled the complete,
				// correct payload for every touched property, so a shallow
				// merge downstream would be redundant, not additive.
				$newObject->setObject($changed);
			}
		} catch (Throwable $failure) {
			// A consent property that cannot be evaluated must not become the
			// reason nothing can be written; the failure is named in the log
			// (mirrors UniqueConstraintListener's fail-open-on-internal-error
			// posture — this guards against a bug in this listener, not
			// against a caller's malformed input, which is refused above).
			$this->logEvaluationFailure(failure: $failure);
		}//end try
	}//end evaluate()

	/**
	 * Name a failed evaluation in the log; the save goes ahead.
	 *
	 * @param Throwable $failure Why the evaluation failed.
	 *
	 * @return void
	 */
	private function logEvaluationFailure(Throwable $failure): void {
		$this->logger->warning(
			message: '[ConsentEnvelopeOnSaveListener] The evaluation itself failed, allowing the save: ' . $failure->getMessage(),
			context: ['file' => __FILE__, 'line' => __LINE__, 'exception' => get_class($failure)]
		);
	}//end logEvaluationFailure()

	/**
	 * Resolve the schema's consent-shaped properties plus the incoming and
	 * previously persisted payloads for one write, or null when there is
	 * nothing for this listener to do.
	 *
	 * @param ObjectCreatingEvent|ObjectUpdatingEvent $event The event that can refuse the save.
	 * @param ObjectEntity $newObject The object as the caller submitted it.
	 *
	 * @return ConsentSaveContext|null
	 */
	private function contextOf(ObjectCreatingEvent|ObjectUpdatingEvent $event, ObjectEntity $newObject): ?ConsentSaveContext {
		$consentProperties = $this->consentPropertiesOf(object: $newObject);
		$incomingData = $newObject->getObject();
		if ($consentProperties === [] || is_array($incomingData) === false) {
			return null;
		}

		return new ConsentSaveContext(
			event: $event,
			properties: $consentProperties,
			incoming: $incomingData,
			persisted: self::persistedDataOf(oldObject: self::oldObjectOf(event: $event))
		);
	}//end contextOf()

	/**
	 * The previously persisted object of an update, or null on a create.
	 *
	 * @param ObjectCreatingEvent|ObjectUpdatingEvent $event The write event.
	 *
	 * @return ObjectEntity|null The stored object.
	 */
	private static function oldObjectOf(ObjectCreatingEvent|ObjectUpdatingEvent $event): ?ObjectEntity {
		if ($event instanceof ObjectUpdatingEvent) {
			return $event->getOldObject();
		}

		return null;
	}//end oldObjectOf()

	/**
	 * The consent properties of the object's schema; empty when it names none or no schema.
	 *
	 * @param ObjectEntity $object The object being saved.
	 *
	 * @return array<string, array<string, mixed>> Each consent property's annotation, by name.
	 */
	private function consentPropertiesOf(ObjectEntity $object): array {
		$reference = (string)$object->getSchema();
		if ($reference === '') {
			return [];
		}

		$schema = $this->schemas->find(id: $reference, _rbac: false, _multitenancy: false);
		return $this->consentProperties(schema: $schema);
	}//end consentPropertiesOf()

	/**
	 * The stored object data, or an empty array on a create.
	 *
	 * @param ObjectEntity|null $oldObject The stored object, null on a create.
	 *
	 * @return array<string, mixed> The stored data.
	 */
	private static function persistedDataOf(?ObjectEntity $oldObject): array {
		$persisted = $oldObject?->getObject();
		if (is_array($persisted) === false) {
			return [];
		}

		return $persisted;
	}//end persistedDataOf()

	/**
	 * Evaluate every declared consent-shaped property against one write.
	 *
	 * @param ConsentSaveContext $context The save: its event (refused via `setErrors()`+`stopPropagation()`), the schema's
	 *                                    `x-openregister-consent` properties, the submitted and the persisted payload.
	 *
	 * @return array<string, mixed>|null The updated payload to persist, or null when nothing changed
	 *     (also null once the event is stopped — the caller checks `isPropagationStopped()`).
	 */
	private function applyConsentProperties(ConsentSaveContext $context): ?array {
		$run = new ConsentApplyRun();
		$run->context = $context;
		$run->data = $context->incoming;
		foreach (array_keys($context->properties) as $name) {
			$run->property = $name;
			$result = $this->evaluateProperty(run: $run);
			if ($context->event->isPropagationStopped() === true) {
				return null;
			}

			if ($result !== ($run->data[$name] ?? [])) {
				$run->data[$name] = $result;
			}
		}

		return self::changedPayload(before: $context->incoming, after: $run->data);
	}//end applyConsentProperties()

	/**
	 * The payload after evaluation when it differs from the submitted one, else null.
	 *
	 * @param array<string, mixed> $before The submitted payload.
	 * @param array<string, mixed> $after  The payload after evaluation.
	 *
	 * @return array<string, mixed>|null The changed payload.
	 */
	private static function changedPayload(array $before, array $after): ?array {
		if ($after === $before) {
			return null;
		}

		return $after;
	}//end changedPayload()

	/**
	 * The `x-openregister-consent` properties declared on a schema, keyed by property name.
	 *
	 * @param Schema $schema The schema to inspect.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function consentProperties(Schema $schema): array {
		$properties = ($schema->getProperties() ?? []);
		if (is_array($properties) === false) {
			return [];
		}

		$found = [];
		foreach ($properties as $name => $definition) {
			$annotation = self::consentAnnotationOf(definition: $definition);
			if ($annotation !== null) {
				$found[(string)$name] = $annotation;
			}
		}

		return $found;
	}//end consentProperties()

	/**
	 * The `x-openregister-consent` declaration of one property definition, or null.
	 *
	 * @param mixed $definition The property definition.
	 *
	 * @return array<string, mixed>|null The declaration.
	 */
	private static function consentAnnotationOf(mixed $definition): ?array {
		if (is_array($definition) === false) {
			return null;
		}

		$annotation = ($definition[self::ANNOTATION_KEY] ?? null);
		if (is_array($annotation) === false) {
			return null;
		}

		return $annotation;
	}//end consentAnnotationOf()

	/**
	 * Evaluate one consent-shaped property: resolves the NC-coupled inputs
	 * (acting identity, IP, user agent) and delegates the pure append-only
	 * check + evidence fill to {@see ConsentEnvelopeEvaluatorInterface}.
	 *
	 * @param ConsentApplyRun $run The save, the property being evaluated and the incoming payload so far
	 *                             (for the value and `subjectProperty` resolution).
	 *
	 * @return array<int, array<string, mixed>> The array to persist (unchanged from the caller's array
	 *     when the event was stopped — the caller must check `isPropagationStopped()`).
	 */
	private function evaluateProperty(ConsentApplyRun $run): array {
		$context = $run->context;
		$name = $run->property;
		$data = $run->data;
		$result = $this->evaluator->evaluate(
			write: new ConsentPropertyWrite(
				name: $name,
				annotation: $context->properties[$name],
				incoming: ($data[$name] ?? []),
				persisted: ($context->persisted[$name] ?? [])
			),
			source: new ConsentEvidenceSource(
				actingIdentity: $this->resolveActingIdentity(annotation: $context->properties[$name], allData: $data),
				ipAddress: $this->safeRemoteAddress(),
				userAgent: $this->safeUserAgent()
			)
		);

		if ($result['refused'] === true) {
			$this->refuse(run: $run, message: (string)$result['message']);
		}

		return $result['value'];
	}//end evaluateProperty()

	/**
	 * Resolve the identity to record as "by" on a newly appended entry: the
	 * acting Nextcloud user, or — when the caller writes on a data subject's
	 * behalf and no user is active — the declared `subjectProperty`'s value.
	 *
	 * @param array<string, mixed> $annotation The property's `x-openregister-consent` declaration.
	 * @param array<string, mixed> $allData The full incoming object payload.
	 *
	 * @return string|null
	 */
	private function resolveActingIdentity(array $annotation, array $allData): ?string {
		$actor = $this->userSession->getUser();
		if ($actor !== null) {
			return $actor->getUID();
		}

		$subjectProperty = ($annotation['subjectProperty'] ?? null);
		if (is_string($subjectProperty) === false) {
			return null;
		}

		$resolved = ($allData[$subjectProperty] ?? null);
		if (is_string($resolved) === true) {
			return $resolved;
		}

		return null;
	}//end resolveActingIdentity()

	/**
	 * Refuse the current write with a structured error.
	 *
	 * @param ConsentApplyRun $run The save whose event to stop, on its offending property.
	 * @param string $message The reason.
	 *
	 * @return void
	 */
	private function refuse(ConsentApplyRun $run, string $message): void {
		$event = $run->context->event;
		$event->setErrors([
			'code' => self::ERROR_CODE,
			'message' => $message,
			'property' => $run->property,
		]);
		$event->stopPropagation();
	}//end refuse()

	/**
	 * The caller's remote address, or null when unavailable (e.g. a CLI/occ write).
	 *
	 * @return string|null
	 */
	private function safeRemoteAddress(): ?string {
		try {
			$address = $this->request->getRemoteAddress();
			if ($address === '') {
				return null;
			}

			return $address;
		} catch (Throwable $failure) {
			return null;
		}
	}//end safeRemoteAddress()

	/**
	 * The caller's User-Agent header, or null when unavailable.
	 *
	 * @return string|null
	 */
	private function safeUserAgent(): ?string {
		try {
			$agent = $this->request->getHeader('User-Agent');
			if ($agent === '') {
				return null;
			}

			return $agent;
		} catch (Throwable $failure) {
			return null;
		}
	}//end safeUserAgent()
}//end class
