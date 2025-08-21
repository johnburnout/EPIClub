<?php
require __DIR__.'/vendor/autoload.php';

use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;
use Endroid\QrCode\Color\Color;
use Endroid\QrCode\Encoding\Encoding;

// Paramètres
$data = $_GET['data'] ?? 'https://www.epiclub.fr';
$size = min($_GET['size'] ?? 300, 1000);
$margin = $_GET['margin'] ?? 10;
$id = $_GET['id'] ?? 0;
$display = isset($_GET['display']); // Si ?display=1 est présent, on affiche

// Dossier de sauvegarde
$saveDir = __DIR__.'/qrcodes/';
$filename = $saveDir.'qrcode'.$id.'_'.$size.'.png';

// Vérifier l'existence du fichier
if (file_exists($filename) && $display) {
    header('Content-Type: image/png');
    readfile($filename);
    exit;
}

// Génération
$qrCode = new QrCode($data);
$qrCode->setSize($size);
$qrCode->setMargin($margin);
$qrCode->setEncoding(new Encoding('UTF-8'));
$qrCode->setForegroundColor(new Color(0, 0, 0));
$qrCode->setBackgroundColor(new Color(255, 255, 255));

$writer = new PngWriter();
$result = $writer->write($qrCode);

// Sauvegarde
if ($id > 0) {
    if (!is_dir($saveDir)) mkdir($saveDir, 0755, true);
    file_put_contents($filename, $result->getString());
}

// Affichage conditionnel
if ($display) {
    header('Content-Type: '.$result->getMimeType());
    echo $result->getString();
} else {
    // Retourner un code HTTP 204 (No Content) si on ne veut pas afficher
    http_response_code(204);
}
?>