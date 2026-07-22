<?php
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    var_dump('POST', $_POST);
    var_dump('FILES', $_FILES);
    exit;
}
?>
<form method="post" enctype="multipart/form-data">
    <input type="file" name="photo">
    <button type="submit">Envoyer</button>
</form>