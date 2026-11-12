<?php

/**
 * ReferenceService: a short code typed in a sentence becomes a link, and the
 * link is recorded on both sides.
 *
 * ZAAK-2026-0412 written in a note should reach the zaak. The pattern that
 * recognises it is administered, not coded, so an instance that declares no
 * pattern renders code-shaped text unchanged and no leaf app ships a regex.
 *
 * Rendering the link is presentation. RECORDING the reference is what makes
 * "which zaken mention this besluit" answerable from the other end, and what
 * survives somebody rewriting the sentence around the code (D-6). The two are
 * separate methods here for exactly that reason.
 *
 * ADMINISTERED REGEXES ARE INPUT. The stored pattern is a BODY, without
 * delimiters and without flags; this service supplies both. An administrator
 * therefore cannot change what the expression means by writing its delimiters
 * themselves, and a pattern that does not compile is refused at declaration
 * time rather than throwing on the next note somebody writes.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Service\Timeline
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Notifications\Service\Timeline;

use DateTime;
use Generator;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCA\OpenRegister\Notifications\Db\EntryReference;
use OCA\OpenRegister\Notifications\Db\EntryReferenceMapper;
use OCA\OpenRegister\Notifications\Db\ReferencePattern;
use OCA\OpenRegister\Notifications\Db\ReferencePatternMapper;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;
use Throwable;

/**
 * Administered reference patterns and the references they record.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Timeline
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.ErrorControlOperator) The two `@preg_*` calls run an
 * ADMINISTERED expression, which is input: an expression that does not compile
 * is the expected case, not an exceptional one, and it is handled by the false
 * return both calls already check. Without the operator PHP also emits a
 * warning, which on a fleet instance means a log line per note written against
 * a pattern somebody mistyped once.
 *
 * @spec openspec/changes/timeline-entries-are-records/specs/object-interactions/spec.md
 */
class ReferenceService {
	use OptionalPayloadString;

	/**
	 * How many codes one entry's text may resolve.
	 *
	 * Each match costs one object lookup, so a note pasted full of codes would
	 * otherwise turn one write into hundreds of reads. Past the cap the rest
	 * render as plain text, which is what they did before this change.
	 *
	 * @var integer
	 */
	public const MAX_MATCHES = 25;

	/**
	 * Constructor.
	 *
	 * @param ReferencePatternMapper $patterns   The administered patterns.
	 * @param EntryReferenceMapper   $references The recorded references.
	 * @param ObjectOperationsInterface          $objects    Resolves a code to an object the caller may read.
	 * @param LoggerInterface        $logger     Logger for the paths that decline rather than throw.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ReferencePatternMapper $patterns,
		private readonly EntryReferenceMapper $references,
		private readonly ObjectOperationsInterface $objects,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Every administered pattern.
	 *
	 * @param boolean $enabledOnly Whether to leave out the disabled ones.
	 *
	 * @return array<int, ReferencePattern> The declarations.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Pass-through of the mapper's
	 * narrowing flag; see ReferencePatternMapper::findAll() for why it is one
	 * read and not two.
	 *
	 * @spec openspec/changes/timeline-entries-are-records/specs/object-interactions/spec.md
	 */
	public function listPatterns(bool $enabledOnly = false): array {
		return $this->patterns->findAll(enabledOnly: $enabledOnly);
	}//end listPatterns()

	/**
	 * Declare a pattern, or rewrite the declaration that carries the name.
	 *
	 * @param array<string,mixed> $data slug, title, pattern, register, schema, targetProperty, urlTemplate, enabled.
	 *
	 * @return ReferencePattern The stored declaration.
	 *
	 * @throws TimelineValidationException When the slug is missing or the expression does not compile.
	 *
	 * @spec openspec/changes/timeline-entries-are-records/specs/object-interactions/spec.md
	 */
	public function declarePattern(array $data): ReferencePattern {
		$slug = $this->requiredSlug(data: $data);
		$this->requireCompilableBody(data: $data);

		$pattern = $this->patterns->findBySlug(slug: $slug);
		if ($pattern === null) {
			return $this->patterns->insert($this->filled(pattern: $this->newPattern(slug: $slug), data: $data));
		}

		return $this->patterns->update($this->filled(pattern: $pattern, data: $data));
	}//end declarePattern()

	/**
	 * The normalised slug of a declaration payload.
	 *
	 * @param array<string,mixed> $data The payload.
	 *
	 * @return string The lower-cased, trimmed slug.
	 *
	 * @throws TimelineValidationException When the slug is missing.
	 */
	private function requiredSlug(array $data): string {
		$slug = strtolower(trim((string)($data['slug'] ?? '')));
		if ($slug === '') {
			throw new TimelineValidationException(['slug' => 'A reference pattern needs a slug']);
		}

		return $slug;
	}//end requiredSlug()

	/**
	 * Refuse a declaration whose expression is missing or does not compile.
	 *
	 * @param array<string,mixed> $data The payload.
	 *
	 * @return void
	 *
	 * @throws TimelineValidationException When the expression is missing or does not compile.
	 */
	private function requireCompilableBody(array $data): void {
		$body = (string)($data['pattern'] ?? '');
		if (trim($body) === '') {
			throw new TimelineValidationException(['pattern' => 'A reference pattern needs an expression']);
		}

		if ($this->compiles(body: $body) === false) {
			throw new TimelineValidationException(['pattern' => 'That expression is not a usable pattern']);
		}
	}//end requireCompilableBody()

	/**
	 * A new, unsaved declaration carrying the slug.
	 *
	 * @param string $slug The normalised slug.
	 *
	 * @return ReferencePattern The declaration.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) Uuid::v4() is the standard utility pattern in this app
	 */
	private function newPattern(string $slug): ReferencePattern {
		$pattern = new ReferencePattern();
		$pattern->setUuid((string)Uuid::v4());
		$pattern->setSlug($slug);
		$pattern->setCreated(new DateTime());

		return $pattern;
	}//end newPattern()

	/**
	 * Write a declaration payload onto a pattern.
	 *
	 * @param ReferencePattern    $pattern The pattern to fill.
	 * @param array<string,mixed> $data    The payload.
	 *
	 * @return ReferencePattern The same pattern.
	 */
	private function filled(ReferencePattern $pattern, array $data): ReferencePattern {
		$pattern->setTitle($this->stringOrNull(data: $data, key: 'title'));
		$pattern->setPattern((string)($data['pattern'] ?? ''));
		$pattern->setRegister($this->stringOrNull(data: $data, key: 'register'));
		$pattern->setSchema($this->stringOrNull(data: $data, key: 'schema'));
		$pattern->setTargetProperty($this->stringOrNull(data: $data, key: 'targetProperty'));
		$pattern->setUrlTemplate($this->stringOrNull(data: $data, key: 'urlTemplate'));
		$pattern->setEnabled(($data['enabled'] ?? true) !== false);
		$pattern->setUpdated(new DateTime());

		return $pattern;
	}//end filled()

	/**
	 * Withdraw a pattern.
	 *
	 * References already recorded stay: they are what was written, and the
	 * link they carry still points where it pointed.
	 *
	 * @param string $slug The pattern name.
	 *
	 * @return boolean True when a declaration was removed.
	 *
	 * @spec openspec/changes/timeline-entries-are-records/specs/object-interactions/spec.md
	 */
	public function withdrawPattern(string $slug): bool {
		$pattern = $this->patterns->findBySlug(slug: $slug);
		if ($pattern === null) {
			return false;
		}

		$this->patterns->delete($pattern);

		return true;
	}//end withdrawPattern()

	/**
	 * The codes a text carries, with what each resolves to.
	 *
	 * An instance that declares no pattern returns nothing, and its text is
	 * therefore rendered exactly as it was written.
	 *
	 * @param string|null $text The text to read.
	 *
	 * @return array<int, array{code: string, patternSlug: string, targetUuid: string|null, url: string|null}> The matches.
	 *
	 * @spec openspec/changes/timeline-entries-are-records/specs/object-interactions/spec.md
	 */
	public function scan(?string $text): array {
		if ($this->isBlank(text: $text) === true) {
			return [];
		}

		$found = [];
		foreach ($this->uniqueCodes(text: $text) as [$pattern, $code]) {
			$found[] = $this->resolve(pattern: $pattern, code: $code);
			if (count($found) >= self::MAX_MATCHES) {
				return $found;
			}
		}

		return $found;
	}//end scan()

	/**
	 * Whether a text is missing or holds only whitespace.
	 *
	 * @param string|null $text The text.
	 *
	 * @return boolean True when there is nothing to read.
	 */
	private function isBlank(?string $text): bool {
		return is_string($text) === false || trim($text) === '';
	}//end isBlank()

	/**
	 * Each distinct code the enabled patterns find in a text, with its pattern.
	 *
	 * Lazy, so patterns after the match cap are never run.
	 *
	 * @param string $text The text to read.
	 *
	 * @return Generator<int, array{0: ReferencePattern, 1: string}> Pattern and code pairs.
	 */
	private function uniqueCodes(string $text): Generator {
		$seen = [];
		foreach ($this->patterns->findAll(enabledOnly: true) as $pattern) {
			foreach ($this->codesOf(pattern: $pattern, text: $text) as $code) {
				if ($code === '' || isset($seen[$code]) === true) {
					continue;
				}

				$seen[$code] = true;
				yield [$pattern, $code];
			}
		}
	}//end uniqueCodes()

	/**
	 * Every code one pattern matches in a text, in order.
	 *
	 * @param ReferencePattern $pattern The pattern.
	 * @param string           $text    The text to read.
	 *
	 * @return array<int, string> The matched codes; empty when the pattern does not run.
	 */
	private function codesOf(ReferencePattern $pattern, string $text): array {
		$matches = [];
		$compiled = $this->delimit(body: (string)$pattern->getPattern());
		if (@preg_match_all($compiled, $text, $matches) === false) {
			$this->logger->warning(
				'[ReferenceService] Pattern '.(string)$pattern->getSlug().' did not run; it is declared but unusable'
			);
			return [];
		}

		return array_map(static fn (mixed $code): string => (string)$code, ($matches[0] ?? []));
	}//end codesOf()

	/**
	 * Record what an entry's text points at, replacing what it pointed at before.
	 *
	 * Rewriting is a delete and a write rather than a merge, which is what
	 * makes REMOVING the code remove the reference: a merge would keep the
	 * reference to a code the sentence no longer carries.
	 *
	 * Only codes that RESOLVED are recorded. A code shaped like a case number
	 * that names no case is a link to nowhere, and recording it would put a
	 * dangling row on the other end of a graph people query.
	 *
	 * @param ReferenceSource $source The entry, its object and its text.
	 *
	 * @return array<int, EntryReference> The references now recorded for this entry.
	 *
	 * @spec openspec/changes/timeline-entries-are-records/specs/object-interactions/spec.md
	 */
	public function record(ReferenceSource $source): array {
		$this->references->deleteForEntry(entryUuid: $source->entryUuid);

		$written = [];
		foreach ($this->scan(text: $source->text) as $match) {
			if ($match['targetUuid'] !== null) {
				$written[] = $this->references->insert($this->referenceTo(source: $source, match: $match));
			}
		}

		return $written;
	}//end record()

	/**
	 * A new reference row from a source to one resolved match.
	 *
	 * @param ReferenceSource                                                                    $source The entry, its object and its text.
	 * @param array{code: string, patternSlug: string, targetUuid: string|null, url: string|null} $match  The resolved match.
	 *
	 * @return EntryReference The unsaved row.
	 */
	private function referenceTo(ReferenceSource $source, array $match): EntryReference {
		$reference = new EntryReference();
		$reference->setEntryUuid($source->entryUuid);
		$reference->setSourceUuid($source->sourceUuid);
		$reference->setTargetUuid($match['targetUuid']);
		$reference->setCode($match['code']);
		$reference->setPatternSlug($match['patternSlug']);
		$reference->setUrl($match['url']);
		$reference->setCreated(new DateTime());

		return $reference;
	}//end referenceTo()

	/**
	 * Everything one entry points at.
	 *
	 * @param string $entryUuid The entry.
	 *
	 * @return array<int, EntryReference> The rows.
	 *
	 * @spec openspec/changes/timeline-entries-are-records/specs/object-interactions/spec.md
	 */
	public function forEntry(string $entryUuid): array {
		return $this->references->findForEntry(entryUuid: $entryUuid);
	}//end forEntry()

	/**
	 * Forget what an entry pointed at.
	 *
	 * @param string $entryUuid The entry.
	 *
	 * @return integer The number of references removed.
	 *
	 * @spec openspec/changes/timeline-entries-are-records/specs/object-interactions/spec.md
	 */
	public function forget(string $entryUuid): int {
		return $this->references->deleteForEntry(entryUuid: $entryUuid);
	}//end forget()

	/**
	 * Resolve one matched code to an object and a url.
	 *
	 * The lookup goes through ObjectService with RBAC ON, so a code naming an
	 * object the writer may not read resolves to nothing: a reference is not a
	 * way to confirm that a case exists.
	 *
	 * @param ReferencePattern $pattern The pattern that matched.
	 * @param string           $code    The matched code.
	 *
	 * @return array{code: string, patternSlug: string, targetUuid: string|null, url: string|null} The match.
	 */
	private function resolve(ReferencePattern $pattern, string $code): array {
		$url = $this->urlFor(pattern: $pattern, code: $code);

		return [
			'code' => $code,
			'patternSlug' => (string)$pattern->getSlug(),
			'targetUuid' => $this->targetUuidOf(pattern: $pattern, code: $code),
			'url' => $url,
		];
	}//end resolve()

	/**
	 * The link a pattern's url template gives a code.
	 *
	 * @param ReferencePattern $pattern The pattern that matched.
	 * @param string           $code    The matched code.
	 *
	 * @return string|null The url, or null when the pattern has no template.
	 */
	private function urlFor(ReferencePattern $pattern, string $code): ?string {
		$template = $pattern->getUrlTemplate();
		if ($template === null) {
			return null;
		}

		return str_replace('{code}', rawurlencode($code), $template);
	}//end urlFor()

	/**
	 * The uuid of the object a code names, when the caller may read it.
	 *
	 * @param ReferencePattern $pattern The pattern that matched.
	 * @param string           $code    The matched code.
	 *
	 * @return string|null The uuid, or null when the code names nothing readable.
	 */
	private function targetUuidOf(ReferencePattern $pattern, string $code): ?string {
		try {
			$object = $this->objects->find(
				id: $code,
				options: new ObjectFindOptions(register: $pattern->getRegister(), schema: $pattern->getSchema(), render: false, audit: false)
			);
			if ($object !== null) {
				return (string)$object->getUuid();
			}
		} catch (Throwable $e) {
			// A code that names nothing is the ordinary case, not a failure:
			// somebody typed a number that is not a case number. It renders as
			// plain text and records nothing.
			return null;
		}

		return null;
	}//end targetUuidOf()

	/**
	 * Wrap an administered expression body in delimiters this service chooses.
	 *
	 * @param string $body The stored expression body.
	 *
	 * @return string The compilable expression.
	 */
	private function delimit(string $body): string {
		return '/'.str_replace('/', '\/', $body).'/u';
	}//end delimit()

	/**
	 * Whether an administered expression body compiles at all.
	 *
	 * @param string $body The expression body.
	 *
	 * @return boolean True when it can be used.
	 */
	private function compiles(string $body): bool {
		return (@preg_match($this->delimit(body: $body), '') !== false);
	}//end compiles()
}//end class
