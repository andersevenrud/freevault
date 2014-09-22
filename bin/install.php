<?php
/*!
 * FreeVault (c) Copyleft Software AS
 */

if ( !file_exists("freevault.php") ) {
  die("You need to run this from root directory: `php bin/install.php`");
}

require "freevault.php";

if ( !$client = Elastic::instance() ) {
  die("Failed to load client...");
}

$indexParams['index'] = 'entries';
$indexParams['body']['settings']['number_of_shards'] = 2;
$indexParams['body']['settings']['number_of_replicas'] = 0;

var_dump($client->indices()->create($indexParams));

?>
