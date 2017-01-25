<?
global $app;

destroySession();

header("Location: ".$app["base_url"].$app["name"]."/");
?>