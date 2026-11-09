<?php
// Static scan of PHPUnit test methods for tests that are provably weak. Usage: php weak.php <repo> <outfile> <dir>...
require $argv[1] . '/vendor/autoload.php';

use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;

$repo = rtrim($argv[1], '/');
$out = $argv[2];
$dirs = array_slice($argv, 3);
$parser = (new ParserFactory())->createForHostVersion();
$finder = new NodeFinder();
$pp = new Standard();

$classes = [];
foreach ($dirs as $d) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$repo/$d", FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if ($f->getExtension() !== 'php') continue;
        $path = $f->getPathname();
        try { $ast = $parser->parse(file_get_contents($path)); } catch (\Throwable $e) { fwrite(STDERR, "parse fail $path\n"); continue; }
        $t = new NodeTraverser(); $t->addVisitor(new NameResolver()); $ast = $t->traverse($ast);
        foreach ($finder->findInstanceOf($ast, Node\Stmt\ClassLike::class) as $c) {
            if ($c->name === null) continue;
            $key = (string)$c->namespacedName;
            $methods = [];
            foreach ($c->getMethods() as $m) $methods[strtolower((string)$m->name)] = $m;
            $traits = [];
            foreach ($c->getTraitUses() as $u) foreach ($u->traits as $n) $traits[] = (string)$n;
            $classes[$key] = ['node'=>$c,'methods'=>$methods,'traits'=>$traits,'parent'=>($c instanceof Node\Stmt\Class_ && $c->extends)?(string)$c->extends:null,'file'=>substr($path,strlen($repo)+1)];
        }
    }
}

function lineage($k,$classes,$seen=[]){ if(!isset($classes[$k])||in_array($k,$seen,true)) return []; $seen[]=$k; $r=[$k];
  foreach($classes[$k]['traits'] as $t) $r=array_merge($r,lineage($t,$classes,$seen));
  if($classes[$k]['parent']) $r=array_merge($r,lineage($classes[$k]['parent'],$classes,$seen)); return $r; }
function findMethod($k,$name,$classes){ foreach(lineage($k,$classes) as $c) if(isset($classes[$c]['methods'][$name])) return $classes[$c]['methods'][$name]; return null; }
function isThis($v){ return $v instanceof Node\Expr\Variable && $v->name==='this'; }
function isSelfish($c){ return $c instanceof Node\Name && in_array(strtolower((string)$c),['self','static','parent'],true); }
function callName($n){ if($n instanceof Node\Expr\MethodCall||$n instanceof Node\Expr\StaticCall||$n instanceof Node\Expr\NullsafeMethodCall) return $n->name instanceof Node\Identifier?(string)$n->name:null;
  if($n instanceof Node\Expr\FuncCall) return $n->name instanceof Node\Name?$n->name->getLast():null; return null; }
function isAssertName($s){ return $s!==null && preg_match('/^(assert|fail$|expectException|expectOutput|expectError|expectWarning|expectDeprecation|expectNotice|expectUserDeprecation)/i',$s); }
function isLiteral($e){
  if($e instanceof Node\Scalar\Int_||$e instanceof Node\Scalar\Float_||$e instanceof Node\Scalar\String_) return true;
  if($e instanceof Node\Expr\ConstFetch) return true;
  if($e instanceof Node\Expr\UnaryMinus) return isLiteral($e->expr);
  if($e instanceof Node\Expr\Array_){ foreach($e->items as $i){ if($i===null) continue; if(!isLiteral($i->value)) return false; if($i->key&&!isLiteral($i->key)) return false;} return true; }
  return false; }
function hasCall($e,$finder){ return (bool)$finder->findFirst($e,fn($n)=>$n instanceof Node\Expr\MethodCall||$n instanceof Node\Expr\StaticCall||$n instanceof Node\Expr\FuncCall||$n instanceof Node\Expr\New_); }

// Returns collected call facts for a method, following $this-> helpers declared in the scanned tree.
function facts($classKey,$method,$classes,$finder,&$memo,$depth=0){
  $id=$classKey.'::'.strtolower((string)$method->name);
  if(isset($memo[$id])) return $memo[$id];
  $memo[$id]=['asserts'=>[],'expects'=>0,'unresolved'=>[],'skip'=>0,'addcount'=>0,'declared'=>0];
  $res=$memo[$id];
  foreach($finder->find($method->stmts??[],fn($n)=>$n instanceof Node\Expr\CallLike) as $n){
    $name=callName($n);
    if($name===null) continue;
    $lname=strtolower($name);
    if($n instanceof Node\Expr\MethodCall && ($lname==='expects' || $lname==='shouldreceive' || $lname==='shouldhavereceived')) { $res['expects']++; continue; }
    if($lname==='addtoassertioncount'){ $res['addcount']++; continue; }
    if($lname==='expectnottoperformassertions'){ $res['declared']++; continue; }
    if($lname==='marktestskipped'||$lname==='marktestincomplete'){ $res['skip']++; continue; }
    if(isAssertName($name)){ $res['asserts'][]=$n; continue; }
    $own = ($n instanceof Node\Expr\MethodCall && isThis($n->var)) || ($n instanceof Node\Expr\StaticCall && isSelfish($n->class));
    if($own){
      $m=findMethod($classKey,$lname,$classes);
      if($m && $depth<4){ $f=facts($classKey,$m,$classes,$finder,$memo,$depth+1);
        foreach($f['asserts'] as $a) $res['asserts'][]=$a; $res['expects']+=$f['expects'];
        foreach($f['unresolved'] as $u) $res['unresolved'][]=$u; }
      continue;
    }
    // A call into project test-support code that may assert on the caller's behalf.
    if($n instanceof Node\Expr\StaticCall && $n->class instanceof Node\Name && stripos((string)$n->class,'Tests\\')!==false) $res['unresolved'][]=(string)$n->class.'::'.$name;
    if($n instanceof Node\Expr\FuncCall && preg_match('/^(verify|check|expect)/i',$name)) $res['unresolved'][]=$name;
  }
  return $memo[$id]=$res;
}

// Map of "mock" expressions in setUp and method body: local $var and $this->prop assigned from createMock-family calls.
function mockExpr($e,$finder){
  if(!$e instanceof Node\Expr) return false;
  $n=callName($e);
  if($n===null) return false;
  if(in_array($n,['createMock','createStub','createConfiguredMock','createConfiguredStub','getMockForAbstractClass'],true)) return true;
  if($n==='getMock'){ $v=$e; while($v instanceof Node\Expr\MethodCall){ if(callName($v)==='getMockBuilder') return !collectOnly($e,$finder); $v=$v->var; } }
  return false; }
function collectOnly($e,$finder){ return (bool)$finder->findFirst($e,fn($n)=>$n instanceof Node\Expr\MethodCall && in_array(callName($n),['onlyMethods','addMethods','setMethods'],true)); }
function mockVars($stmts,$finder,$pp){ $vars=[];
  foreach($finder->findInstanceOf($stmts,Node\Expr\Assign::class) as $a){
    $key=$pp->prettyPrintExpr($a->var);
    if(mockExpr($a->expr,$finder)) { $vars[$key]=true; } elseif(isset($vars[$key])) { unset($vars[$key]); }
  } return $vars; }
function stubbed($stmts,$finder,$pp){ $s=[];
  foreach($finder->findInstanceOf($stmts,Node\Expr\MethodCall::class) as $c){ if(callName($c)!=='method'||!$c->args||!($c->args[0]->value instanceof Node\Scalar\String_)) continue;
    $s[strtolower($c->args[0]->value->value)]=true; } return $s; }
function ownProducts($stmts,$finder,$pp){ // variable => right-hand side for variables assigned exactly once
  $assign=[]; $bad=[];
  foreach($finder->findInstanceOf($stmts,Node\Expr\Assign::class) as $a){ if(!$a->var instanceof Node\Expr\Variable||!is_string($a->var->name)) continue; $k=$a->var->name; if(isset($assign[$k])) $bad[$k]=1; $assign[$k]=$a->expr; }
  foreach($bad as $k=>$_) unset($assign[$k]); return $assign; }
function varUses($stmts,$finder,$name){ return count($finder->find($stmts,fn($n)=>$n instanceof Node\Expr\Variable&&$n->name===$name)); }

$tests=[]; $memo=[]; $totals=['tests'=>0];
foreach($classes as $key=>$c){
  $node=$c['node'];
  if(!$node instanceof Node\Stmt\Class_||$node->isAbstract()) continue;
  $setup=findMethod($key,'setup',$classes);
  $teardown=findMethod($key,'teardown',$classes);
  $setUpFacts=$setup?facts($key,$setup,$classes,$finder,$memo):null;
  $tearFacts=$teardown?facts($key,$teardown,$classes,$finder,$memo):null;
  $setupStmts=$setup?($setup->stmts??[]):[];
  $mocksSetup=mockVars($setupStmts,$finder,$pp);
  foreach($c['methods'] as $lname=>$m){
    if(!$m->isPublic()) continue;
    $doc=$m->getDocComment()?$m->getDocComment()->getText():'';
    $attrs=[]; foreach($m->attrGroups as $g) foreach($g->attrs as $a) $attrs[]=$a->name->getLast();
    $isTest=str_starts_with($lname,'test')||in_array('Test',$attrs,true)||preg_match('/@test\b/',$doc);
    if(!$isTest) continue;
    $totals['tests']++;
    $stmts=$m->stmts??[];
    $f=facts($key,$m,$classes,$finder,$memo);
    $rule=null; $note='';
    $noexp=$f['expects']===0;
    $skipTop=false; foreach($stmts as $s){ if($s instanceof Node\Stmt\Expression && in_array(strtolower((string)callName($s->expr)),['marktestskipped','marktestincomplete'],true)) $skipTop=true; }
    $declared=$f['declared']>0||in_array('DoesNotPerformAssertions',$attrs,true)||preg_match('/@doesNotPerformAssertions/i',$doc);
    if($skipTop){ $rule='unconditional-skip'; }
    else if(count($f['asserts'])===0 && $noexp){
      $inherit = ($setUpFacts && ($setUpFacts['expects']>0||count($setUpFacts['asserts'])>0)) || ($tearFacts && ($tearFacts['expects']>0||count($tearFacts['asserts'])>0));
      if($inherit){ $rule='maybe-setup-expectations'; }
      elseif($f['unresolved']){ $rule='maybe-helper-asserts'; $note=implode(',',array_slice(array_unique($f['unresolved']),0,3)); }
      elseif(count($stmts)===0){ $rule='empty-body'; }
      elseif($f['addcount']>0){ $rule='addToAssertionCount-only'; }
      elseif($declared){ $rule='declared-no-assertions'; }
      else { $rule='no-assertion'; }
    } else if($noexp && count($f['asserts'])>0){
      $mocks=$mocksSetup+mockVars($stmts,$finder,$pp);
      $stubs=stubbed($stmts,$finder,$pp)+stubbed($setupStmts,$finder,$pp);
      $vars=ownProducts($stmts,$finder,$pp);
      $reasons=[];
      foreach($f['asserts'] as $a){
        $an=strtolower((string)callName($a));
        $args=array_map(fn($x)=>$x->value,array_values(array_filter($a->args,fn($x)=>$x instanceof Node\Arg)));
        $r=null;
        if(in_array($an,['fail'],true)) { $r=null; }
        elseif(!$args){ $r=null; }
        else {
          $actual=$args[count($args)>=2&&!preg_match('/^(asserttrue|assertfalse|assertnull|assertnotnull|assertempty|assertnotempty|assertis)/',$an)?1:0];
          $expected=$args[0];
          // Follow a local assigned once.
          $resolved=$actual;
          if($actual instanceof Node\Expr\Variable&&is_string($actual->name)&&isset($vars[$actual->name])) $resolved=$vars[$actual->name];
          // literal against literal, or the same pure expression on both sides.
          if(in_array($an,['asserttrue','assertfalse','assertnull','assertnotnull','assertempty','assertnotempty'],true)||str_starts_with($an,'assertis')){
            if(isLiteral($actual)) $r='tautology';
            elseif($actual instanceof Node\Expr\Variable&&isset($vars[$actual->name])&&isLiteral($vars[$actual->name])&&varUses($stmts,$finder,$actual->name)<=2) $r='asserts-own-fixture';
            elseif(in_array($an,['asserttrue','assertfalse'],true)&&callName($actual)&&in_array(strtolower(callName($actual)),['method_exists','class_exists','interface_exists','function_exists','property_exists','enum_exists','trait_exists','is_callable'],true)) $r='existence-only';
          } elseif(in_array($an,['assertsame','assertequals','assertnotsame','assertnotequals'],true)&&count($args)>=2){
            if(isLiteral($expected)&&isLiteral($actual)) $r='tautology';
            elseif(($expected instanceof Node\Expr\ClassConstFetch||$actual instanceof Node\Expr\ClassConstFetch)&&(isLiteral($expected)||isLiteral($actual))) $r='constant-pin';
            elseif(!hasCall($expected,$finder)&&!hasCall($actual,$finder)&&$pp->prettyPrintExpr($expected)===$pp->prettyPrintExpr($actual)) $r='tautology';
            elseif(isLiteral($expected)&&$actual instanceof Node\Expr\Variable&&isset($vars[$actual->name])&&isLiteral($vars[$actual->name])&&varUses($stmts,$finder,$actual->name)<=2) $r='asserts-own-fixture';
          }
          if($r===null&&$an==='assertinstanceof'&&count($args)>=2){
            if($actual instanceof Node\Expr\New_||$resolved instanceof Node\Expr\New_){
              $newed=$resolved instanceof Node\Expr\New_?$resolved:$actual;
              $cn=$newed->class instanceof Node\Name?(string)$newed->class:null;
              $ex=$expected instanceof Node\Expr\ClassConstFetch&&$expected->class instanceof Node\Name?(string)$expected->class:null;
              if($cn!==null&&$ex!==null&&strcasecmp($cn,$ex)===0) $r='instanceof-own-new';
            } else { $k=$pp->prettyPrintExpr($actual); $kr=$pp->prettyPrintExpr($resolved); if(isset($mocks[$k])||isset($mocks[$kr])) $r='mock-own-return'; }
          }
          if($r===null){ // assertion on the return value of a stubbed call on a mock
            foreach([$actual,$resolved] as $x){
              if($x instanceof Node\Expr\MethodCall&&$x->name instanceof Node\Identifier){ $k=$pp->prettyPrintExpr($x->var); if(isset($mocks[$k])&&isset($stubs[strtolower((string)$x->name)])){ $r='mock-own-return'; break; } }
            }
          }
        }
        $reasons[]=$r;
      }
      $n=count($reasons); $w=count(array_filter($reasons));
      if($w===$n && $n>0){ $u=array_values(array_unique($reasons)); $rule=count($u)===1?$u[0]:'mixed-tautology:'.implode('+',$u); }
      elseif($w>0){ $rule='partial-noise'; $note="$w of $n assertions weak: ".implode(',',array_unique(array_filter($reasons))); }
    }
    if($rule===null) continue;
    $tests[]=['file'=>$c['file'],'class'=>$key,'method'=>(string)$m->name,'line'=>$m->getStartLine(),'rule'=>$rule,'note'=>$note,'stmts'=>count($stmts),'asserts'=>count($f['asserts'])];
  }
}
file_put_contents($out,json_encode(['totals'=>$totals,'tests'=>$tests],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
$c=[]; foreach($tests as $t){ $c[$t['rule']]=($c[$t['rule']]??0)+1; } arsort($c); echo "tests={$totals['tests']}\n"; print_r($c);
