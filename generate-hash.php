<?php
$password = "ict123"; // change this if you want

echo "<h3>Password Hash Generator</h3>";
echo "Plain password: <b>$password</b><br><br>";

$hash = password_hash($password, PASSWORD_DEFAULT);

echo "Hashed password:<br>";
echo "<textarea rows='4' cols='90'>$hash</textarea>";
