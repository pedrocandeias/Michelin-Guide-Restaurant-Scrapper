<?php
session_start();

if (isset($_SESSION['downloadLink'])) {
    $filePath = $_SESSION['downloadLink'];
    if (file_exists($filePath)) {
        header('Content-Description: File Transfer');
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . basename($filePath) . '"');
        header('Expires: 0');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        header('Content-Length: ' . filesize($filePath));
        readfile($filePath);
        exit;
    } else {
        echo "File does not exist.";
    }
} else {
    echo "No file to download.";
}
?>
