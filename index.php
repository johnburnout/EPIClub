<?php

require __DIR__ . '/app/bootstrap.php';

if ($isLoggedIn) {
	header('Location: /tableau_de_bord.php');
	exit();
}

require __DIR__ . '/login.php';