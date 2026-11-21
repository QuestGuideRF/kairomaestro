<?php
require_once __DIR__ . '/config/config.php';
$userObj = new User();
$userObj->logout();
redirect('/login.php');
