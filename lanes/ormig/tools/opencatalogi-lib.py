import re, os
os.chdir('/tmp/lanes/ormig/opencatalogi')
p = 'lib/Command/AttachDocumentsToPublicationsCommand.php'
s = open(p).read()
old = "$fileMapper->setPublicationWindowForFile(fileId: $fileId, published: $window['published'], depublished: $window['depublished']);"
assert old in s
s = s.replace(old, "$fileMapper->setPublicationWindowForFile(\n\t\t\t\tfileId: $fileId,\n\t\t\t\twindow: new PublicationWindow(published: $window['published'], depublished: $window['depublished'])\n\t\t\t);")
line = 'use OCA\\OpenRegister\\Files\\Db\\PublicationWindow;'
ms = list(re.finditer(r'^use [^;]+;$', s, re.M))
for m in ms:
    if m.group(0).lower() > line.lower():
        s = s[:m.start()] + line + '\n' + s[m.start():]; break
else:
    s = s[:ms[-1].end()] + '\n' + line + s[ms[-1].end():]
open(p, 'w').write(s)
print('ok')
