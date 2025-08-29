<?php 

require __DIR__ . '/../app/bootstrap.php';

$username = 'admin';
$email = 'admin@epiclub.tld';
$password = password_hash('admin', PASSWORD_DEFAULT);
$role = 'admin';

$sql = "INSERT INTO utilisateur (username, email, password, role) VALUES (?,?,?,?)";
$stmt = $db->prepare($sql);
$stmt->bind_param("ssss", $username, $email, $password, $role);
$stmt->execute();