import os, re, glob
os.chdir('/tmp/lanes/ormig/decidiq')
# A recorder that names a mock's arguments by reflection reads the release's positions.
pat = re.compile(r"(\$(\w+)) = LegacyObjectArgs::flatten\(args: \1\);(\s*)\1 = \$this->named\(([\w\\]+::class), '(\w+)', \1\);")
for p in glob.glob('tests/**/*.php', recursive=True):
    s = open(p).read(); s2 = pat.sub(lambda m: f"{m.group(1)} = LegacyObjectArgs::positional(method: '{m.group(5)}', args: {m.group(1)});{m.group(3)}{m.group(1)} = $this->named({m.group(4)}, '{m.group(5)}', {m.group(1)});", s)
    # call(string $method, array $args) helpers that name the arguments by reflection
    if 'private function call(string $method, array $args)' in s2 and '$named       = $this->named(method: $method, args: $args);' in s2 and 'positional(method: $method' not in s2:
        s2 = s2.replace('$named       = $this->named(method: $method, args: $args);', '$args        = LegacyObjectArgs::positional(method: $method, args: $args);\n\t\t$named       = $this->named(method: $method, args: $args);')
    s2 = s2.replace('$this->named(class: ObjectServiceInterface::class, method: $method, args: $args)', '$this->named(class: ObjectServiceInterface::class, method: $method, args: LegacyObjectArgs::positional(method: $method, args: $args))')
    if s2 != s:
        if 'Tests\\Support\\LegacyObjectArgs;' not in s2:
            s2 = re.sub(r'(\nuse [^\n]+;\n)', r'\1use OCA\\Decidiq\\Tests\\Support\\LegacyObjectArgs;\n', s2, count=1)
        open(p, 'w').write(s2); print('NAMED', p)
# WrittenRoundStore::find(id, schema) is the app's own method.
p = 'tests/Unit/Service/WrittenRoundStoreTest.php'; s = open(p).read()
s2 = re.sub(r"\$this->store->find\(id: ([^,]+), options: new ObjectFindOptions\(schema: ([^)]+)\)\)", r"$this->store->find(id: \1, schema: \2)", s)
if s2 != s: open(p, 'w').write(s2); print('STORE', p)
# The golden was recorded against a stub whose saveObject() ended at failIfExists.
p = 'tests/Unit/Service/MeetingTransitionGoldenTest.php'; s = open(p).read()
a = "$rest = array_slice(LegacyObjectArgs::positional(method: 'saveObject', args: [$object, ...$rest]), 1);"
if a in s: open(p, 'w').write(s.replace(a, a.replace("), 1);", "), 1, 11);"))); print('TRIM', p)
# An expectation separated from its method() by a comment.
p = 'tests/Unit/Controller/VotingBehaviourControllerTest.php'; s = open(p).read()
a = "->with($participantUuid, [], false, 'decidiq', 'participant')"
if a in s:
    s = s.replace(a, "->with(...LegacyObjectArgs::with(method: 'find', expected: [\n\t\t\t\t'id' => $participantUuid,\n\t\t\t\t'_extend' => [],\n\t\t\t\t'files' => false,\n\t\t\t\t'register' => 'decidiq',\n\t\t\t\t'schema' => 'participant',\n\t\t\t]))")
    if 'Tests\\Support\\LegacyObjectArgs;' not in s:
        s = s.replace("use OCA\\Decidiq\\Tests\\Unit\\Support\\FakeSlugResolver;\n", "use OCA\\Decidiq\\Tests\\Support\\LegacyObjectArgs;\nuse OCA\\Decidiq\\Tests\\Unit\\Support\\FakeSlugResolver;\n")
    open(p, 'w').write(s); print('WITH', p)
