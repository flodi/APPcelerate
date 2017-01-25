<?
global $router;

$router->map( 'GET', '/', 'init#');

#
# APP
#

$router->map( 'GET', '/app/', 'app#main');
$router->map( 'GET', '/app/section/', 'app#section');
$router->map( 'GET|POST', '/app/login/', 'app#login');
$router->map( 'GET', '/app/logout/', 'app#logout');
?>