<?
/*
 * (c) Fabrizio Lodi <flodi@e-scientia.eu>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

class APPcelerate
{
    public function say($toSay = "Nothing given")
    {
        return $toSay;
    }
}

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

function logCrash() {
	$e=error_get_last();

	if ($e['type']) {
		$msg=sprintf("Type %u File %s Line %u Message %s",$e["type"],$e["file"],$e["line"],$e["message"]);
		doLog($msg);
	}
}

register_shutdown_function('logCrash');

global $app;

include_once("include/init.php");

doLog("Instance Started");

$app["TBS"] = new clsTinyButStrong;

$router = new AltoRouter();
if (!empty($app["base_app"]) or $app["base_app"]!=="") {
	$router->setBasePath($app["base_app"]);
}

include_once("routes.php");

$match = $router->match();

doLog("App Start - ".json_encode($match));

if ($match) {
	//
	// If no Section specified, error
	//
	if (!array_key_exists(1, explode("#",$match["target"])) or empty(explode("#",$match["target"]))) {
		die("Error in routes definition: missing section");		
	}
	$app["name"]=explode("#",$match["target"])[0];
	$app["section"]=explode("#",$match["target"])[1];
	$app["params"]=$match["params"];

	doLog("=====> Routing for  ".json_encode($match));
	doLog("=====> Starting ".$app["name"]."/".$app["section"]." (".json_encode($app["params"]).")");

	//
	// If no App specified, go to default one
	//
	if ($app["name"]==="init") {
		header("Location: ".$app["base_url"]."/".$app["default_app"]."/");
		die();
	}

	//
	// Check app name. must be one of defined apps
	//
	if (!in_array($app["name"],$app["apps"])) {
		die("Error in routes definition: unauthorized app ".$app["name"]);
	}
		
	//
	// Security
	//
	doLog("Doing Security ".json_encode($_SESSION));
	include_once("security.php");

	//
	// Init app
	//
	doLog("Initializing app ".$app["name"]);
	include_once($app["name"]."/init.php");
	$app["tplfolder"]=$app["templates_path"].$app["name"]."/".$app["section"]."/";
	$app["apptplfolder"]=$app["templates_path"].$app["name"]."/";
	$base_url=$app["base_url"];
	$templates_path=$app["templates_path"];
	$app_name=$app["name"];
	$section_name=$app["section"];
	
	//
	// Init section (if exists)
	//
	if (stream_resolve_include_path($app["name"]."/".$app["section"]."/init.php")) {
		doLog("Initializing section ".$app["section"]);
		include_once($app["name"]."/".$app["section"]."/init.php");
	}

	if (!$app["skipui"]) {

		//
		// Include app header template
		//
		doLog("Loading HEAD template for ".$app["name"]);
		$app["TBS"]->LoadTemplate($app["apptplfolder"]."head.htm");
		
		//
		// Include section header template (if exists)
		//
		doLog("Loading HEAD template for ".$app["name"]."/".$app["section"]);
		if (stream_resolve_include_path($app["tplfolder"]."head.htm")) {
			$app["TBS"]->LoadTemplate($app["tplfolder"]."head.htm","+");
		}
		else {
			doLog("HEAD template not found for ".$app["name"]."/".$app["section"]);
		}

		//
		// Include section template (if exists)
		//
		doLog("Loading MAIN template for ".$app["name"]."/".$app["section"]);
		if (stream_resolve_include_path($app["tplfolder"]."main.htm")) {
			$app["TBS"]->LoadTemplate($app["tplfolder"]."main.htm","+");
		}
		else {
			doLog("MAIN template not found for ".$app["name"]."/".$app["section"]);
		}

		//
		// Include section tail template (if exists)
		//
		doLog("Loading TAIL template for ".$app["name"]."/".$app["section"]);
		if (stream_resolve_include_path($app["tplfolder"]."tail.htm")) {
			$app["TBS"]->LoadTemplate($app["tplfolder"]."tail.htm","+");
		}
		else {
			doLog("TAIL template not found for ".$app["name"]."/".$app["section"]);
		}

	}
	
	//
	// Execute section (if exists)
	//
	if (stream_resolve_include_path($app["name"]."/".$app["section"]."/main.php")) {
		doLog("Executing section ".$app["name"]."/".$app["section"]);
		include_once($app["name"]."/".$app["section"]."/main.php");
	}

	if (!$app["skipui"]) {

		//
		// Include app tail template
		//
		doLog("Loading TAIL template for ".$app["name"]);
		$app["TBS"]->LoadTemplate($app["apptplfolder"]."tail.htm","+");

		//
		// Merge default variables
		//
		if (isset($app['uname'])) {
			$app["TBS"]->MergeField('uname',$app['uname']);
		}
		else {
			$app["TBS"]->MergeField('uname',"");
		}
		if (isset($app['uid'])) {
			$app["TBS"]->MergeField('uid',$app['uid']);
		}
		else {
			$app["TBS"]->MergeField('uid',"");
		}
		$app["TBS"]->MergeField('base_url',$app["base_url"]);
		$app["TBS"]->MergeField('templates_path',$app["templates_path"]);
		$app["TBS"]->MergeField('app',$app["name"]);
		$app["TBS"]->MergeField('section',$app["section"]);

		if(array_key_exists("tail_blocks", $app)) {
			foreach($app["tail_blocks"] as $block_name => $block_data) {
				$app["TBS"]->MergeBlock("$block_name",$block_data);
			}
		}

		if(array_key_exists("tail_fields", $app)) {
			foreach($app["tail_fields"] as $field_name => $field_data) {
				$app["TBS"]->MergeField("$field_name",$field_data);
			}
		}

		$app["TBS"]->MergeField('tokens','getString',true);
		$app["TBS"]->MergeField('include','getInclude',true);

		$app["TBS"]->SetOption('render',TBS_OUTPUT);
		$app["TBS"]->Show();
	}

	doLog("<===== Ending ".$app["name"]."/".$app["section"]." (".json_encode($app["params"]).")");
}
else {
	errRoute();
}

doLog("<===== Routed for  ".json_encode($match));

?>
