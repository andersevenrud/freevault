# Installation

```
# Clone repository
git clone --recursive https://github.com/andersevenrud/freevault
cd freevault

# Install dependencies
curl -s http://getcomposer.org/installer | php
php composer.phar install --no-dev

# Set up local stuff
php bin/install.php

# Set up database
mysql -u root -p < "CREATE DATABASE freevault;"
mysql -u root -p < "GRANT USAGE ON freevault.* TO 'freevault'@'localhost' IDENTIFIED BY 'freevault';"
mysql -u freevault -p freevault < schema.sql

```

# Configuration

You can set up your settings in `config.php` (see `freevault.php` for all available settings)

# Set up web-server

Set the directory to `public_html/` and make sure `.htaccess` is allowed.
