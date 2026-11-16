<?php
// Rewrites test doubles of openregister's ObjectService (fake methods, interfaces and
// willReturnCallback closures) from the release signature to main's option-object signature.
// usage: php rewrite-doubles.php [--apply] [--recv=REGEX] file...
require __DIR__ . '/vendor/autoload.php';

use PhpParser\Lexer\Emulative;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use PhpParser\NodeVisitor\ParentConnectingVisitor;
use PhpParser\NodeTraverser;

$apply = false; $support = null; $cbOnly = false; $recv = '/bjectService|objects|Objects|objectStore|orService|\$service\b|\$os\b|\$store\b/'; $files = [];
foreach (array_slice($argv, 1) as $a) {
	if ($a === '--apply') { $apply = true; continue; }
	if (str_starts_with($a, '--recv=')) { $recv = substr($a, 7); continue; }
	if (str_starts_with($a, '--support=')) { $support = substr($a, 10); continue; }
	if ($a === '--cb-only') { $cbOnly = true; continue; }
	$files[] = $a;
}
const V = 'OCA\\OpenRegister\\Kernel\\Contracts\\Value\\';
// method => [old slots, new head (class, var, nullable default), flags after head, accessor per old slot]
$SPEC = [
	'find' => ['old' => ['id', '_extend', 'files', 'register', 'schema', '_rbac', '_multitenancy', '_render', '_audit'], 'keepFirst' => true,
		'head' => ['ObjectFindOptions', 'options', '?ObjectFindOptions $options = null'], 'flags' => ['_rbac' => 'bool %s = true', '_multitenancy' => 'bool %s = true'],
		'acc' => ['_extend' => '$options?->extend', 'files' => '$options?->files', 'register' => '$options?->register', 'schema' => '$options?->schema', '_render' => '$options?->render', '_audit' => '$options?->audit']],
	'findSilent' => ['old' => ['id', '_extend', 'files', 'register', 'schema', '_rbac', '_multitenancy'], 'keepFirst' => true,
		'head' => ['ObjectFindOptions', 'options', '?ObjectFindOptions $options = null'], 'flags' => ['_rbac' => 'bool %s = true', '_multitenancy' => 'bool %s = true'],
		'acc' => ['_extend' => '$options?->extend', 'files' => '$options?->files', 'register' => '$options?->register', 'schema' => '$options?->schema']],
	'saveObject' => ['old' => ['object', 'extend', 'register', 'schema', 'uuid', '_rbac', '_multitenancy', 'silent', '_validation', 'uploadedFiles', 'currentUser', 'failIfExists', '_unowned', '_dedupOverride', '_owner'], 'keepFirst' => true,
		'head' => ['ObjectSaveOptions', 'options', 'ObjectSaveOptions $options = new ObjectSaveOptions()'], 'flags' => ['_rbac' => 'bool %s = true', '_multitenancy' => 'bool %s = true'],
		'acc' => ['extend' => '$options->extend', 'register' => '$options->target->register', 'schema' => '$options->target->schema', 'uuid' => '$options->uuid', 'silent' => '$options->policy->silent',
			'_validation' => '$options->policy->validation', 'uploadedFiles' => '$options->uploadedFiles', 'currentUser' => '$options->actor->currentUser', 'failIfExists' => '$options->policy->failIfExists',
			'_unowned' => '$options->actor->unowned', '_dedupOverride' => '$options->policy->dedupOverride', '_owner' => '$options->actor->owner']],
	'deleteObject' => ['old' => ['uuid', 'register', 'schema', '_rbac', '_multitenancy', '_retentionSweep', 'currentUser', 'permanent'], 'keepFirst' => false,
		'head' => ['ObjectDeletion', 'deletion', 'ObjectDeletion $deletion'], 'flags' => ['_rbac' => 'bool %s = true', '_multitenancy' => 'bool %s = true', '_retentionSweep' => 'bool %s = false'],
		'acc' => ['uuid' => '$deletion->uuid', 'register' => '$deletion->target->register', 'schema' => '$deletion->target->schema', 'currentUser' => '$deletion->currentUser', 'permanent' => '$deletion->permanent']],
	'patchObject' => ['old' => ['objectId', 'data', 'register', 'schema', '_rbac', '_multitenancy', 'currentUser'], 'keepFirst' => false,
		'head' => ['ObjectPatch', 'patch', 'ObjectPatch $patch'], 'flags' => ['_rbac' => 'bool %s = true', '_multitenancy' => 'bool %s = true'],
		'acc' => ['objectId' => '$patch->objectId', 'data' => '$patch->data', 'register' => '$patch->target->register', 'schema' => '$patch->target->schema', 'currentUser' => '$patch->currentUser']],
	'lockObject' => ['old' => ['identifier', 'process', 'duration', 'advisory', 'runUuid', 'nodeId'], 'keepFirst' => false,
		'head' => ['LockRequest', 'request', 'LockRequest $request'], 'flags' => ['advisory' => 'bool %s = false'],
		'acc' => ['identifier' => '$request->identifier', 'process' => '$request->process', 'duration' => '$request->duration', 'runUuid' => '$request->runUuid', 'nodeId' => '$request->nodeId']],
	'unlockObject' => ['old' => ['identifier', 'advisory', 'runUuid'], 'keepFirst' => false,
		'head' => ['UnlockRequest', 'request', 'UnlockRequest $request'], 'flags' => [],
		'acc' => ['identifier' => '$request->identifier', 'advisory' => '$request->advisory', 'runUuid' => '$request->runUuid']],
	'saveObjects' => ['old' => ['objects', 'register', 'schema', '_rbac', '_multitenancy', 'validation', 'events', 'deduplicateIds', 'enrich', '_audit'], 'keepFirst' => true,
		'head' => ['BulkSaveOptions', 'options', 'BulkSaveOptions $options = new BulkSaveOptions()'], 'flags' => ['_rbac' => 'bool %s = true', '_multitenancy' => 'bool %s = true'],
		'acc' => ['register' => '$options->defaults->register', 'schema' => '$options->defaults->schema', 'validation' => '$options->validation', 'events' => '$options->events', '_audit' => '$options->audit', 'deduplicateIds' => 'true', 'enrich' => 'true']],
];
// Main-shaped already?
$NEWNAMES = ['options', 'deletion', 'patch', 'request'];

$lexer = new Emulative(['usedAttributes' => ['startLine', 'endLine', 'startFilePos', 'endFilePos', 'comments']]);
$parser = (new ParserFactory())->create(ParserFactory::PREFER_PHP7, $lexer);

function src(string $code, Node $n): string { return substr($code, $n->getAttribute('startFilePos'), $n->getAttribute('endFilePos') - $n->getAttribute('startFilePos') + 1); }
function lineIndent(string $code, int $pos): string {
	$ls = strrpos(substr($code, 0, $pos), "\n"); $ls = $ls === false ? 0 : $ls + 1;
	preg_match('/^[ \t]*/', substr($code, $ls), $m); return $m[0];
}

/**
 * Plan a signature rewrite. $byName: map declared params to old slots by name (fakes);
 * otherwise by position (mock callbacks). Returns null when not applicable, or a string reason.
 */
function plan(array $spec, array $params, string $code, bool $byName, bool $isCallback) {
	global $NEWNAMES;
	$old = $spec['old'];
	if ($params === []) return null;
	$tail = null;
	$last = end($params);
	if ($last->variadic && count($params) > 2 || ($last->variadic && count($params) === 2 && !$spec['keepFirst'])) {
		// Named parameters followed by an ignored rest: plan the named ones, keep the rest last.
		$tail = src($code, $last);
		$params = array_slice($params, 0, -1);
	}
	foreach ($params as $i => $p) {
		if (in_array($p->var->name, $NEWNAMES, true)) return null;
		if ($p->variadic) {
			if ($i !== count($params) - 1 || $i > 1) return 'variadic';
			if ($i === 1 && !$spec['keepFirst']) return 'variadic';
			return ['variadic' => '$' . $p->var->name];
		}
	}
	$slots = [];
	foreach ($params as $i => $p) {
		$name = $p->var->name;
		if ($byName) {
			$s = ($i === 0 && !in_array($old[0], array_map(fn($q) => $q->var->name, $params), true)) ? 0 : array_search($name, $old, true);
			if ($s === false) {
				if ($i === 0) { $s = 0; }
				elseif (isset($old[$i]) && (str_contains(ltrim($old[$i], '_'), ltrim($name, '_')) || str_contains(ltrim($name, '_'), ltrim($old[$i], '_'))) && !in_array($old[$i], array_map(fn($q) => $q->var->name, $params), true)) { $s = $i; }
				else return "unmatched param \$$name";
			}
		} else {
			if (!isset($old[$i])) return 'too many params';
			$s = $i;
		}
		$slots[$s] = $p;
	}
	if (in_array('register', $old, true) && in_array('_extend', $old, true)) {
		// A find() that names neither register, schema, files, render nor audit is a mapper's.
		$named = array_map(fn($k) => $old[$k], array_keys($slots));
		if (array_intersect($named, ['register', 'schema', 'files', '_render', '_audit']) === [] && count($slots) > 1) return null;
	}
	if ($byName) {
		$extra = array_diff(array_keys($slots), [0]);
		if ($extra === []) {
			// Only the first param: fine for find/save; request-shaped methods still change type.
			if ($spec['keepFirst']) return null;
		}
	} else {
		if (count($params) < 2 && $spec['keepFirst']) return null;
	}
	$first = $old[0];
	$sig = []; $pro = [];
	if ($spec['keepFirst']) {
		$sig[] = isset($slots[0]) ? src($code, $slots[0]) : '$' . $first;
	}
	$needHead = !$spec['keepFirst'] || array_diff(array_keys($slots), array_merge([0], array_keys(array_intersect($old, array_keys($spec['flags']))))) !== [] || !$isCallback;
	$sig[] = $spec['head'][2];
	$flagsUsed = [];
	foreach ($spec['flags'] as $fname => $fmt) {
		$s = array_search($fname, $old, true);
		$var = isset($slots[$s]) ? '$' . $slots[$s]->var->name : '$' . $fname;
		if (!$isCallback || isset($slots[$s])) $flagsUsed[] = sprintf($fmt, $var);
	}
	// Callbacks need flags positioned; trailing unused flags can be dropped.
	foreach ($flagsUsed as $f) $sig[] = $f;
	if ($tail !== null) {
		if ($isCallback) { foreach ($spec['flags'] as $fname => $fmt) { $q = sprintf($fmt, '$' . $fname); if (!in_array($q, $sig, true) && !preg_grep('/\\$' . $fname . '\b/', $sig)) $sig[] = $q; } }
		$sig[] = $tail;
	}
	foreach ($slots as $s => $p) {
		$oname = $old[$s];
		if ($s === 0 && $spec['keepFirst']) continue;
		if (isset($spec['flags'][$oname])) continue;
		$acc = $spec['acc'][$oname] ?? null;
		if ($acc === null) return "no accessor for $oname";
		$def = $p->default !== null ? src($code, $p->default) : null;
		if ($def !== null && strtolower($def) !== 'null' && $acc !== 'true') $acc .= " ?? $def";
		$pro[] = ['$' . $p->var->name, $acc];
	}
	return ['sig' => $sig, 'pro' => $pro, 'use' => V . $spec['head'][0]];
}

$total = 0;
foreach ($files as $file) {
	$code = file_get_contents($file);
	try { $ast = $parser->parse($code); } catch (\Throwable $e) { echo "PARSEERR\t$file\n"; continue; }
	$tr = new NodeTraverser(); $tr->addVisitor(new ParentConnectingVisitor()); $ast = $tr->traverse($ast);
	$edits = []; $uses = [];
	$finder = new NodeFinder();
	// (A) method declarations
	foreach ($cbOnly ? [] : $finder->findInstanceOf($ast, Node\Stmt\ClassMethod::class) as $m) {
		$name = $m->name->toString();
		if (!isset($SPEC[$name])) continue;
		$tag = "$file:" . $m->getLine() . "\t$name";
		// A double of an app class or interface keeps that type's own signature.
		$cls = $m->getAttribute('parent');
		if ($cls instanceof Node\Stmt\ClassLike) {
			$types = [];
			if (($cls instanceof Node\Stmt\Class_ || $cls instanceof Node\Stmt\Interface_) && $cls->extends !== null) $types = array_merge($types, is_array($cls->extends) ? $cls->extends : [$cls->extends]);
			if ($cls instanceof Node\Stmt\Class_) $types = array_merge($types, $cls->implements);
			$appType = null;
			foreach ($types as $t) {
				$short = $t->getFirst();
				$fq = preg_match('/^use\s+([\\\\\w]+\\\\' . preg_quote($short, '/') . ')\s*;/m', $code, $um) ? $um[1] : $t->toString();
				if (str_starts_with(ltrim($fq, '\\'), 'OCA\\') && !str_contains($fq, 'OpenRegister') && !str_contains($fq, '\\Tests\\')) $appType = $fq;
			}
			if ($appType !== null) { echo "APPTYPE\t$tag\t$appType\n"; continue; }
			// A plain store with no parent type is the test's own helper, called by the test with its own signature.
			if ($types === [] && $cls instanceof Node\Stmt\Class_ && getenv('SKIP_UNTYPED') !== false && getenv('SKIP_UNTYPED') !== '' && preg_match(getenv('SKIP_UNTYPED'), $file) === 1) { echo "UNTYPED\t$tag\n"; continue; }
		}
		$plan = plan($SPEC[$name], $m->params, $code, true, false);
		if ($plan === null) continue;
		if (is_string($plan)) { echo "SKIPDECL\t$tag\t$plan\n"; continue; }
		if (isset($plan['variadic'])) {
			if ($m->stmts === null || $support === null) continue;
			// A rest that is spread into another call is passed through untouched.
			$bodyStart = strpos($code, '{', end($m->params)->getAttribute('endFilePos'));
			if (str_contains(substr($code, $bodyStart, $m->getAttribute('endFilePos') - $bodyStart), '...' . $plan['variadic'] . ')')) continue;
			$brace = strpos($code, '{', end($m->params)->getAttribute('endFilePos'));
			$bodyInd = ($m->stmts !== [] && $m->stmts[0]->getLine() !== $m->getLine()) ? lineIndent($code, $m->stmts[0]->getAttribute('startFilePos')) : lineIndent($code, $m->getAttribute('startFilePos')) . "\t";
			$v = $plan['variadic'];
			$declBody = substr($code, $brace, $m->getAttribute('endFilePos') - $brace);
			if (str_contains($declBody, 'LegacyObjectArgs::')) continue;
			$line = (preg_match('/' . preg_quote($v, '/') . '\\[\\s*\\d/', $declBody) === 1 && preg_match('/' . preg_quote($v, '/') . "\\[\\s*'/", $declBody) !== 1 && count($m->params) === 1)
				? "$v = LegacyObjectArgs::positional(method: '$name', args: $v, trim: true);"
				: "$v = LegacyObjectArgs::flatten(args: $v);";
			$edits[] = [$brace + 1, $brace, "\n$bodyInd$line"];
			$uses[] = $support; echo "VARIADIC\t$tag\n"; $total++;
			continue;
		}
		$open = strpos($code, '(', $m->name->getAttribute('endFilePos') + 1);
		$close = $m->params === [] ? $open + 1 : null;
		// find the ')' closing the params: after last param end
		$lastEnd = end($m->params)->getAttribute('endFilePos');
		$close = strpos($code, ')', $lastEnd + 1);
		$between = substr($code, $open + 1, $close - $open - 1);
		$multi = str_contains($between, "\n");
		$ind = $multi ? lineIndent($code, $m->params[0]->getAttribute('startFilePos')) : '';
		$closeInd = $multi ? lineIndent($code, $close) : '';
		$newSig = $multi ? "(\n$ind" . implode(",\n$ind", $plan['sig']) . "\n$closeInd)" : '(' . implode(', ', $plan['sig']) . ')';
		$edits[] = [$open, $close, $newSig];
		if ($m->stmts !== null && $plan['pro'] !== []) {
			$brace = strpos($code, '{', $close);
			$bodyInd = $m->stmts !== [] ? lineIndent($code, $m->stmts[0]->getAttribute('startFilePos')) : lineIndent($code, $m->getAttribute('startFilePos')) . "\t";
			if ($m->stmts !== [] && $m->stmts[0]->getLine() === $m->getLine()) $bodyInd = lineIndent($code, $m->getAttribute('startFilePos')) . "\t";
			$lines = '';
			foreach ($plan['pro'] as [$v, $acc]) $lines .= "\n$bodyInd$v = $acc;";
			$edits[] = [$brace + 1, $brace, $lines];
		}
		// parent::<same>() handed the release's locals on; it now hands on the option object.
		if ($m->stmts !== null) {
			$declared = array_map(fn($q) => $q->var->name, $m->params);
			foreach ($finder->find($m->stmts, fn($n) => (($n instanceof Node\Expr\StaticCall && $n->class instanceof Node\Name && strtolower($n->class->toString()) === 'parent') || $n instanceof Node\Expr\MethodCall) && $n->name instanceof Node\Identifier && $n->name->toString() === $name) as $pc) {
				$ok = $pc->args !== [];
				foreach ($pc->args as $pi => $pa) {
					if (!$pa instanceof Node\Arg || $pa->name !== null) { $ok = false; continue; }
					// The first argument may be any expression when the method keeps its first parameter.
					if ($pi === 0 && $SPEC[$name]['keepFirst']) continue;
					if (!$pa->value instanceof Node\Expr\Variable || !in_array($pa->value->name, $declared, true)) $ok = false;
				}
				if (!$ok) continue;
				$head = '$' . $SPEC[$name]['head'][1];
				// A fake that assigns the uuid itself hands that uuid on.
				$uuidVar = isset($slotVars) ? null : null;
				$bodyText = substr($code, $m->getAttribute('startFilePos'), $m->getAttribute('endFilePos') - $m->getAttribute('startFilePos'));
				if ($name === 'saveObject' && preg_match_all('/\$uuid\s*=[^=]/', $bodyText) >= 1 && in_array('uuid', $declared, true)) {
					$head = 'new ObjectSaveOptions(target: $options->target, uuid: $uuid, extend: $options->extend, uploadedFiles: $options->uploadedFiles, policy: $options->policy, actor: $options->actor)';
				}
				$passed = [];
				if ($SPEC[$name]['keepFirst']) $passed[] = src($code, $pc->args[0]->value);
				$passed[] = $head;
				foreach ($pc->args as $pa) { if ($pa->value instanceof Node\Expr\Variable && in_array($pa->value->name, ['_rbac', '_multitenancy'], true)) $passed[] = '$' . $pa->value->name; }
				$po = strpos($code, '(', $pc->name->getAttribute('endFilePos'));
				$edits[] = [$po, $pc->getAttribute('endFilePos'), '(' . implode(', ', $passed) . ')'];
			}
		}
		// func_get_args() in the body meant the release's arguments, which now live in the locals above.
		if ($m->stmts !== null) {
			$oldList = '[' . implode(', ', array_map(fn($q) => '$' . $q->var->name, array_filter($m->params, fn($q) => !$q->variadic))) . ']';
			foreach ($finder->find($m->stmts, fn($n) => $n instanceof Node\Expr\FuncCall && $n->name instanceof Node\Name && strtolower($n->name->toString()) === 'func_get_args') as $fg) {
				$edits[] = [$fg->getAttribute('startFilePos'), $fg->getAttribute('endFilePos'), $oldList];
			}
		}
		$uses[] = $plan['use'];
		echo "DECL\t$tag\n"; $total++;
	}
	// (B) willReturnCallback closures on ->method('X')
	foreach ($finder->findInstanceOf($ast, Node\Expr\MethodCall::class) as $c) {
		if (!$c->name instanceof Node\Identifier || $c->name->toString() !== 'willReturnCallback') continue;
		$arg = $c->args[0]->value ?? null;
		if (!$arg instanceof Node\Expr\Closure && !$arg instanceof Node\Expr\ArrowFunction) continue;
		// walk the chain for ->method('X') and its root receiver
		$n = $c->var; $method = null; $root = null;
		while ($n instanceof Node\Expr\MethodCall) {
			if ($n->name instanceof Node\Identifier && $n->name->toString() === 'method' && isset($n->args[0]) && $n->args[0]->value instanceof Node\Scalar\String_) $method = $n->args[0]->value->value;
			$root = $n->var; $n = $n->var;
		}
		if ($method === null || !isset($SPEC[$method])) continue;
		$recvText = src($code, $root);
		$tag = "$file:" . $c->getLine() . "\t$method\t$recvText";
		if (!preg_match($recv, $recvText)) { echo "RECV\t$tag\n"; continue; }
		// A mock of a class that is not openregister's object service keeps its own signature.
		if (preg_match('/' . preg_quote($recvText, '/') . '\\s*=\\s*\\$this->createMock\\(\\s*(?:originalClassName:\\s*)?[\'"]?\\\\?([\\w\\\\]+?)(?:::class|[\'"])/', $code, $cm) === 1
			&& preg_match('/ObjectService|ObjectServiceInterface/', $cm[1]) !== 1) { echo "NOTOR\t$tag\t{$cm[1]}\n"; continue; }
		$plan = plan($SPEC[$method], $arg->params, $code, true, true);
		if (is_string($plan) && str_starts_with($plan, 'unmatched')) $plan = plan($SPEC[$method], $arg->params, $code, false, true);
		if ($plan === null) continue;
		if (is_string($plan)) { echo "SKIPCB\t$tag\t$plan\n"; continue; }
		if (isset($plan['variadic'])) {
			if (!$arg instanceof Node\Expr\Closure || $support === null) { echo "SKIPCB\t$tag\tvariadic arrow\n"; continue; }
			$brace = strpos($code, '{', end($arg->params)->getAttribute('endFilePos'));
			$bodyInd = $arg->stmts !== [] ? lineIndent($code, $arg->stmts[0]->getAttribute('startFilePos')) : lineIndent($code, $arg->getAttribute('startFilePos')) . "\t";
			$v = $plan['variadic'];
			// A mock hands its callback every argument by position, so give it the release's positions;
			// a callback reading its rest by name reads what a variadic mocked method was called with.
			$cbBody = substr($code, $brace, $arg->getAttribute('endFilePos') - $brace);
			if (str_contains($cbBody, 'LegacyObjectArgs::')) continue;
			if (preg_match('/' . preg_quote($v, '/') . "\\[\\s*'/", $cbBody) === 1) {
				$line = "$v = LegacyObjectArgs::flatten(args: $v);";
			} elseif (count($arg->params) === 2) {
				$f = '$' . $arg->params[0]->var->name;
				$line = "$v = array_slice(LegacyObjectArgs::positional(method: '$method', args: [$f, ...$v]), 1);";
			} else {
				$line = "$v = LegacyObjectArgs::positional(method: '$method', args: $v);";
			}
			$edits[] = [$brace + 1, $brace, "\n$bodyInd$line"];
			$uses[] = $support; echo "VARIADIC\t$tag\n"; $total++;
			continue;
		}
		$open = strpos($code, '(', $arg->getAttribute('startFilePos'));
		$close = strpos($code, ')', end($arg->params)->getAttribute('endFilePos') + 1);
		$newSig = '(' . implode(', ', $plan['sig']) . ')';
		if ($arg instanceof Node\Expr\Closure) {
			$edits[] = [$open, $close, $newSig];
			if ($plan['pro'] !== []) {
				$brace = strpos($code, '{', $close);
				$bodyInd = $arg->stmts !== [] ? lineIndent($code, $arg->stmts[0]->getAttribute('startFilePos')) : lineIndent($code, $arg->getAttribute('startFilePos')) . "\t";
				$lines = '';
				foreach ($plan['pro'] as [$v, $acc]) $lines .= "\n$bodyInd$v = $acc;";
				$edits[] = [$brace + 1, $brace, $lines];
			}
		} else {
			$edits[] = [$open, $close, $newSig];
			$map = []; foreach ($plan['pro'] as [$v, $acc]) $map[substr($v, 1)] = $acc;
			foreach ($finder->findInstanceOf($arg->expr, Node\Expr\Variable::class) as $v) {
				if (is_string($v->name) && isset($map[$v->name])) $edits[] = [$v->getAttribute('startFilePos'), $v->getAttribute('endFilePos'), '(' . $map[$v->name] . ')'];
			}
		}
		$uses[] = $plan['use'];
		echo "CB\t$tag\n"; $total++;
	}
	if ($edits === []) continue;
	usort($edits, fn($a, $b) => $b[0] <=> $a[0] ?: $b[1] <=> $a[1]);
	foreach ($edits as $e) {
		if ($e[1] < $e[0]) $code = substr($code, 0, $e[0]) . $e[2] . substr($code, $e[0]);
		else $code = substr($code, 0, $e[0]) . $e[2] . substr($code, $e[1] + 1);
	}
	foreach (array_unique($uses) as $fq) {
		$short = substr($fq, strrpos($fq, '\\') + 1);
		if (preg_match('/^use\s+' . preg_quote($fq, '/') . '\s*;/m', $code)) continue;
		if (preg_match('/^namespace\s+' . preg_quote(substr($fq, 0, strrpos($fq, '\\')), '/') . '\s*;/m', $code)) continue;
		if (preg_match_all('/^use\s+[A-Za-z0-9_\\\\]+(\s+as\s+\w+)?\s*;[ \t]*$/m', $code, $mm, PREG_OFFSET_CAPTURE)) {
			$last = end($mm[0]); $pos = $last[1] + strlen($last[0]);
			$code = substr($code, 0, $pos) . "\nuse $fq;" . substr($code, $pos);
		} else {
			$code = preg_replace('/^(namespace\s+[^;]+;[ \t]*\n)/m', "$1\nuse $fq;\n", $code, 1);
		}
	}
	if ($apply) file_put_contents($file, $code);
}
fwrite(STDERR, "changed sites: $total\n");
