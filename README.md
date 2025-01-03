# Open Registers

Open Registers provides the ability to work with objects based on [`schema.json`](https://json-schema.org/).

## What is Open Registers?

Open Registers is a system for managing registers in Nextcloud. A register is a collection of one or more object types that are defined by a [`schema.json`](https://json-schema.org/). Registers sort objects and validate them against their object types.

Registers can store objects either directly in the Nextcloud database, or in an external database or object store.

Registers provide APIs for consumption.

Registers can also apply additional logic to objects, such as validation that is not applicable through the [`schema.json`](https://json-schema.org/) format.

## Features

- 📦 **Object Management**: Work with objects based on [`schema.json`](https://json-schema.org/).
- 🗂️ **Register System**: Manage collections of object types.
- 🛡️ **Validation**: Validate objects against their types.
- 💾 **Flexible Storage**: Store objects in Nextcloud, external databases, or object stores.
- 🔄 **APIs**: Provide APIs for consumption.
- 🧩 **Additional Logic**: Apply extra validation and logic beyond [`schema.json`](https://json-schema.org/).

## Documentation

For more detailed information, please refer to the documentation files in the `docs` folder:

- [Developer Guide](docs/developers.md)
- [Styleguide](docs/styleguide.md)

## Project Structure

- **appinfo/routes.php**: Defines the routes for the application.
- **lib**: Contains all the PHP code for the application.
- **src**: Contains all the Vue.js code for the application.
- **docs**: Contains documentation files.
