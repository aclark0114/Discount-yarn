<?php
require __DIR__ . '/../includes/bootstrap.php';

unset($_SESSION['admin'], $_SESSION['admin_seen'], $_SESSION['admin_pw']);
session_regenerate_id(true);
redirect('/admin/login.php');
