<?
/*
 * (c) Fabrizio Lodi <flodi@e-scientia.eu>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

class APPcelerate {
	
	public $app;
	
	const L_DEBUG="100";
	const L_INFO="200";
	const L_NOTICE="250";
	const L_WARNING="300";
	const L_ERROR="400";
	const L_CRITICAL="500";
	const L_ALERT="550";
	const L_EMERCENCY="600";
	
	public function doLog($msg,$level=APPcelerate::L_DEBUG) {
	
		if (array_key_exists("name", $this->app) and $this->app["name"]!=="init") {
			$app_name=$this->app["name"];
		}
		else {
			$app_name="main";
		}
		
		if (array_key_exists("section",$this->app)) {
			$context=array($this->app["section"]);
		}
		else {
			$context=array("main");
		}
	
		switch($level) {
			default:
				$this->app[$app_name."_logger"]->addRecord($level,$msg,$context);
		}
		
	}

    public function __construct() {
	    
		register_shutdown_function(function() {
			$e=error_get_last();
		
			if ($e['type']) {
				$msg=sprintf("Type %u File %s Line %u Message %s",$e["type"],$e["file"],$e["line"],$e["message"]);
				$this->doLog($msg);
			}
		});

		$base_path=$_SERVER["DOCUMENT_ROOT"];
		
		$fwpath=__DIR__;

		$views_path=$base_path."/views";
		$vendor_path=$base_path."/vendor";
		$include_path=$base_path."/include";

		if (set_include_path(get_include_path().PATH_SEPARATOR.$include_path.PATH_SEPARATOR.$vendor_path.PATH_SEPARATOR.$views_path.PATH_SEPARATOR.$fwpath)==false) {
			die("Cannot set include path.");
		}

		include_once("tinybutstrong/tinybutstrong/plugins/tbs_plugin_html.php");

		$this->app["skipui"]=false;
	
		$this->app["base_path"]=$base_path;
		$dotenv = new Dotenv\Dotenv($this->app["base_path"], 'app.config');
		$dotenv->load();
	
		$this->app["base_url"]=getenv('BASE_URL');
	
		$this->app["apps"]=explode("|",getenv('APPS'));
	
		$this->app["base_app"]=getenv('BASE_APP');
		$this->app["default_app"]=getenv('DEFAULT_APP');
	
		$this->app["locale"]=getenv('DEFAULT_LANGUAGE');
	
		# Define Additional templates
		foreach ($this->app["apps"] as $app_name) {
			$add_tpl=getenv('ADD_TPL_'.$app_name);
			if ($add_tpl==="Y") {
				$this->app["addtemplates"][$app_name]=$app_name."_additional_template.htm";
			}
		}

		//
		// Init Log
		//
		//use Monolog\Logger;
		//use Monolog\Formatter\LineFormatter;
		//use Monolog\Handler\StreamHandler;
		//use Monolog\Handler\RavenHandler;
		
		Raven_Autoloader::register();
		
		$this->app["main_logger"]=new Monolog\Logger('appcelerate');
		
		$dateFormat = "d-m-Y G:i";
		$output = "%datetime% ; %level_name% ; %message% ; %context%\n";
		$formatter = new Monolog\Formatter\LineFormatter($output, $dateFormat);
		
		$mainstream=new Monolog\Handler\StreamHandler($this->app["base_path"]."/logs/appcelerate.log", Monolog\Logger::DEBUG);
		$mainstream->setFormatter($formatter);
		
		$this->app["main_logger"]->pushHandler($mainstream);
		
		# Apps Log
		foreach ($this->app["apps"] as $app_name) {
			$this->app[$app_name."_ravenc"]=new Raven_Client('https://f2b7f556cda845cb83c8c7faa8de9134:9b0f361e29a74b89971d7f2486dff827@sentry.io/97912');
			$this->app[$app_name."_ravenh"]= new Monolog\Handler\RavenHandler($this->app[$app_name."_ravenc"]);
			$this->app[$app_name."_ravenh"]->setFormatter(new Monolog\Formatter\LineFormatter("%message% %context% %extra%\n"));
			$this->app[$app_name."_logger"]=new Monolog\Logger($app_name);
			$this->app[$app_name."_log_stream"]=new Monolog\Handler\StreamHandler($this->app["base_path"]."/logs/".$app_name.".log", Monolog\Logger::DEBUG);
			$this->app[$app_name."_log_stream"]->setFormatter($formatter);
			$this->app[$app_name."_logger"]->pushHandler($this->app[$app_name."_log_stream"]);
			$this->app[$app_name."_logger"]->pushHandler($this->app[$app_name."_ravenh"]);
		}
		
		//
		// DB Connection Init
		//
		$db_address=getenv('DB_ADDRESS');
		$db_user=getenv('DB_USER');
		$db_password=getenv('DB_PASSWORD');
		
		foreach ($this->app["apps"] as $app_name) {
			$db_name=getenv('DB_NAME_'.$app_name);
			$this->app["db_".$app_name] = new mysqli($db_address, $db_user, $db_password, $db_name);
			if ($this->app["db_".$app_name]->connect_error) {
			    die("Failed to connect to MySQL: doing new mysqli($db_address, $db_user, $db_password, $db_name) (".$this->app["db_".$app_name]->connect_errno.") ".$this->app["db_".$app_name]->connect_error);
			}
			$this->app["db_".$app_name]->set_charset("utf8");
		}

	}

	public function sqlError($recordset,$query) {
		global $app;
		
		if (!$recordset) {
			doLog("Failed SQL query - Query => $query, Error => ".$app["db_".$app["name"]]->error);
			die("Database error, please contact support\n");
		}
	}
	
	public function destroySession() {
		$_SESSION = array();
		
		if (ini_get("session.use_cookies")) {
		    $params = session_get_cookie_params();
		    setcookie(session_name(), '', time() - 42000,
		        $params["path"], $params["domain"],
		        $params["secure"], $params["httponly"]
		    );
		}
	
		session_destroy();
	
		return;
	}
	
	public function fetchAll ($recordset) {
		$data = [];
		while ($row = $recordset->fetch_array(MYSQLI_NUM)) {
	    	$data[] = $row;
		}
		return $data;
	}
	public function fetchAllAssoc ($recordset) {
		$data = [];
		while ($row = $recordset->fetch_array(MYSQLI_ASSOC)) {
	    	$data[] = $row;
		}
		return $data;
	}
	
	public function getString($token) {
		global $app;
	
	
		foreach ($app["apps"] as $app_name) {
			$sql="select string from strings where token='$token' and id_language=(select id from languages where locale='".$app["locale"]."')";
			$rs=$app["db_".$app_name]->query($sql);
			if (!$rs) {
				sqlError($rs,$sql);
			}
			if ($rs->num_rows!=0) {
				return (utf8_encode($rs->fetch_array()[0]));
			}
		}
		return($token);
	
	}
	
	public function getInclude($type,$params) {
		global $app;
		$mode=$params["mode"];
		$file="/include/";
		
		switch($type) {
			case "js":
				$file.="js/";
				break;
			case "css":
				$file.="css/";
				break;
		}
		
		switch($mode) {
			case "app":
				$file.=$app["name"];
				break;
			case "section":
				$file.=$app["name"]."_".$app["section"];
				break;
		}
	
		switch($type) {
			case "js":
				$file.=".js";
				break;
			case "css":
				$file.=".css";
				break;
		}
		
		$include="";
			
		if (file_exists($app["base_path"].$file)) {
			
			switch($type) {
				case "js":
					$include="<script src=\"".$app["base_url"].$file."\"></script>";
					break;
				case "css":
					$include="<link rel=\"stylesheet\" href=\"".$app["base_url"].$file."\">";
					break;
			}
			
		}
	
		return($include);
		
	}
	
	public function errRoute() {
		global $app;
	
		doLog("Route Error, restarting ".$_SERVER["REQUEST_URI"]);
		
		header("Location: ".$app["base_url"]."/");
	
	}
	
	public function stringForHTML($field,&$value) {
		global $app;
	
		$value=utf8_encode($value);

	}

	public function addMerge($type,$field,$var) {
		global $app;
		
		switch ($type) {
			case "block":
					if (array_key_exists("bmerge", $app)) {
						$app["bmerge"].="|$field;$var";
					}
					else {
						$app["bmerge"]="$field;$var";
					}
				break;
			case "field":
					if (array_key_exists("merge", $app)) {
						$app["merge"].="|$field;$var";
					}
					else {
						$app["merge"]="$field;$var";
					}
				break;
			default:
				die("addMerge called with wrong type - $type");
		}
	}
	
	public function doSecurity() {

		if (!empty($_SESSION[$this->app["name"]."_ap_uid"])) {
			$this->app['uid']=$_SESSION[$this->app["name"]."_ap_uid"];
			$this->app['uname']=$_SESSION[$this->app["name"]."_ap_uname"];
			if(array_key_exists($this->app["name"]."_ap_locale", $_SESSION)) {
				$this->app['locale']=$_SESSION[$$this->app["name"]."_ap_locale"];
			}
		}
		else {
			if (!empty($_REQUEST["login"]) and !empty($_REQUEST["password"])) {
				$sql="select id from users where app='". $this->app["name"] ."' and login='" . $_REQUEST["login"] . "' and pwd='" . $_REQUEST["password"] . "'";
				$rs=$this->app["db_".$this->app["name"]]->query($sql);
				sqlError($rs,$sql);
				switch ($rs->num_rows) {
					case 1:
						$row=$rs->fetch_row();
						$this->app['uid']=$row[0];
						$this->app['uname']=$_REQUEST["login"];
						$_SESSION[$this->app["name"]."_ap_uid"]=$this->app['uid'];
						$_SESSION[$this->app["name"]."_ap_uname"]=$this->app['uname'];
						$sql="select locale from languages where id=(select id_language from users where id=".$this->app["uid"].")";
						$rs1=$$this->app["db_".$this->app["name"]]->query($sql);
						$this->sqlError($rs1,$sql);
						if ($rs1->num_rows!=0) {
							$this->app["locale"]=$rs1->fetch_row()[0];
							$_SESSION[$this->app["name"]."_ap_locale"]=$this->app['locale'];
						}
						header("Location: ".$this->app["base_url"]."/".$this->app["name"]."/");
						break;
					case 0:
						header("Location: ".$this->app["base_url"]."/".$this->app["name"]."/login/?wrong");
						break;
					default:
						header("Location: ".$this->app["base_url"]."/".$this->app["name"]."/login/?multi");
						break;
				}
			}
			else {
				unset($this->app['uid']);
				unset($$this->app['uname']);
				if (!(strpos($_SERVER['REQUEST_URI'],"/login/")) and !(strpos($_SERVER['REQUEST_URI'],"/logout/"))) {
					header("Location: " . $this->app["base_url"] . "/".$this->app["name"] . "/login/?nolo");
				}
			}
		}

	}
	
	public function doApp() {

		$this->doLog("Instance Started");
		
		$this->app["TBS"] = new clsTinyButStrong;
		
		$router = new AltoRouter();
		if (!empty($this->app["base_app"]) or $this->app["base_app"]!=="") {
			$router->setBasePath($this->app["base_app"]);
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
			$this->app["name"]=explode("#",$match["target"])[0];
			$this->app["section"]=explode("#",$match["target"])[1];
			$this->app["params"]=$match["params"];
		
			doLog("=====> Routing for  ".json_encode($match));
			doLog("=====> Starting ".$this->app["name"]."/".$this->app["section"]." (".json_encode($this->app["params"]).")");
		
			//
			// If no App specified, go to default one
			//
			if ($this->app["name"]==="init") {
				header("Location: ".$this->app["base_url"]."/".$this->app["default_app"]."/");
				die();
			}
		
			//
			// Check app name. must be one of defined apps
			//
			if (!in_array($this->app["name"],$this->app["apps"])) {
				die("Error in routes definition: unauthorized app ".$this->app["name"]);
			}
				
			//
			// Security
			//
			doLog("Doing Security ".json_encode($_SESSION));
			include_once("security.php");
		
			//
			// Init app
			//
			doLog("Initializing app ".$this->app["name"]);
			include_once($this->app["name"]."/init.php");
			$this->app["tplfolder"]=$this->app["templates_path"].$this->app["name"]."/".$this->app["section"]."/";
			$this->app["apptplfolder"]=$this->app["templates_path"].$this->app["name"]."/";
			$base_url=$this->app["base_url"];
			$templates_path=$this->app["templates_path"];
			$app_name=$this->app["name"];
			$section_name=$this->app["section"];
			
			//
			// Init section (if exists)
			//
			if (stream_resolve_include_path($this->app["name"]."/".$this->app["section"]."/init.php")) {
				doLog("Initializing section ".$this->app["section"]);
				include_once($this->app["name"]."/".$this->app["section"]."/init.php");
			}
		
			if (!$this->app["skipui"]) {
		
				//
				// Include app header template
				//
				doLog("Loading HEAD template for ".$this->app["name"]);
				$this->app["TBS"]->LoadTemplate($this->app["apptplfolder"]."head.htm");
				
				//
				// Include section header template (if exists)
				//
				doLog("Loading HEAD template for ".$this->app["name"]."/".$this->app["section"]);
				if (stream_resolve_include_path($this->app["tplfolder"]."head.htm")) {
					$this->app["TBS"]->LoadTemplate($this->app["tplfolder"]."head.htm","+");
				}
				else {
					doLog("HEAD template not found for ".$this->app["name"]."/".$this->app["section"]);
				}
		
				//
				// Include section template (if exists)
				//
				doLog("Loading MAIN template for ".$this->app["name"]."/".$this->app["section"]);
				if (stream_resolve_include_path($this->app["tplfolder"]."main.htm")) {
					$this->app["TBS"]->LoadTemplate($this->app["tplfolder"]."main.htm","+");
				}
				else {
					doLog("MAIN template not found for ".$this->app["name"]."/".$this->app["section"]);
				}
		
				//
				// Include section tail template (if exists)
				//
				doLog("Loading TAIL template for ".$this->app["name"]."/".$this->app["section"]);
				if (stream_resolve_include_path($this->app["tplfolder"]."tail.htm")) {
					$this->app["TBS"]->LoadTemplate($this->app["tplfolder"]."tail.htm","+");
				}
				else {
					doLog("TAIL template not found for ".$this->app["name"]."/".$this->app["section"]);
				}
		
			}
			
			//
			// Execute section (if exists)
			//
			if (stream_resolve_include_path($this->app["name"]."/".$this->app["section"]."/main.php")) {
				doLog("Executing section ".$this->app["name"]."/".$this->app["section"]);
				include_once($this->app["name"]."/".$this->app["section"]."/main.php");
			}
		
			if (!$this->app["skipui"]) {
		
				//
				// Include app tail template
				//
				doLog("Loading TAIL template for ".$this->app["name"]);
				$this->app["TBS"]->LoadTemplate($this->app["apptplfolder"]."tail.htm","+");
		
				//
				// Merge default variables
				//
				if (isset($this->app['uname'])) {
					$this->app["TBS"]->MergeField('uname',$this->app['uname']);
				}
				else {
					$this->app["TBS"]->MergeField('uname',"");
				}
				if (isset($this->app['uid'])) {
					$this->app["TBS"]->MergeField('uid',$this->app['uid']);
				}
				else {
					$this->app["TBS"]->MergeField('uid',"");
				}
				$this->app["TBS"]->MergeField('base_url',$this->app["base_url"]);
				$this->app["TBS"]->MergeField('templates_path',$this->app["templates_path"]);
				$this->app["TBS"]->MergeField('app',$this->app["name"]);
				$this->app["TBS"]->MergeField('section',$this->app["section"]);
		
				if(array_key_exists("tail_blocks", $this->app)) {
					foreach($this->app["tail_blocks"] as $block_name => $block_data) {
						$this->app["TBS"]->MergeBlock("$block_name",$block_data);
					}
				}
		
				if(array_key_exists("tail_fields", $this->app)) {
					foreach($this->app["tail_fields"] as $field_name => $field_data) {
						$this->app["TBS"]->MergeField("$field_name",$field_data);
					}
				}
		
				$this->app["TBS"]->MergeField('tokens','getString',true);
				$this->app["TBS"]->MergeField('include','getInclude',true);
		
				$this->app["TBS"]->SetOption('render',TBS_OUTPUT);
				$this->app["TBS"]->Show();
			}
		
			doLog("<===== Ending ".$this->app["name"]."/".$this->app["section"]." (".json_encode($this->app["params"]).")");
		}
		else {
			errRoute();
		}
		
		doLog("<===== Routed for  ".json_encode($match));

	}

}
