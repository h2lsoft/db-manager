<?php
/**
 * Simple example to fetch data to server
 */

include "../src/DBManager.php";
include "include/connection_init.php";

// normal query => top 3 countries in ASIA by surface area
$sql = "SELECT Name, SurfaceArea FROM Country WHERE Continent = :Continent AND deleted = 'NO' ORDER BY SurfaceArea DESC LIMIT 3";
$results = $DBM->query($sql, [':Continent' =>  'Asia'])->fetchAll();

echo "<pre>";
print_r($results);
echo "</pre>";



// simple query rewrinting
$sql = $DBM->select("Name, SurfaceArea")
		   ->from('Country')
		   ->where("Continent = :Continent")
		   ->orderBy('SurfaceArea DESC')
		   ->limit(3)
		   ->getSQL();

$results = $DBM->query($sql, [':Continent' => "Asia"])->fetchAll();

echo "<pre>";
print_r($results);
echo "</pre>";

