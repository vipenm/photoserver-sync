<?php

namespace PhotoserverSync;

require_once ('ImageManipulator.php');

$date = shell_exec('find /var/www/html/nextcloud/data/Nextcloud/sneek/files/ -type f -exec stat \{} --printf="%Y\n" \; | sort -n -r | head -n 1');
$path = dirname(__FILE__, 2) . '/config/last_modified.json';
$file = file_get_contents($path);
$json = json_decode($file);
$json_date = $json->last_modified;

if ($json_date == $date) {
    return;
} else {
    $json->last_modified = $date;
    $new_json = json_encode($json);
    file_put_contents($path, $new_json);
    new ImageManipulator();
}
