<?php
// Breaks single-line calls that carry openregister option objects and exceed the line limit
// into one argument per line, nesting the option objects the same way when they still do not fit.
// usage: php wrap.php [--limit=150] file...
require __DIR__ . '/vendor/autoload.php';

use PhpParser\Lexer\Emulative;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;

$limit = 150; $files = [];
foreach (array_slice($argv, 1) as $a) {
	if (str_starts_with($a, '--limit=')) { $limit = (int)substr($a, 8); continue; }
	$files[] = $a;
}
$lexer = new Emulative(['usedAttributes' => ['startLine', 'endLine', 'startFilePos', 'endFilePos']]);
$parser = (new ParserFactory())->create(ParserFactory::PREFER_PHP7, $lexer);
const OPT = '/^(ObjectFindOptions|ObjectSaveOptions|ObjectFindTarget|SavePolicy|SaveActor|ObjectDeletion|ObjectPatch|LockRequest|LockRunStep|UnlockRequest|BulkSaveOptions|BulkBatchDefaults|FlowNodeCall|FormCompletionRequest|FormDestination|PublishedForm|FormDependentsSignals)$/';

function src(string $code, Node $n): string { return substr($code, $n->getAttribute('startFilePos'), $n->getAttribute('endFilePos') - $n->getAttribute('startFilePos') + 1); }
function lineOf(string $code, int $pos): array {
	$s = strrpos(substr($code, 0, $pos), "\n"); $s = $s === false ? 0 : $s + 1;
	$e = strpos($code, "\n", $pos); $e = $e === false ? strlen($code) : $e;
	return [$s, $e];
}
function width(string $line): int { return strlen(str_replace("\t", '    ', $line)); }
function argText(string $code, Node\Arg $a, string $ind, int $limit, int $prefix): string {
	$name = $a->name ? $a->name->toString() . ': ' : '';
	$v = $a->value;
	$flat = $name . src($code, $v);
	if (width($ind) + strlen($flat) + $prefix <= $limit || !($v instanceof Node\Expr\New_) || $v->args === []) return $flat;
	return $name . newText($code, $v, $ind, $limit);
}
function newText(string $code, Node\Expr\New_ $n, string $ind, int $limit): string {
	$cls = src($code, $n->class);
	$in = $ind . "\t"; $parts = [];
	foreach ($n->args as $a) $parts[] = $in . argText($code, $a, $in, $limit, 1);
	return "new $cls(\n" . implode(",\n", $parts) . "\n$ind)";
}

foreach ($files as $file) {
	$code = file_get_contents($file);
	for ($round = 0; $round < 300; $round++) {
		$ast = $parser->parse($code);
		$best = null;
		foreach ((new NodeFinder())->find($ast, fn($n) => ($n instanceof Node\Expr\MethodCall || $n instanceof Node\Expr\StaticCall || $n instanceof Node\Expr\New_ || $n instanceof Node\Expr\NullsafeMethodCall) && $n->getStartLine() === $n->getEndLine() && $n->args !== []) as $c) {
			$hasOpt = false;
			foreach ((new NodeFinder())->findInstanceOf($c->args, Node\Expr\New_::class) as $nw) {
				if ($nw->class instanceof Node\Name && preg_match(OPT, $nw->class->getLast())) $hasOpt = true;
			}
			if (!$hasOpt) continue;
			[$ls, $le] = lineOf($code, $c->getAttribute('startFilePos'));
			if (width(substr($code, $ls, $le - $ls)) <= $limit) continue;
			// Outermost call on the line first.
			if ($best === null || $c->getAttribute('startFilePos') < $best->getAttribute('startFilePos') || ($c->getAttribute('startFilePos') === $best->getAttribute('startFilePos') && $c->getAttribute('endFilePos') > $best->getAttribute('endFilePos'))) $best = $c;
		}
		if ($best === null) break;
		$c = $best;
		[$ls, $le] = lineOf($code, $c->getAttribute('startFilePos'));
		preg_match('/^[ \t]*/', substr($code, $ls), $m); $ind = $m[0]; $in = $ind . "\t";
		$nameEnd = $c instanceof Node\Expr\New_ ? $c->class->getAttribute('endFilePos') : $c->name->getAttribute('endFilePos');
		$open = strpos($code, '(', $nameEnd + 1); $close = $c->getAttribute('endFilePos');
		$parts = [];
		foreach ($c->args as $a) $parts[] = $in . argText($code, $a, $in, $limit, 1);
		$code = substr($code, 0, $open) . "(\n" . implode(",\n", $parts) . "\n$ind)" . substr($code, $close + 1);
		echo "WRAP\t$file:" . $c->getStartLine() . "\n";
	}
	file_put_contents($file, $code);
}
