<?php
// Sitede duran gizli key (Kullanıcı veya AI bunu göremez)
$SECRET_KEY = "HELALLACRACKLEDIN";

if (isset($_GET['key'])) {
    if ($_GET['key'] === $SECRET_KEY) {
        echo "SUCCESS";
    } else {
        echo "DENIED";
    }
} else {
    echo "INVALID_REQUEST";
}
?>
