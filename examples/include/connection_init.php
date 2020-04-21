<?php

$DBM = new h2lsoft\DBManager\DBManager();
$DBM->connect('mysql', 'localhost', 'root', '', 'tests', '', [PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8']);