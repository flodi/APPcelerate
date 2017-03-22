<?
//
// Refer to the framework object created in index.php
//
global $fw;

//
// Default map MUST be always this
// The map() function has all the three parameters as mandatory
//
$fw->map( 'GET|POST', '/', 'init#init');

//
// Define routes for main APP
// First parameter is the method expected for the route (GET, POST, GET|POST)
// Second parameter is the route that it is to be mapped
// Third parameter is the name of the app and the name of the section of the app I'm mapping to this route
//			This will cause to call the view (.php) and template (.htm) with the same name of the section in the folder of the app
//
// NB: login & logout routes are mandtory
//
$fw->map( 'GET|POST', '/intranet/login/', 'intranet#login');
$fw->map( 'GET|POST', '/intranet/logout/', 'intranet#logout');
$fw->map( 'GET|POST', '/intranet/', 'intranet#home');

$fw->map( 'GET|POST', '/magazzino/logout/', 'intranet#logout');
$fw->map( 'GET|POST', '/magazzino/', 'magazzino#home');

$fw->map( 'POST', '/magazzino/cdc/[a:action]/', 'magazzino#cdc');
$fw->map( 'GET|POST', '/magazzino/cdc/', 'magazzino#cdc');

$fw->map( 'POST', '/magazzino/categorie/[a:action]/', 'magazzino#categorie');
$fw->map( 'GET|POST', '/magazzino/categorie/', 'magazzino#categorie');

$fw->map( 'POST', '/magazzino/attivita/[a:action]/', 'magazzino#attivita');
$fw->map( 'GET|POST', '/magazzino/attivita/', 'magazzino#attivita');

$fw->map( 'POST', '/magazzino/attivita-cdc/[a:action]/', 'magazzino#attivita-cdc');
$fw->map( 'GET|POST', '/magazzino/attivita-cdc/', 'magazzino#attivita-cdc');

$fw->map( 'GET|POST', '/magazzino/valutazione/', 'magazzino#valutazione');
?>