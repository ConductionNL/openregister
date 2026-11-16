import os, re
os.chdir('/tmp/lanes/ormig/learniq')
def sub(p, a, b):
    s = open(p).read()
    if a not in s: print('MISSING', p, a[:60]); return
    open(p, 'w').write(s.replace(a, b))
# Recorders that name a mock's arguments by reflection keep the release's names.
sub('tests/Support/PassedArguments.php', "foreach ((new ReflectionMethod($method[0], $method[1]))->getParameters() as $index => $param) {",
    "foreach (LegacyObjectArgs::parameters($method[0], $method[1]) as $index => $param) {")
s = open('tests/Support/PassedArguments.php').read()
if 'use OCA\\Learniq\\Tests\\Support\\LegacyObjectArgs;' not in s and 'namespace OCA\\Learniq\\Tests\\Support;' not in s:
    pass
open('tests/Support/PassedArguments.php', 'w').write(s)
# The recording lists the flags in the release's order.
sub('tests/Unit/Service/ExampleSetDatesTest.php', "$rest = LegacyObjectArgs::flatten(args: $rest);\n\t\t$this->finds[] = ['id' => $id] + $rest;",
    "$rest = LegacyObjectArgs::ordered(method: 'find', named: LegacyObjectArgs::flatten(args: $rest));\n\t\t$this->finds[] = ['id' => $id] + $rest;")
# The contract test pins OpenRegister main's signatures.
sub('tests/Unit/Contract/OpenRegisterContractTest.php', "['id', '_extend', 'files', 'register', 'schema', '_rbac', '_multitenancy', '_render', '_audit']", "['id', 'options', '_rbac', '_multitenancy']")
sub('tests/Unit/Contract/OpenRegisterContractTest.php', "['object', 'extend', 'register', 'schema'],", "['object', 'options', '_rbac', '_multitenancy'],")
# A test method used as find() callback.
p = 'tests/Unit/Listener/ReportCardComposerTest.php'; s = open(p).read()
old = "public function resolveTemplateAwareFixture(int|string $id, ?array $_extend, bool $files, $register, $schema): ?ObjectEntity {\n"
if old in s:
    s = s.replace(old, "public function resolveTemplateAwareFixture(int|string $id, ?\\OCA\\OpenRegister\\Kernel\\Contracts\\Value\\ObjectFindOptions $options = null): ?ObjectEntity {\n\t\t$_extend = $options?->extend;\n\t\t$files = $options?->files ?? false;\n\t\t$register = $options?->register;\n\t\t$schema = $options?->schema;\n")
    open(p, 'w').write(s)
print('L2')
# The golden recorded the release's trailing positional flags.
p = 'tests/Unit/Service/Course/CourseTemplateInstantiateGoldenTest.php'
if "positional('deleteObject'" not in open(p).read(): sub(p, "$this->calls[] = ['delete' => [$uuid, $register, $schema, $rest]];",
    "$rest = array_slice(LegacyObjectArgs::positional('deleteObject', func_get_args()), 3);\n\t\t\t\t$this->calls[] = ['delete' => [$uuid, $register, $schema, $rest]];")
s = open(p).read()
if 'Tests\\Support\\LegacyObjectArgs;' not in s:
    s = s.replace("use Throwable;\n", "use Throwable;\nuse OCA\\Learniq\\Tests\\Support\\LegacyObjectArgs;\n", 1); open(p, 'w').write(s)
print('L3')
# An arrow-function saveObject double that ignores everything but the object.
sub('tests/Unit/Service/Portal/PortalHourWeekApprovalTest.php',
    "fn (array $object, ?array $extend = [], $register = null, $schema = null, ?string $uuid = null): object => OrEntityFactory::make($object, 'bpv-hour-week')",
    "fn (array $object, mixed ...$rest): object => OrEntityFactory::make($object, 'bpv-hour-week')")
print('L4')
