<?php

// optional parameters see documentation
$soft_mode = true;
$soft_modeDefaultUserUID = 'username'; // name to track in soft mode


$DBM = new h2lsoft\DBManager\DBManager($soft_mode, $soft_modeDefaultUserUID);
$DBM->connect('mysql', 'localhost', 'root', '', 'tests', '', [PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8']);