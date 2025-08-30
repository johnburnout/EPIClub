<?php

require __DIR__ . '/app/bootstrap.php';

session_destroy();
header('Location: index.php');
exit();
