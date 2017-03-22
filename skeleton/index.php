<?
//
// index.php to be used for APPcelerate framework
// this file MUST be placed in your HTTP home folder
// DO NOT CHANGE ANYTHING if not clearly requested in the comments
//
// APPcelerate expect to find in the same directory:
// - app.config (app configuration)
// - a folder for each app you make with the same APPcelerate installation, with inside:
//	- a "template" folder, with the TinyButStrong templates
//	- a "views" folder, with the PHP actions
//

//
// Comment the lines below when you are sure all it is ok!
//
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

//
// Start session to enable user security
//
session_start();

//
// Include Composer libraries and HTML plugin for TinyButStrong, the template manager
//
include_once("vendor/autoload.php");

//
// Create the framework
//
$fw=new APPcelerate;

//
// Init the framework
//
//Parameters:
// 1- the current work directory of 
//
$fw->doApp();

//
//
//
die();
?>