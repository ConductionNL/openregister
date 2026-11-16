#!/bin/bash
# integriq requires its OpenRegister stubs one by one; the option objects main added load by PSR-4.
B=tests/bootstrap.php
grep -q "addPsr4('OCA\\\\\\\\OpenRegister\\\\\\\\', \$stubsDir" $B || sed -i "s#^\t\$stubsDir = __DIR__ . '/stubs';#&\n\t\$autoloader->addPsr4('OCA\\\\\\\\OpenRegister\\\\\\\\', \$stubsDir . '/OCA/OpenRegister/');#" $B
# The integration provider contract follows main: list() lives on RefIntegrationProvider with an ObjectRef.
OR=/tmp/lanes/ormig/openregister/lib; S=tests/stubs/OCA/OpenRegister
cp $OR/Service/Integration/IntegrationProvider.php $S/Service/Integration/IntegrationProvider.php
cp $OR/Service/Integration/AbstractIntegrationProvider.php $S/Service/Integration/AbstractIntegrationProvider.php
cp $OR/Service/Integration/RefIntegrationProvider.php $S/Service/Integration/RefIntegrationProvider.php
mkdir -p $S/Kernel/Contracts && cp $OR/Kernel/Contracts/ObjectRef.php $S/Kernel/Contracts/ObjectRef.php
