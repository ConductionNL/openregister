# Open Register Documentation

This directory contains the documentation website for Open Register, built with [Docusaurus 2](https://docusaurus.io/).

## Installation

Install the dependencies:

    npm install

## Local Development

Start the development server:

    npm start

This command starts a local development server and opens up a browser window. Most changes are reflected live without having to restart the server.

## Build

Build the static files:

    npm run build

This command generates static content into the `build` directory and can be served using any static contents hosting service.

## Deployment

The documentation is automatically deployed to GitHub Pages when changes are pushed to the `documentation` branch.

## Project Structure

    website/
    ├── docs/              # Documentation markdown files
    ├── src/               # React components and pages
    ├── static/            # Static files (images, etc)
    ├── docusaurus.config.js   # Site configuration
    ├── package.json       # Project dependencies
    ├── README.md         # This file
    └── sidebars.js       # Sidebar configuration

## Contributing

1. Create a new branch from `documentation`
2. Make your changes
3. Test locally using `npm start`
4. Create a Pull Request to the `documentation` branch
