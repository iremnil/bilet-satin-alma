<?php
session_start();
session_destroy();
header('Location: index.php');
exit;
// sıfrelerın bır kısmı burda : admin- admin@example.com  admin123.
// companyadminler-pamukkale@example.com pamuk11, metro@example.com metro11 kamılkoc@example.com kamil, varan@example.com varan.
//users- irem@example.com 000irem, leyla@example.com leyla,naz@example.com nazlı.
?>