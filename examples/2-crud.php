<?php
/**
 * Simple CRUD example
 */

include "../src/DBManager.php";
include "include/connection_init.php";

// insert record soft mode (with stamp)
echo "<h3>Insert a row in soft mode</h3>";

$values = [];
$values['Name'] = "Agatha Christies";
$values['Birthdate'] = "1890-10-15";

$ID = $DBM->table('Author')->insert($values);

echo " => New author ID #{$ID}<br>";
echo $DBM->dBugLastQuery();
echo "<hr>";


// update record in soft mode (with stamp)
echo "<h3>Update a row in soft mode</h3>";
$values = [];
$values['Name'] = "Agatha Christies Updated";
$affected_rows = $DBM->table('Author')->update($values, $ID);
// $affected_rows = $DBM->table('Author')->update($values, ["ID = :wID AND Name = :wName", [':wID' => $ID, ':wName' => 'Agatha Christies']]);

echo " => Affected_rows:  $affected_rows<br>";
echo " => Author ID #{$ID} updated<br>";
echo $DBM->dBugLastQuery();
echo "<hr>";


// delete record in soft mode (with stamp)
echo "<h3>Delete a row in soft mode</h3>";

echo " => Author ID #{$ID} softly deleted<br>";
$affected_rows = $DBM->table('Author')->delete($ID);
echo " => Affected_rows:  $affected_rows<br>";
echo $DBM->dBugLastQuery();
echo "<hr>";

// real delete record
$DBM->setSoftMode(false); // desactivate soft mode (turn on by default in constructor)

echo "<h3>Delete a row in real mode</h3>";
echo " => Author ID #{$ID} really deleted<br>";
$affected_rows = $DBM->table('Author')->delete(["ID = ?", $ID]);
echo " => Affected_rows:  $affected_rows<br>";
echo $DBM->dBugLastQuery();

