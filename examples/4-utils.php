<?php
/**
 * Utils example
 */

include "../src/DBManager.php";
include "include/connection_init.php";

// alias
$DBM->table('Country')->addSoftModeColumns(); // create soft columns dynamically

// get a record by ID, you can use multiple ID by array
$record = $DBM->table('Country')->get(10);

echo "<pre>";
print_r($record);
echo "</pre>";



//  multiple ID
$records = $DBM->table('Country')->getByID([12, 10, 55]);

echo "<pre>";
print_r($records);
echo "</pre>";
