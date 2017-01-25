<?
global $app;

$TBS->VarRef['errmsg']='no';

if (array_key_exists("wrong",$_REQUEST)) {
	$TBS->VarRef['errmsg']="Login e Password non riconosciuti.";
}

$TBS->LoadTemplate($app["templates_path"]."app/login.htm","+");
?>