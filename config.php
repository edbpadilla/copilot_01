<?php


//skip the config file if somebody call it from the browser.
if (stristr($_SERVER['PHP_SELF'], "config.php")) {
    Header("Location: index.php");
    die();
}

//your databse hostname.
$db_host = "localhost";
$databse_name = "vms_vms";
$db_username = "vms_vms";
$db_password = "vms_vms";

//tables prefix. Don't change unless you change this value in the db.
$prefix = "m";
