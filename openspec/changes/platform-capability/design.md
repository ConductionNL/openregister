# Design: platform-capability

## D-1. One source, two surfaces

The Nextcloud capability block and the API capabilities endpoint answer
the same question to two audiences. They read one source, so an instance
cannot tell a Nextcloud client one upload limit and an integrator another.

## D-2. A block per claiming app

A client of the case app should be able to ask whether the case app is
there. Nesting a block per claiming app means it never has to know that
OpenRegister is underneath, which is also what makes the leaf app
replaceable.

## D-3. The block names no register and no secret

Capabilities are read by anything that can reach the instance. Listing the
data model there is an enumeration gift. The block carries versions,
limits and switches, and the model stays behind authentication.

## D-4. kind

Code, in OpenRegister.
