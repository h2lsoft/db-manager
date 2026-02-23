<?php
/**
 * Transaction, rollback & savepoints examples
 */

include "../src/DBManager.php";
include "include/connection_init.php";


// ============================================================
// 1. manual begin / rollback
// ============================================================
echo "<h3>1. Manual transaction with rollback</h3>";

echo "inTransaction: " . var_export($DBM->inTransaction(), true) . "<br>";

$DBM->beginTransaction();
echo "inTransaction: " . var_export($DBM->inTransaction(), true) . " (level {$DBM->getTransactionLevel()})<br>";

$id1 = $DBM->table('Author')->insert(['Name' => 'Victor Hugo', 'Birthdate' => '1802-02-26']);
echo "Inserted author #{$id1}<br>";

$id2 = $DBM->table('Author')->insert(['Name' => 'Emile Zola', 'Birthdate' => '1840-04-02']);
echo "Inserted author #{$id2}<br>";

$DBM->rollBack();
echo "Rollback done<br>";

$check = $DBM->table('Author')->getByID($id1);
echo "Author #{$id1} after rollback: " . ($check ? $check['Name'] : 'NOT FOUND') . "<br>";
echo "<hr>";


// ============================================================
// 2. safeTransaction() — auto-commit on success
// ============================================================
echo "<h3>2. safeTransaction() — auto-commit on success</h3>";

$inserted_id = null;
$ok = $DBM->safeTransaction(function($db) use (&$inserted_id) {
	$inserted_id = $db->table('Author')->insert(['Name' => 'Albert Camus', 'Birthdate' => '1913-11-07']);
	echo "Inserted author #{$inserted_id} inside transaction<br>";
});

echo "Success: " . var_export($ok, true) . "<br>";
$check = $DBM->table('Author')->getByID($inserted_id);
echo "Author #{$inserted_id} after commit: {$check['Name']}<br>";
echo "<hr>";


// ============================================================
// 3. safeTransaction() — auto-rollback on error
// ============================================================
echo "<h3>3. safeTransaction() — auto-rollback on error</h3>";

$error = null;
$ok = $DBM->safeTransaction(function($db) {
	$db->table('Author')->insert(['Name' => 'Ghost Author', 'Birthdate' => '2000-01-01']);
	echo "Inserted Ghost Author (not yet committed)<br>";
	throw new \Exception("Something went wrong!");
}, $error);

echo "Success: " . var_export($ok, true) . "<br>";
if($error)
	echo "Error: {$error->getMessage()}<br>";
echo "<hr>";


// ============================================================
// 4. nested transactions (savepoints)
// ============================================================
echo "<h3>4. Nested transactions with savepoints</h3>";

$DBM->beginTransaction(); // level 1 — BEGIN
echo "Level: {$DBM->getTransactionLevel()}<br>";

$id1 = $DBM->table('Author')->insert(['Name' => 'Jules Verne', 'Birthdate' => '1828-02-08']);
echo "Inserted Jules Verne #{$id1}<br>";

	$DBM->beginTransaction(); // level 2 — SAVEPOINT sp_1
	echo "Level: {$DBM->getTransactionLevel()}<br>";

	$id2 = $DBM->table('Author')->insert(['Name' => 'Bad Author', 'Birthdate' => '0000-00-00']);
	echo "Inserted Bad Author #{$id2}<br>";

	$DBM->rollBack(); // ROLLBACK TO SAVEPOINT sp_1
	echo "Savepoint rolled back — Bad Author cancelled<br>";
	echo "Level: {$DBM->getTransactionLevel()}<br>";

$DBM->commit(); // COMMIT
echo "Committed — Jules Verne persisted<br>";

$check1 = $DBM->table('Author')->getByID($id1);
$check2 = $DBM->table('Author')->getByID($id2);
echo "Jules Verne: " . ($check1 ? $check1['Name'] : 'NOT FOUND') . "<br>";
echo "Bad Author: " . ($check2 ? $check2['Name'] : 'NOT FOUND') . "<br>";
echo "<hr>";


// ============================================================
// 5. nested safeTransaction()
// ============================================================
echo "<h3>5. Nested safeTransaction()</h3>";

$DBM->safeTransaction(function($db) {

	$id = $db->table('Author')->insert(['Name' => 'Balzac', 'Birthdate' => '1799-05-20']);
	echo "Inserted Balzac #{$id}<br>";

	// nested transaction that fails — only this part rolls back
	$inner_error = null;
	$ok = $db->safeTransaction(function($db) {
		$db->table('Author')->insert(['Name' => 'Nested Ghost', 'Birthdate' => '0000-00-00']);
		echo "Inserted Nested Ghost (will rollback)<br>";
		throw new \Exception("Inner error");
	}, $inner_error);

	if(!$ok)
		echo "Inner failed: {$inner_error->getMessage()} — Nested Ghost rolled back<br>";

	echo "Outer still alive at level {$db->getTransactionLevel()}<br>";
});

echo "All committed — Balzac persisted, Nested Ghost gone<br>";
echo "<hr>";


// ============================================================
// cleanup
// ============================================================
echo "<h3>Cleanup</h3>";
$DBM->setSoftMode(false);
if(!empty($inserted_id)) $DBM->table('Author')->delete(["ID = ?", $inserted_id]);
if(!empty($id1)) $DBM->table('Author')->delete(["ID = ?", $id1]);
echo "Done.<br>";
