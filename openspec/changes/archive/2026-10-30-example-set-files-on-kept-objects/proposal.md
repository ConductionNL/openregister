## Why

An example set attaches an object's files only when it writes the object. A file declared later, or one whose attach failed the first time, never arrives: the next install keeps the object and skips its files.

That is why the Lindelaan Woo case on :8080 shows no files. Its fragment declares them, but the case was written before they were declared.

## What Changes

- On install, a kept object whose record holds no files gets the files its declaration names attached and recorded, once.
- An object whose files the record already holds is left alone, as before.
