<?
	global $app;
	
	$app['tail_fields'] = array('page_title' => getString('PAGEMENULISTS'));
	
	include_once($action.".php");

	$app["TBS"]->MergeField("action","$action");
?>