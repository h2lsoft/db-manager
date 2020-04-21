<?php
/**
 * Pagination example
 */

include "../src/DBManager.php";
include "include/connection_init.php";

$current_page = (!isset($_GET['page'])) ? 1 : $_GET['page'];


echo "<h2>Pagination example</h2>";
echo "add ?page=2 to simulate page 2<br>";


$sql = $DBM->select("*")
		   ->from('Country')
		   ->where("Continent = :Continent")
		   ->getSQL();

$params = [':Continent' => 'Asia'];

$pager = $DBM->paginate($sql, $params, $current_page, 20);


echo "<pre>";
print_r($pager);
echo "</pre>";
