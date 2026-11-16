import os
os.chdir('/tmp/lanes/ormig/opencatalogi')
p = 'tests/bootstrap-unit.php'
s = open(p).read()
if 'Support/LegacyObjectArgs.php' not in s:
    s = s.rstrip('\n') + "\n\n// Test helpers for OpenRegister's option objects (tests carry no PSR-4 test namespace).\nrequire_once __DIR__ . '/Support/ReleaseObjectSignatures.php';\nrequire_once __DIR__ . '/Support/LegacyObjectArgs.php';\n"
    open(p, 'w').write(s)
import re
def _sub(p, pat, rep):
    s = open(p).read(); s2, n = re.subn(pat, rep, s, count=1)
    if n == 0: print('NOSUB', p)
    open(p, 'w').write(s2)
_sub('tests/Unit/Command/AttachDocumentsApplyTest.php', r"public function setPublicationWindowForFile\(int \$fileId, \?\\DateTime \$published, \?\\DateTime \$depublished\): void \{\n(\t+)", lambda m: "public function setPublicationWindowForFile(int $fileId, \\OCA\\OpenRegister\\Files\\Db\\PublicationWindow $window): void {\n" + m.group(1) + "$published = $window->published;\n" + m.group(1) + "$depublished = $window->depublished;\n" + m.group(1))
_sub('tests/Unit/Service/Table/PublishedTableServiceTest.php', r"public function saveObject\(array \$object, mixed \.\.\.\$options\): array \{\n(\t+)", lambda m: "public function saveObject(array $object, mixed ...$options): array {\n" + m.group(1) + "$options = \\OCA\\OpenCatalogi\\Tests\\Support\\LegacyObjectArgs::flatten(args: $options);\n" + m.group(1))
_sub('tests/Unit/Command/AttachDocumentsApplyTest.php', r"\$this->calls\[\] = 'delete ' \. \$uuid \. ' ' \. json_encode\(\$rest\);", "$this->calls[] = 'delete ' . $uuid . ' ' . json_encode(array_filter(['_rbac' => $_rbac, '_multitenancy' => $_multitenancy], static fn (bool $flag): bool => $flag === false) + $rest);")
_p = 'tests/Unit/Service/Table/PublishedTableServiceTest.php'
_s = open(_p).read(); _s = re.sub(r"(\['id' => '(?:t1|p1)', )'_extend' => \[\], ", r"\1", _s); open(_p, 'w').write(_s)
