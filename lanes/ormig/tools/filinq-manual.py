import subprocess
print(subprocess.run(['python3', '/tmp/lanes/ormig/tools/singlefile.py', 'filinq', 'tests/bootstrap-unit.php', 'tests/stubs/openregister-flow.stub.php'], capture_output=True, text=True).stdout)
import re
p = 'tests/stubs/OpenRegisterStubs.php'
s = open(p).read()
s = re.sub(r'^use OCA\\OpenRegister\\Kernel\\Contracts\\Value\\\w+;\n', '', s, flags=re.M)
s = re.sub(r'(?<![\\\w])(ObjectFindOptions|ObjectSaveOptions|ObjectDeletion|ObjectPatch|LockRequest|UnlockRequest|BulkSaveOptions|ObjectFindTarget)\b(?=\s+\$|\(|\s*=)', r'\\OCA\\OpenRegister\\Kernel\\Contracts\\Value\\\1', s)
s = re.sub(r'(?<=\?)(ObjectFindOptions)\b', r'\\OCA\\OpenRegister\\Kernel\\Contracts\\Value\\\1', s)
open(p, 'w').write(s)
print('FQ stub')
p = 'tests/unit/Support/RecordsCalls.php'; s = open(p).read()
old = """						$named[($parameter?->getName() ?? (string) $index)] = $this->traced(value: $argument);"""
if old in s and 'LegacyObjectArgs' not in s:
    s = s.replace(old, """						// OpenRegister's option objects are traced as the named values they carry.
						$flat = \\OCA\\Filinq\\Tests\\Support\\LegacyObjectArgs::flatten(args: [$argument]);
						if (array_key_exists(0, $flat) === false) {
							foreach ($flat as $name => $value) {
								$named[$name] = $this->traced(value: $value);
							}

							continue;
						}

""" + old)
    open(p, 'w').write(s)
    print('RECORDS')
# hydra-gates still ships the release's ObjectServiceInterface; the stub carries main's.
p = 'tests/bootstrap-unit.php'; s = open(p).read()
s = s.replace("foreach (['ObjectEntityInterface', 'ObjectServiceInterface'] as $contract) {", "foreach (['ObjectEntityInterface'] as $contract) {")
open(p, 'w').write(s)
# filinq's ObjectService stub declared its own release order; the helpers name and place arguments in that order.
p = 'tests/Support/LegacyObjectArgs.php'; s = open(p).read()
s = s.replace("return [$first, $o->extend, $o->files, $o->register, $o->schema, ($args[2] ?? true), ($args[3] ?? true), $o->render, $o->audit];",
              "return [$first, $o->register, $o->schema, ($args[2] ?? true), ($args[3] ?? true), $o->render, $o->audit];")
s = re.sub(r"return \[\n\t+\$first, \$o->extend, \$o->target->register, \$o->target->schema, \$o->uuid, \(\$args\[2\] \?\? true\), \(\$args\[3\] \?\? true\),\n\t+\$o->policy->silent, \$o->policy->validation, \$o->uploadedFiles, \$o->actor->currentUser, \$o->policy->failIfExists,\n\t+\$o->actor->unowned, \$o->policy->dedupOverride, \$o->actor->owner,\n\t+\];",
           "return [$first, $o->target->register, $o->target->schema, $o->uuid, ($args[2] ?? true), ($args[3] ?? true), $o->actor->unowned, $o->actor->owner];", s)
s = s.replace("return [$first->uuid, $first->target->register, $first->target->schema, ($args[1] ?? true), ($args[2] ?? true), ($args[3] ?? false), $first->currentUser, $first->permanent];",
              "return [$first->uuid, $first->target->register, $first->target->schema, ($args[1] ?? true), ($args[2] ?? true)];")
s = re.sub(r"'find' => \['id', '_extend', 'files', 'register', 'schema', '_rbac', '_multitenancy', '_render', '_audit'\],", "'find' => ['id', 'register', 'schema', '_rbac', '_multitenancy', '_render', '_audit'],", s)
s = re.sub(r"'saveObject' => \['object', 'extend', 'register', 'schema', 'uuid', [^\]]*\],", "'saveObject' => ['object', 'register', 'schema', 'uuid', '_rbac', '_multitenancy', '_unowned', '_owner'],", s)
s = re.sub(r"'deleteObject' => \['uuid', 'register', 'schema', [^\]]*\],", "'deleteObject' => ['uuid', 'register', 'schema', '_rbac', '_multitenancy'],", s)
s = re.sub(r"'find' => \[1 => \[\], 2 => false, 3 => null, 4 => null, 5 => true, 6 => true, 7 => true, 8 => true\],", "'find' => [1 => null, 2 => null, 3 => true, 4 => true, 5 => true, 6 => true],", s)
s = re.sub(r"'saveObject' => \[1 => \[\], [^\]]*\],", "'saveObject' => [1 => null, 2 => null, 3 => null, 4 => true, 5 => true, 6 => false, 7 => null],", s)
s = re.sub(r"'deleteObject' => \[1 => null, [^\]]*\],", "'deleteObject' => [1 => null, 2 => null, 3 => true, 4 => true],", s)
open(p, 'w').write(s)
p = 'tests/Support/ReleaseObjectSignatures.php'; s = open(p).read()
s = re.sub(r"public function find\(int\|string \$id, [^)]*\): mixed;", "public function find(int|string $id, mixed $register = null, mixed $schema = null, bool $_rbac = true, bool $_multitenancy = true, bool $_render = true, bool $_audit = true): mixed;", s)
s = re.sub(r"public function saveObject\(mixed \$object, [^)]*\): mixed;", "public function saveObject(mixed $object, mixed $register = null, mixed $schema = null, ?string $uuid = null, bool $_rbac = true, bool $_multitenancy = true, bool $_unowned = false, ?string $_owner = null): mixed;", s)
s = re.sub(r"public function deleteObject\(string \$uuid, [^)]*\): mixed;", "public function deleteObject(string $uuid, mixed $register = null, mixed $schema = null, bool $_rbac = true, bool $_multitenancy = true): mixed;", s)
open(p, 'w').write(s)
print('FILINQ ORDER')
p = 'tests/unit/Service/CorrespondenceLogGoldenTest.php'; s = open(p).read()
s = s.replace("\t\t\t$args = LegacyObjectArgs::flatten(args: $args);", "\t\t\t$args = LegacyObjectArgs::positional(method: 'saveObject', args: $args);")
open(p, 'w').write(s)
p = 'tests/unit/Service/LegalBasesSummaryActingUserTraceTest.php'; s = open(p).read()
old = "\t\t\tpublic function __call(string $name, array $arguments): mixed {\n"
if old in s and 'flatten(args: $arguments)' not in s:
    s = s.replace(old, old + "\t\t\t\t$arguments = \\OCA\\Filinq\\Tests\\Support\\LegacyObjectArgs::flatten(args: $arguments);\n", 1)
open(p, 'w').write(s)
# On main the unowned flag travels in SaveActor(unowned: true).
p = 'tests/unit/Settings/ServiceWrittenCreateTest.php'; s = open(p).read()
s = s.replace("$this->assertMatchesRegularExpression(pattern: '/_unowned:\\s*true/', string: $source, message: $file.' must save with _unowned: true');",
              "$this->assertMatchesRegularExpression(pattern: '/SaveActor\\([^)]*unowned:\\s*true/', string: $source, message: $file.' must save with SaveActor(unowned: true)');")
open(p, 'w').write(s)
p = 'tests/unit/Support/RecordsCalls.php'; s = open(p).read()
old = "\t\t\t\t\t$this->trace[] = [$label.'::'.$method => $named];"
if old in s and 'ordered(' not in s:
    s = s.replace(old, "\t\t\t\t\t$named = \\OCA\\Filinq\\Tests\\Support\\LegacyObjectArgs::ordered(method: $method, named: $named);\n" + old)
    open(p, 'w').write(s)
p = 'tests/Support/LegacyObjectArgs.php'; s = open(p).read()
if 'function ordered(' not in s:
    i = s.index('\t/**\n\t * The named values an option object carries')
    s = s[:i] + """	/**
	 * Named arguments in the release's parameter order, others after.
	 *
	 * @param string               $method The object-service method.
	 * @param array<string, mixed> $named  The named arguments.
	 *
	 * @return array<string, mixed> The same arguments, reordered.
	 */
	public static function ordered(string $method, array $named): array {
		if (isset(self::RELEASE[$method]) === false) {
			return $named;
		}

		$order = array_flip(self::RELEASE[$method]);
		uksort($named, static fn (int|string $a, int|string $b): int => (($order[$a] ?? PHP_INT_MAX) <=> ($order[$b] ?? PHP_INT_MAX)));
		return $named;
	}//end ordered()

""" + s[i:]
    open(p, 'w').write(s)
