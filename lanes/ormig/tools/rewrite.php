<?php
// Rewrites release-shaped openregister ObjectService calls to main's option-object API.
// usage: php rewrite.php [--apply] [--recv=REGEX] file...
// Prints one TSV line per candidate: status file:line method receiver detail
require __DIR__ . '/vendor/autoload.php';

use PhpParser\Lexer\Emulative;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;

$apply = false; $namedOnly = false; $order = null; $onlyMethod = null; $recv = '/objectService|ObjectService|objectStore|orObjects|OrObjects/'; $files = [];
foreach (array_slice($argv, 1) as $a) {
	if ($a === '--apply') { $apply = true; continue; }
	if ($a === '--named-only') { $namedOnly = true; continue; }
	if (str_starts_with($a, '--order=')) { $order = explode(',', substr($a, 8)); continue; }
	if (str_starts_with($a, '--method=')) { $onlyMethod = substr($a, 9); continue; }
	if (str_starts_with($a, '--recv=')) { $recv = substr($a, 7); continue; }
	$files[] = $a;
}

const V = 'OCA\\OpenRegister\\Kernel\\Contracts\\Value\\';
$OLD = [
	'find' => ['id', '_extend', 'files', 'register', 'schema', '_rbac', '_multitenancy', '_render', '_audit'],
	'findSilent' => ['id', '_extend', 'files', 'register', 'schema', '_rbac', '_multitenancy'],
	'saveObject' => ['object', 'extend', 'register', 'schema', 'uuid', '_rbac', '_multitenancy', 'silent', '_validation', 'uploadedFiles', 'currentUser', 'failIfExists', '_unowned', '_dedupOverride', '_owner'],
	'deleteObject' => ['uuid', 'register', 'schema', '_rbac', '_multitenancy', '_retentionSweep', 'currentUser', 'permanent'],
	'patchObject' => ['objectId', 'data', 'register', 'schema', '_rbac', '_multitenancy', 'currentUser'],
	'lockObject' => ['identifier', 'process', 'duration', 'advisory', 'runUuid', 'nodeId'],
	'unlockObject' => ['identifier', 'advisory', 'runUuid'],
	'saveObjects' => ['objects', 'register', 'schema', '_rbac', '_multitenancy', 'validation', 'events', 'deduplicateIds', 'enrich', '_audit'],
];
// Literal values equal to main's default for that slot; such args are dropped.
$DEFAULTS = [
	'_extend' => ['[]', 'array()'], 'extend' => ['[]', 'array()'], 'files' => ['false'], 'register' => ['null'], 'schema' => ['null'],
	'_render' => ['true'], '_audit' => ['true'], 'uuid' => ['null'],
	'silent' => ['false'], '_validation' => ['true'], 'uploadedFiles' => ['null'], 'currentUser' => ['null'], 'failIfExists' => ['false'],
	'_unowned' => ['false'], '_dedupOverride' => ['false'], '_owner' => ['null'], '_retentionSweep' => ['false'], 'permanent' => ['false'],
	'process' => ['null'], 'duration' => ['null'], 'advisory' => ['false'], 'runUuid' => ['null'], 'nodeId' => ['null'],
	'validation' => ['false'], 'events' => ['false'], 'deduplicateIds' => ['true'], 'enrich' => ['true'],
];

$lexer = new Emulative(['usedAttributes' => ['startLine', 'endLine', 'startFilePos', 'endFilePos', 'comments']]);
$parser = (new ParserFactory())->create(ParserFactory::PREFER_PHP7, $lexer);

function lineIndent(string $code, int $pos): string {
	$ls = strrpos(substr($code, 0, $pos), "\n");
	$ls = $ls === false ? 0 : $ls + 1;
	preg_match('/^[ \t]*/', substr($code, $ls), $m);
	return $m[0];
}
function lit(string $t): string { return strtolower(preg_replace('/\s+/', '', $t)); }

function newObj(string $cls, array $pairs, string $ind, bool $multi): string {
	$parts = [];
	foreach ($pairs as $k => $v) { if ($v !== null) $parts[] = "$k: $v"; }
	$inline = "new $cls(" . implode(', ', $parts) . ')';
	if (!$multi || strlen($inline) + strlen($ind) * 4 <= 110 || count($parts) < 2) return $inline;
	$in = $ind . "\t";
	return "new $cls(\n$in" . implode(",\n$in", array_map(fn($p) => str_replace("\n", "\n\t", $p), $parts)) . "\n$ind)";
}

$total = ['rewritten' => 0, 'skipped' => 0];
foreach ($files as $file) {
	$code = file_get_contents($file);
	for ($pass = 0; $pass < ($order === null ? 6 : 1); $pass++) {
		try { $ast = $parser->parse($code); } catch (\Throwable $e) { echo "PARSEERR\t$file\t" . $e->getMessage() . "\n"; continue 2; }
		$calls = (new NodeFinder())->find($ast, fn($n) => ($n instanceof Node\Expr\MethodCall || $n instanceof Node\Expr\NullsafeMethodCall || ($n instanceof Node\Expr\StaticCall && $n->class instanceof Node\Name && strtolower($n->class->toString()) === 'parent')) && $n->name instanceof Node\Identifier && isset($GLOBALS['OLD'][$n->name->toString()]));
		$edits = []; $needs = [];
		foreach ($calls as $c) {
			$m = $c->name->toString(); $old = $OLD[$m];
			if ($onlyMethod !== null && $m !== $onlyMethod) continue;
			$posOrder = $GLOBALS['order'] ?? null;
			$rv = $c instanceof Node\Expr\StaticCall ? $c->class : $c->var;
			$recvText = substr($code, $rv->getAttribute('startFilePos'), $rv->getAttribute('endFilePos') - $rv->getAttribute('startFilePos') + 1);
			$line = $c->getLine();
			$tag = "$file:$line\t$m\t" . preg_replace('/\s+/', ' ', $recvText);
			$map = []; $bad = null; $firstNamed = false;
			foreach ($c->args as $i => $a) {
				if (!$a instanceof Node\Arg || $a->unpack) { $bad = 'spread/placeholder'; break; }
				$t = substr($code, $a->value->getAttribute('startFilePos'), $a->value->getAttribute('endFilePos') - $a->value->getAttribute('startFilePos') + 1);
				if ($a->name !== null) {
					$n = $a->name->toString();
					if ($i === 0) $firstNamed = true;
					if (in_array($n, ['options', 'deletion', 'patch', 'request'], true)) { $bad = 'already-main'; break; }
					if (!in_array($n, $old, true)) { $bad = "unknown named arg $n"; break; }
					$map[$n] = ['t' => $t, 'node' => $a->value];
				} else {
					$po = $posOrder ?? $old;
					if (!isset($po[$i])) { $bad = 'too many positional'; break; }
					$map[$po[$i]] = ['t' => $t, 'node' => $a->value];
				}
			}
			if ($bad === 'already-main') continue;
			foreach ($c->args as $a) { if ($a instanceof Node\Arg && $a->value instanceof Node\Expr\New_ && $a->value->class instanceof Node\Name && preg_match('/^(ObjectFindOptions|ObjectSaveOptions|BulkSaveOptions|ObjectDeletion|ObjectPatch|LockRequest|UnlockRequest)$/', $a->value->class->getLast())) { continue 2; } }
			if ($namedOnly) { foreach ($c->args as $i => $a) { if ($i > 0 && $a instanceof Node\Arg && $a->name === null) { $bad = 'positional'; } } if ($bad === 'positional') { if ($pass === 0) echo "POSITIONAL\t$tag\n"; continue; } }
			if ($bad !== null) { if ($pass === 0) echo "SKIP\t$tag\t$bad\n"; $total['skipped']++; continue; }
			$first = $old[0];
			if (!isset($map[$first])) { if ($pass === 0) echo "SKIP\t$tag\tno first arg\n"; continue; }
			// Already main-shaped positional forms.
			$v0 = $map[$first]['node'];
			$isNew = fn($node, $classes) => $node instanceof Node\Expr\New_ && $node->class instanceof Node\Name && in_array($node->class->getLast(), $classes, true);
			if (in_array($m, ['deleteObject', 'patchObject', 'lockObject', 'unlockObject'], true) && $isNew($v0, ['ObjectDeletion', 'ObjectPatch', 'LockRequest', 'UnlockRequest'])) continue;
			if (in_array($m, ['deleteObject', 'patchObject', 'lockObject', 'unlockObject'], true) && $v0 instanceof Node\Expr\Variable && is_string($v0->name) && preg_match('/^(deletion|patch|request|lockRequest|unlockRequest|objectPatch|objectDeletion)$/i', $v0->name)) continue;
			$second = $old[1] ?? null;
			if ($second !== null && isset($map[$second])) {
				$n1 = $map[$second]['node'];
				if ($isNew($n1, ['ObjectFindOptions', 'ObjectSaveOptions', 'BulkSaveOptions'])) continue;
				if ($n1 instanceof Node\Expr\Variable && is_string($n1->name) && stripos($n1->name, 'option') !== false) continue;
			}
			// Drop literal defaults.
			foreach ($map as $k => $v) {
				if ($k === $first || ($m === 'patchObject' && $k === 'data')) continue;
				if (isset($DEFAULTS[$k]) && in_array(lit($v['t']), $DEFAULTS[$k], true)) unset($map[$k]);
			}
			$flagNames = ['_rbac', '_multitenancy', '_retentionSweep'];
			if ($m === 'lockObject') $flagNames[] = 'advisory';
			$optKeys = array_diff(array_keys($map), array_merge([$first], $flagNames));
			if ($m === 'patchObject') $optKeys = array_diff($optKeys, ['data']);
			$alwaysObj = in_array($m, ['deleteObject', 'patchObject', 'lockObject', 'unlockObject'], true);
			if (!$alwaysObj && $optKeys === []) {
				// Nothing beyond flags: valid on main as long as flags are named.
				$positionalFlags = false;
				foreach ($c->args as $i => $a) {
					if ($a->name === null && $i > 0) $positionalFlags = true;
					// A named release argument main does not know, even when it only carried a default.
					if ($a->name !== null && !in_array($a->name->toString(), array_merge([$first, '_rbac', '_multitenancy'], $flagNames), true)) $positionalFlags = true;
				}
				if (!$positionalFlags) continue;
			}
			if (!preg_match($recv, $recvText)) { if ($pass === 0) echo "RECV\t$tag\t" . implode(',', array_keys($map)) . "\n"; continue; }
			if ($m === 'saveObjects' && (isset($map['deduplicateIds']) || isset($map['enrich']))) { if ($pass === 0) echo "BEHAV\t$tag\tdeduplicateIds/enrich not on main\n"; continue; }

			$open = strpos($code, '(', $c->name->getAttribute('endFilePos') + 1);
			$close = $c->getAttribute('endFilePos');
			$argText = substr($code, $open + 1, $close - $open - 1);
			$multi = str_contains($argText, "\n");
			$ind = $multi ? lineIndent($code, $c->args[0]->getAttribute('startFilePos')) : '';
			$closeInd = $multi ? lineIndent($code, $close) : '';
			$g = fn($k) => $map[$k]['t'] ?? null;
			$target = ($g('register') !== null || $g('schema') !== null)
				? newObj('ObjectFindTarget', ['register' => $g('register'), 'schema' => $g('schema')], $ind . "\t", $multi) : null;
			$use = [];
			$args = [];
			$firstText = ($firstNamed && !$alwaysObj ? "$first: " : '') . $map[$first]['t'];
			switch ($m) {
				case 'find': case 'findSilent':
					$args[] = $firstText;
					$o = newObj('ObjectFindOptions', ['register' => $g('register'), 'schema' => $g('schema'), 'extend' => $g('_extend'), 'files' => $g('files'), 'render' => $g('_render'), 'audit' => $g('_audit')], $ind, $multi);
					if ($optKeys !== []) { $args[] = ($firstNamed ? 'options: ' : '') . $o; $use[] = V . 'ObjectFindOptions'; }
					break;
				case 'saveObject':
					$args[] = $firstText;
					$policy = ($g('silent') ?? $g('_validation') ?? $g('failIfExists') ?? $g('_dedupOverride')) !== null
						? newObj('SavePolicy', ['silent' => $g('silent'), 'validation' => $g('_validation'), 'failIfExists' => $g('failIfExists'), 'dedupOverride' => $g('_dedupOverride')], $ind . "\t", $multi) : null;
					$actor = ($g('currentUser') ?? $g('_owner') ?? $g('_unowned')) !== null
						? newObj('SaveActor', ['currentUser' => $g('currentUser'), 'owner' => $g('_owner'), 'unowned' => $g('_unowned')], $ind . "\t", $multi) : null;
					$o = newObj('ObjectSaveOptions', ['target' => $target, 'uuid' => $g('uuid'), 'extend' => $g('extend'), 'uploadedFiles' => $g('uploadedFiles'), 'policy' => $policy, 'actor' => $actor], $ind, $multi);
					if ($optKeys !== []) { $args[] = ($firstNamed ? 'options: ' : '') . $o; $use[] = V . 'ObjectSaveOptions'; }
					if ($target) $use[] = V . 'ObjectFindTarget';
					if ($policy) $use[] = V . 'SavePolicy';
					if ($actor) $use[] = V . 'SaveActor';
					break;
				case 'deleteObject':
					$args[] = newObj('ObjectDeletion', ['uuid' => $g('uuid'), 'target' => $target, 'currentUser' => $g('currentUser'), 'permanent' => $g('permanent')], $ind, $multi);
					$use[] = V . 'ObjectDeletion'; if ($target) $use[] = V . 'ObjectFindTarget';
					break;
				case 'patchObject':
					$args[] = newObj('ObjectPatch', ['objectId' => $g('objectId'), 'data' => $g('data'), 'target' => $target, 'currentUser' => $g('currentUser')], $ind, $multi);
					$use[] = V . 'ObjectPatch'; if ($target) $use[] = V . 'ObjectFindTarget';
					break;
				case 'lockObject':
					$step = ($g('runUuid') ?? $g('nodeId')) !== null ? newObj('LockRunStep', ['runUuid' => $g('runUuid') ?? 'null', 'nodeId' => $g('nodeId') ?? 'null'], $ind . "\t", $multi) : null;
					$args[] = newObj('LockRequest', ['identifier' => $g('identifier'), 'process' => $g('process'), 'duration' => $g('duration'), 'runStep' => $step], $ind, $multi);
					$use[] = V . 'LockRequest'; if ($step) $use[] = V . 'LockRunStep';
					break;
				case 'unlockObject':
					$args[] = newObj('UnlockRequest', ['identifier' => $g('identifier'), 'advisory' => $g('advisory'), 'runUuid' => $g('runUuid')], $ind, $multi);
					$use[] = V . 'UnlockRequest';
					break;
				case 'saveObjects':
					$args[] = $firstText;
					$def = $target !== null ? newObj('BulkBatchDefaults', ['register' => $g('register'), 'schema' => $g('schema')], $ind . "\t", $multi) : null;
					$o = newObj('BulkSaveOptions', ['defaults' => $def, 'validation' => $g('validation'), 'events' => $g('events'), 'audit' => $g('_audit')], $ind, $multi);
					if ($optKeys !== []) { $args[] = ($firstNamed ? 'options: ' : '') . $o; $use[] = V . 'BulkSaveOptions'; } if ($def) $use[] = V . 'BulkBatchDefaults';
					break;
			}
			foreach ($flagNames as $f) { if ($g($f) !== null) $args[] = "$f: " . $g($f); }
			$new = $multi ? "(\n$ind" . implode(",\n$ind", $args) . "\n$closeInd)" : '(' . implode(', ', $args) . ')';
			$edits[] = [$open, $close, $new, $use, $tag];
		}
		// Apply innermost-first: skip edits that contain another edit this pass.
		$take = [];
		foreach ($edits as $e) {
			$contains = false;
			foreach ($edits as $o) { if ($o !== $e && $o[0] > $e[0] && $o[1] < $e[1]) { $contains = true; break; } }
			if (!$contains) $take[] = $e;
		}
		if ($take === []) break;
		usort($take, fn($a, $b) => $b[0] <=> $a[0]);
		$uses = [];
		foreach ($take as $e) {
			$code = substr($code, 0, $e[0]) . $e[2] . substr($code, $e[1] + 1);
			$uses = array_merge($uses, $e[3]);
			echo "REWRITE\t{$e[4]}\n"; $total['rewritten']++;
		}
		// Add imports.
		foreach (array_unique($uses) as $fq) {
			$short = substr($fq, strrpos($fq, '\\') + 1);
			if (preg_match('/^use\s+' . preg_quote($fq, '/') . '\s*;/m', $code)) continue;
			if (preg_match('/^use\s+[^;]*\\\\' . $short . '\s*;/m', $code)) { echo "IMPORTCONFLICT\t$file\t$short\n"; continue; }
			if (preg_match_all('/^use\s+[A-Za-z0-9_\\\\]+(\s+as\s+\w+)?\s*;[ \t]*$/m', $code, $mm, PREG_OFFSET_CAPTURE)) {
				// Insert alphabetically among existing imports.
				$pos = null;
				foreach ($mm[0] as $u) { if (strcasecmp(trim(substr($u[0], 4)), $fq . ';') > 0) { $pos = $u[1]; break; } }
				if ($pos === null) { $last = end($mm[0]); $pos = $last[1] + strlen($last[0]); $code = substr($code, 0, $pos) . "\nuse $fq;" . substr($code, $pos); }
				else { $code = substr($code, 0, $pos) . "use $fq;\n" . substr($code, $pos); }
			} else {
				$code = preg_replace('/^(namespace\s+[^;]+;\s*\n)/m', "$1\nuse $fq;\n", $code, 1);
			}
		}
	}
	if ($apply) file_put_contents($file, $code);
}
fwrite(STDERR, json_encode($total) . "\n");
