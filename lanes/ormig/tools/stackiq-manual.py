import os, re
os.chdir('/tmp/lanes/ormig/stackiq')
p = 'tests/bootstrap-unit.php'; s = open(p).read()
s = s.replace("foreach (['ObjectEntityInterface', 'ObjectServiceInterface'] as $contract) {", "foreach (['ObjectEntityInterface'] as $contract) {")
if 'Kernel\\\\Contracts\\\\Value' not in s:
    s = s.replace("\t\t'OCA\\\\OpenRegister\\\\Mcp\\\\' => __DIR__ . '/Stubs/OpenRegister/Mcp/',\n", "\t\t'OCA\\\\OpenRegister\\\\Mcp\\\\' => __DIR__ . '/Stubs/OpenRegister/Mcp/',\n\t\t'OCA\\\\OpenRegister\\\\Kernel\\\\Contracts\\\\Value\\\\' => __DIR__ . '/Stubs/Kernel/Contracts/Value/',\n")
if 'Support/LegacyObjectArgs.php' not in s:
    s = s.rstrip('\n') + """

// OpenRegister main's ObjectServiceInterface (hydra-gates still ships the release's) and the test helpers for its option objects.
if (interface_exists('\\\\OCA\\\\OpenRegister\\\\Contract\\\\ObjectServiceInterface') === false) {
	require_once __DIR__ . '/Stubs/Contract/ObjectServiceInterface.php';
}

require_once __DIR__ . '/Support/ReleaseObjectSignatures.php';
require_once __DIR__ . '/Support/LegacyObjectArgs.php';
"""
open(p, 'w').write(s)
print('STACKIQ', s.count("Kernel\\\\Contracts\\\\Value"))
p = 'tests/Unit/Service/ContactpersoonProcessTest.php'; s = open(p).read()
s = s.replace("array_combine(self::SAVE_PARAMETERS, array_pad($args, count(self::SAVE_PARAMETERS), null))", "array_combine(self::SAVE_PARAMETERS, array_pad(array_slice($args, 0, count(self::SAVE_PARAMETERS)), count(self::SAVE_PARAMETERS), null))")
open(p, 'w').write(s)
# The recording was made when this mock handed its callback the release's 2nd and 3rd positions (extend, files).
p = 'tests/Unit/Service/ModuleComplianceBulkSyncGoldenTest.php'; s = open(p).read()
s = s.replace("$this->trace[] = ['objects.find', $id, $register, $schema];", "$this->trace[] = ['objects.find', $id, ($options?->extend ?? []), ($options?->files ?? false)];")
open(p, 'w').write(s)
