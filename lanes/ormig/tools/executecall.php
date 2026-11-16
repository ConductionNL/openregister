<?php
// $node->execute($items, $config, $context) => $node->execute(new FlowNodeCall(items: ..., config: ..., context: ...))
require __DIR__ . '/vendor/autoload.php';
use PhpParser\Lexer\Emulative; use PhpParser\Node; use PhpParser\NodeFinder; use PhpParser\ParserFactory;
$lexer = new Emulative(['usedAttributes' => ['startLine', 'endLine', 'startFilePos', 'endFilePos']]);
$parser = (new ParserFactory())->create(ParserFactory::PREFER_PHP7, $lexer);
foreach (array_slice($argv, 1) as $file) {
	$code = file_get_contents($file); $ast = $parser->parse($code); $edits = [];
	foreach ((new NodeFinder())->findInstanceOf($ast, Node\Expr\MethodCall::class) as $c) {
		if (!$c->name instanceof Node\Identifier || $c->name->toString() !== 'execute' || count($c->args) !== 3) continue;
		$names = ['items', 'config', 'context']; $parts = [];
		foreach ($c->args as $i => $a) {
			if ($a->unpack) continue 2;
			$n = $a->name ? $a->name->toString() : $names[$i];
			if (!in_array($n, $names, true)) continue 2;
			$parts[] = $n . ': ' . substr($code, $a->value->getAttribute('startFilePos'), $a->value->getAttribute('endFilePos') - $a->value->getAttribute('startFilePos') + 1);
		}
		$open = strpos($code, '(', $c->name->getAttribute('endFilePos')); $close = $c->getAttribute('endFilePos');
		$edits[] = [$open, $close, '(new FlowNodeCall(' . implode(', ', $parts) . '))'];
		echo "EXEC\t$file:" . $c->getLine() . "\n";
	}
	if ($edits === []) continue;
	usort($edits, fn($a, $b) => $b[0] <=> $a[0]);
	foreach ($edits as $e) $code = substr($code, 0, $e[0]) . $e[2] . substr($code, $e[1] + 1);
	if (!preg_match('/^use OCA\\\\OpenRegister\\\\Service\\\\Flow\\\\FlowNodeCall;/m', $code)) {
		preg_match_all('/^use\s+[A-Za-z0-9_\\\\]+(\s+as\s+\w+)?\s*;[ \t]*$/m', $code, $mm, PREG_OFFSET_CAPTURE);
		$last = end($mm[0]); $pos = $last[1] + strlen($last[0]);
		$code = substr($code, 0, $pos) . "\nuse OCA\\OpenRegister\\Service\\Flow\\FlowNodeCall;" . substr($code, $pos);
	}
	file_put_contents($file, $code);
}
