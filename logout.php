<?php
require_once 'config.php';
require_once 'includes/Database.php';
require_once 'includes/Auth.php';

$auth = new Auth();
$auth->logout();

header('Location: index.php');
exit;