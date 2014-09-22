FreeVault is a free and open-source password manager (based on SimpleVault) using ElasticSearch.

Currently in **beta**-ish state

## Features

* Store passwords or other secrets safely
* Client-side encryption (zero-knowledge server)
* Multiple user support
* Free and open-source
* Comes with a CLI version as well

## Requriements

### Backend

Runs on PHP 5.2+ (with PDO, mcrypt, ssl and curl) with Apache

Requires ElasticSearch

### Frontend

Works in all web-browsers

### CLI

Requires python

## Installation

See `INSTALL.md` for installation help.

You can override default definitions by creating `config.php` in root directory.
