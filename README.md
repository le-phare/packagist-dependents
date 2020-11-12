# Packagist dependents

Find dependents  from a package in a specified version

## Usage

`php packagist_dependents.php vendor/name:version`

What it does:

1. Uses the private packagist client to find dependents from `vendor/name` package (in all versions).
2. Query the related private projects on gitlab.com and a self-hosted gitlab. If there is a `composer.json` file, check if the package version is the same as the input one.
3. Render result in table with the name of the project and its URL.

## Configuration

Create a .env file and set your tokens:

`cp .env.dist .env`

## Why

The packagist interface and api doesn't allow to query dependents of a package with a specific version.
