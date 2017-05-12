<?
/*
 * (c) Fabrizio Lodi <flodi@e-scientia.eu>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

class APPcelerate {
	
	public $app;

	private $bpme;
	
	const L_DEBUG="100";
	const L_INFO="200";
	const L_NOTICE="250";
	const L_WARNING="300";
	const L_ERROR="400";
	const L_CRITICAL="500";
	const L_ALERT="550";
	const L_EMERCENCY="600";
	
	//
	// Logger Function
	//
	public function doLog($msg,$level=APPcelerate::L_DEBUG) {
	
		if (isset($this->app) and  array_key_exists("name", $this->app) and $this->app["name"]!=="init") {
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

	public function setBPME($v) {
		$this->bpme=$v;
	}

	//
	// INIT
	//
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

		$vendor_path=$base_path."/vendor";
		$include_path=$base_path."/include";
		$apps_path=$base_path."/apps";

		if (set_include_path(get_include_path().PATH_SEPARATOR.$vendor_path.PATH_SEPARATOR.$include_path.PATH_SEPARATOR.$fwpath.PATH_SEPARATOR.$apps_path)==false) {
			die("Cannot set include path.");
		}

		include_once("tinybutstrong/tinybutstrong/plugins/tbs_plugin_html.php");

		$this->app["skipui"]=false;
	
		$this->app["apps_path"]=$base_path."/apps";

		$this->app["base_path"]=$base_path;
		$dotenv = new Dotenv\Dotenv($this->app["base_path"], 'app.config');
		$dotenv->load();
	
		$this->app["base_url"]=getenv('BASE_URL');
	
		$this->app["apps"]=explode("|",getenv('APPS'));
	
		$this->app["base_app"]=getenv('BASE_APP');
		$this->app["default_app"]=getenv('DEFAULT_APP');
	
		$this->app["locale"]=getenv('DEFAULT_LANGUAGE');
	
		$this->app["loglevel"]=getenv('LOGLEVEL');

		$this->app["favicon"]=getenv('FAVICON');
		if (!empty($this->app["favicon"])) {
			$cmd=$this->app["base_path"]."/vendor/bin/favicon generate --ico-64 --ico-48 ".$this->app["base_path"]."/include/img/".$this->app["favicon"]." ".$this->app["base_path"];
		}

		$this->app["from_email"]=getenv('FROM_EMAIL');

		#Default template folders
		foreach ($this->app["apps"] as $app_name) {
			$app_path=$base_path."/apps/$app_name";
			$views_path=$base_path."/apps/$app_name/templates";
			if (set_include_path(get_include_path().PATH_SEPARATOR.$app_path.PATH_SEPARATOR.$views_path)==false) {
				die("Cannot set include path.");
			}
		}

		# Define Additional templates
		foreach ($this->app["apps"] as $app_name) {
			$add_tpl=getenv('ADD_TPL_'.$app_name);
			if ($add_tpl==="Y") {
				$this->app["addtemplates"][$app_name]=$app_name."_additional_template.htm";
			}
		}

		# Define Accounts exception
		foreach ($this->app["apps"] as $app_name) {
			$add_tpl=getenv('ACCOUNT_'.$app_name);
			if ($add_tpl==="N") {
				$this->app["accounts"][$app_name]=false;
				$this->app["secredir"][$app_name]=true;
			}
			else if ($add_tpl==="Y") {
				$this->app["accounts"][$app_name]=true;
				$this->app["secredir"][$app_name]=true;
			}
			else if ($add_tpl==="C") {
				$this->app["accounts"][$app_name]=true;
				$this->app["secredir"][$app_name]=false;
			}
		}

		//
		// Init Log
		//		
		$this->app["main_logger"]=new Monolog\Logger('appcelerate');
		
		$dateFormat = "d-m-Y G:i";
		$output = "%datetime% ; %level_name% ; %message% ; %context%\n";
		$formatter = new Monolog\Formatter\LineFormatter($output, $dateFormat);
		
		switch($this->app["loglevel"]) {
			case "info":
				$ll=Monolog\Logger::INFO;
				break;
			default:
				$ll=Monolog\Logger::DEBUG;
		}
		
		$mainstream=new Monolog\Handler\StreamHandler($this->app["base_path"]."/logs/appcelerate.log", $ll);
		$mainstream->setFormatter($formatter);
		
		$this->app["main_logger"]->pushHandler($mainstream);
		
		# Apps Log
		foreach ($this->app["apps"] as $app_name) {
			$this->app[$app_name."_logger"]=new Monolog\Logger($app_name);
			$this->app[$app_name."_log_stream"]=new Monolog\Handler\StreamHandler($this->app["base_path"]."/logs/".$app_name.".log", $ll);
			$this->app[$app_name."_log_stream"]->setFormatter($formatter);
			$this->app[$app_name."_logger"]->pushHandler($this->app[$app_name."_log_stream"]);
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
		
		$this->bpme=false;

		$this->doLog("APPCelerate created for http://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]");

	}

	//
	// Excel Functions
	//

	public function excel2Table($file,$columns,$addcolumns,$temporary=true) {
		$tmptable="import_ospiti_".str_replace(" ","",str_replace(".","",microtime()));

		if ($temporary) {
			$sql="CREATE TEMPORARY TABLE $tmptable (mytmpid INT NOT NULL AUTO_INCREMENT,";
		}
		else {
			$sql="CREATE TABLE $tmptable (mytmpid INT NOT NULL AUTO_INCREMENT,";
		}
		foreach ($columns as $key => $value) {
			$sql.="`$value` text,";
		}
		foreach ($addcolumns as $value) {
			$sql.="`$value` text,";
		}
		$sql.="PRIMARY KEY (mytmpid));";
		$rs=$this->app["db_".$this->app["name"]]->query($sql);
		$this->sqlError($rs,$sql);

		try {
			$x = new SpreadsheetReader($file);
			$x->ChangeSheet("OSPITI");
		}
		catch (Exception $e) {
			throw new Exception($e->getMessage());
		}

		$first=true;
		foreach ($x as $r) {
			if ($first) {
				$first=false;
				$colno=array();
				$missing_error="";
				foreach ($r as $no => $title) {
					if (array_key_exists($title, $columns)) {
						$colno[$no]=$columns[$title];
					}
				}
				if (count($colno)!=count($columns)) {
					throw new Exception("Missing columns: ".implode(",",array_diff(array_keys($columns,$colno))));
				}
			}
			else {
				// Skip empty rows
				$empty=0;
				foreach ($r as $key => $value) {
					if (empty($value)) {
						$empty++;
					}
				}
				if ($empty==count(array_keys($r))) {
					break;
				}
				$sql="insert into $tmptable (id) values (NULL)";
				$rs=$this->app["db_".$this->app["name"]]->query($sql);
				$err=$this->ISsqlError($rs,$sql);
				if ($err) {
					throw new Exception("SQL Error $err");
				}
				$id=$this->app["db_".$this->app["name"]]->insert_id;
				foreach ($colno as $i => $col) {
					if (array_key_exists($i, $r)) {
						$sql="update $tmptable set `$col`='".$this->app["db_".$this->app["name"]]->escape_string($r[$i])."' where mytmpid=$id";
						$rs=$this->app["db_".$this->app["name"]]->query($sql);
						$err=$this->ISsqlError($rs,$sql);
						if ($err) {
							throw new Exception("SQL Error $err");
						}
					}
				}
			}
		}

		return ($tmptable);

	}

	//
	// MySQL function
	//

	public function DBexistRel($rel,$idfromname,$idto) {

		if (!is_numeric($idto)) {
			$idto="'".$idto."'";
		}

		$sql="select count(*) from $rel where $idfromname=$idto";

		$rs=$this->app["db_".$this->app["name"]]->query($sql);
		$this->sqlError($rs,$sql);
		$nr=$rs->fetch_array(MYSQLI_NUM)[0];

		if ($nr==0) {
			return false;
		}
		else {
			return true;
		}

	}

	public function DBfindDup($table,$field) {
		$sql="select $field from $table group by $field having count($field)>1";
		$rs=$this->app["db_".$this->app["name"]]->query($sql);
		$this->sqlError($rs,$sql);
		if ($rs->num_rows>0) {
			return(array_values($rs->fetch_array(MYSQLI_NUM)));
		}
		else {
			return(array());
		}
	}

	public function DBexistRelAll($rel,$idtoname,$tblto,$idfromname="id") {
		global $DBerrMsg;

		$sql1="select count(*) from $rel where $idfromname in (select $idtoname from $tblto)";
		$sql2="select count(distinct $idfromname) from $tblto";

		$rs=$this->app["db_".$this->app["name"]]->query($sql1);
		$this->sqlError($rs,$sql1);
		$nr1=$rs->num_rows;

		$rs=$this->app["db_".$this->app["name"]]->query($sql2);
		$this->sqlError($rs,$sql2);
		$nr2=$rs->num_rows;

		if ($nr1==$nr2) {
			return true;
		}

		$sql="select $idfromname from $rel where $idfromname not in (select $idfromname from $tblto)";
		$rs=$this->app["db_".$this->app["name"]]->query($sql);
		$this->sqlError($rs,$sql);
		$DBerrMsg=explode(",",array_values($rs->fetch_array(MYSQLI_NUM)));

		return false;

	}

	public function DBisRelOK($rel,$idfromname,$idfrom,$idtoname,$idto) {

		if (!is_numeric($idfrom)) {
			$idfrom="'".$idfrom."'";
		}

		if (!is_numeric($idto)) {
			$idto="'".$idto."'";
		}

		$sql="select * from $rel where $idfromname=$idfrom and $idtoname=$idto";

		$rs=$this->app["db_".$this->app["name"]]->query($sql);
		$this->sqlError($rs,$sql);
		$nr=$rs->num_rows;
		if ($nr===0) {
			return false;
		}
		else {
			return true;
		}

	}

	public function DBnumRows($table,$cond="") {
		$sql="select count(*) from $table";
		if (!empty($cond)) {
			$sql.=" where ".$cond;
		}
		$rs=$this->app["db_".$this->app["name"]]->query($sql);
		$this->sqlError($rs,$sql);
		return($rs->fetch_array(MYSQLI_NUM)[0]);
	}

	public function DBnumValues($table,$column) {
		$sql="select $column from $table";
		$rs=$this->app["db_".$this->app["name"]]->query($sql);
		$this->sqlError($rs,$sql);
		while($r=$rs->fetch_array(MYSQLI_NUM)) {
			$v[]=$r[0];
		}
		return(count(array_unique($v)));
	}

	public function ISsqlError($recordset,$query) {
		if (!$recordset) {
			$error=$this->app["db_".$this->app["name"]]->error;
			$this->doLog("Failed SQL query - Query => $query, Error => ".$error);
			return ($error);
		}
		else {
			return (false);
		}
	}

	public function DBsqlError($recordset,$query) {
		sqlError($recordset,$query);
	}

	// DEPRECATED
	public function sqlError($recordset,$query) {
		if (!$recordset) {
			$this->doLog("Failed SQL query - Query => $query, Error => ".$this->app["db_".$this->app["name"]]->error);
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
		foreach ($this->app["apps"] as $app_name) {
			$sql="select string from strings where token='$token' and id_language=(select id from languages where locale='".$this->app["locale"]."')";
			$rs=$this->app["db_".$app_name]->query($sql);
			if (!$rs) {
				$this->sqlError($rs,$sql);
			}
			if ($rs->num_rows!=0) {
				return ($rs->fetch_array()[0]);
			}
		}
		return($token);
	}

	public function genSSO($token) {
		if (array_key_exists($token."_ap_uid",$_SESSION)) {
			$uid=$_SESSION[$token."_ap_uid"];
			$sql="select login,pwd from users where id=$uid";
			$rs=$this->app["db_".$token]->query($sql);
			$this->sqlError($rs,$sql);
			list($login,$password)=$rs->fetch_array(MYSQLI_NUM);
			$sso=base64_encode($login."§".$password);
			return("?sso=".$sso);
		}
		else {
			return("");
		}
	}
	
	public function getInclude($type,$params) {
		$mode=$params["mode"];
		
		if ($mode==="std") {
			switch($type) {
				case "js":
					$c='
		<script src="/vendor/flodi/appcelerate/src/include/js/jquery-2.2.0.min.js"></script>
		<script src="/vendor/flodi/appcelerate/src/include/js/jquery-ui.min.js"></script>
		<script src="/vendor/flodi/appcelerate/src/include/js/bootstrap.min.js"></script>
		<script src="/vendor/flodi/appcelerate/src/include/js/parsley.min.js"></script>
		<script src="/vendor/flodi/appcelerate/src/include/js/i18n/it.js"></script>
		<script src="/vendor/flodi/appcelerate/src/include/js/jquery.keepFormData.min.js"></script>
		<script src="/vendor/flodi/appcelerate/src/include/js/jquery.simple-popup.min.js"></script>
		<script src="/vendor/flodi/appcelerate/src/include/js/jquery.form.js"></script>
		<script src="/vendor/flodi/appcelerate/src/include/js/jquery.treetable.js"></script>
		<script src="/vendor/flodi/appcelerate/src/include/datatables/datatables.min.js"></script>
		<script src="/vendor/flodi/appcelerate/src/include/js/bootstrap-editable.min.js"></script>
					';
					break;
				case "css":
					$c='
		<link rel="stylesheet" href="/vendor/flodi/appcelerate/src/include/css/jquery-ui.min.css">
		<link rel="stylesheet" href="/vendor/flodi/appcelerate/src/include/css/jquery-ui.structure.min.css">
		<link rel="stylesheet" href="/vendor/flodi/appcelerate/src/include/css/jquery-ui.theme.min.css">
		<link rel="stylesheet" href="/vendor/flodi/appcelerate/src/include/css/bootstrap.min.css">
		<link rel="stylesheet" href="/vendor/flodi/appcelerate/src/include/css/bootstrap-theme.min.css">
		<link rel="stylesheet" href="/vendor/flodi/appcelerate/src/include/css/font-awesome.css">
		<link rel="stylesheet" href="/vendor/flodi/appcelerate/src/include/css/parsley.css">
		<link rel="stylesheet" href="/vendor/flodi/appcelerate/src/include/css/jquery.treetable.css">
		<link rel="stylesheet" href="/vendor/flodi/appcelerate/src/include/css/jquery.treetable.theme.default.css">
		<link rel="stylesheet" href="/vendor/flodi/appcelerate/src/include/css/jquery.simple-popup.min.css">
		<link rel="stylesheet" href="/vendor/flodi/appcelerate/src/include/css/jquery.simple-popup.settings.css">
		<link rel="stylesheet" href="/vendor/flodi/appcelerate/src/include/datatables/datatables.min.css">
		<link rel="stylesheet" href="/vendor/flodi/appcelerate/src/include/css/bootstrap-editable.css">
					';
					$c.=favicon(FAVICON_ENABLE_ALL,array(
						'application_name' => $this->app["name"]
					));
					break;					
			}
			
			return($c);

		}
		else {
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
					$file.=$this->app["name"];
					break;
				case "section":
					$file.=$this->app["name"]."_".$this->app["section"];
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
					
			if (file_exists($this->app["base_path"].$file)) {
			
				switch($type) {
					case "js":
						$tag="script";
						break;
					case "css":
						$tag="style";
						break;
				}
				return($this->app["base_path"].$file);		
			}
		}
		
		return(NULL);
		
	}
	
	public function errRoute($redir=true) {
		$this->doLog("Route Error, restarting ".$_SERVER["REQUEST_URI"]);
		if ($redir) {
			header("Location: ".$this->app["base_url"]."/");
			die();
		}
		else {
			die("Routing error, you've done something not permitted");
		}
	}

	public function urlEncode($url) {
		return urlencode($url);
	}
	
	public function stringForHTML($field,&$value) {	
		$value=utf8_encode($value);
	}

	public function addMerge($type,$field,$var) {
		switch ($type) {
			case "block":
				$this->app["tail_blocks"][$field]=$var;
				break;
			case "field":
				$this->app["tail_fields"][$field]=$var;
				break;
			default:
				die("addMerge called with wrong type - $type");
		}
	}
	
	public function logged($app) {
		if (!empty($_SESSION[$app."_ap_uid"])) {
			return (true);
		}
		return (false);

	}

	public function doSecurity() {

		$secredir=$this->app["secredir"][$this->app["name"]];

		if (!empty($_SESSION[$this->app["name"]."_ap_uid"])) {
			$this->doLog("Session uid not empty");
			$this->app['uid']=$_SESSION[$this->app["name"]."_ap_uid"];
			$this->app['uname']=$_SESSION[$this->app["name"]."_ap_uname"];

			$sql="select pwd from users where id=".$this->app['uid'];
			$rs=$this->app["db_".$this->app["name"]]->query($sql);
			$this->sqlError($rs,$sql);

			$this->app['upwd']=$rs->fetch_array(MYSQLI_NUM)[0];

			if(array_key_exists($this->app["name"]."_ap_locale", $_SESSION)) {
				$this->app['locale']=$_SESSION[$this->app["name"]."_ap_locale"];
			}
		}
		else {
			$this->doLog("Session uid empty");
			if (!empty($_REQUEST["sso"])) {
				$this->doLog("SSO requested");
				list($login,$password)=explode("§",base64_decode($_REQUEST["sso"]));
				$_REQUEST["login"]=$login;
				$_REQUEST["password"]=$password;
			}
			if (!empty($_REQUEST["login"]) and !empty($_REQUEST["password"])) {
				$this->doLog("Requested login for ".$_REQUEST["login"]." / ".$_REQUEST["password"]);
				$sql="select id from users where app like '%|". $this->app["name"] ."|%' and login='" . $_REQUEST["login"] . "' and pwd='" . $_REQUEST["password"] . "'";
				$rs=$this->app["db_".$this->app["name"]]->query($sql);
				$this->sqlError($rs,$sql);
				switch ($rs->num_rows) {
					case 1:
						$row=$rs->fetch_row();
						$this->app['uid']=$row[0];
						$this->app['uname']=$_REQUEST["login"];
						$this->app['upwd']=$_REQUEST["password"];
						$_SESSION[$this->app["name"]."_ap_uid"]=$this->app['uid'];
						$_SESSION[$this->app["name"]."_ap_uname"]=$this->app['uname'];
						$sql="select locale from languages where id=(select id_language from users where id=".$this->app["uid"].")";
						$rs1=$this->app["db_".$this->app["name"]]->query($sql);
						$this->sqlError($rs1,$sql);
						if ($rs1->num_rows!=0) {
							$this->app["locale"]=$rs1->fetch_row()[0];
							$_SESSION[$this->app["name"]."_ap_locale"]=$this->app['locale'];
						}
						$this->doLog("[SECURITY OK] Continuing");
						break;
					case 0:
						$this->doLog("[SECURITY KO] redirecting to ".$this->app["base_url"]."/".$this->app["name"]."/login/?wrong");
						if ($secredir) {
							header("Location: ".$this->app["base_url"]."/".$this->app["name"]."/login/?wrong");
							die();
						}
						else {
							$break;
						}
					default:
						$this->doLog("[SECURITY MULTI] redirecting to ".$this->app["base_url"]."/".$this->app["name"]."/login/?wrong");
						if ($secredir) {
							header("Location: ".$this->app["base_url"]."/".$this->app["name"]."/login/?multi");
							die();
						}
				}
			}
			else {
				$this->doLog("No Request login data found");
				unset($this->app['uid']);
				unset($this->app['uname']);
				if (!(strpos($_SERVER['REQUEST_URI'],"/login/")) and !(strpos($_SERVER['REQUEST_URI'],"/logout/"))) {
					$this->doLog($_SERVER['REQUEST_URI']." is not login or logout page => Security Error");
					if ($secredir) {
						$this->doLog("[SECURITY] redirecting to ". $this->app["base_url"] . "/".$this->app["name"] . "/login/?nolo");
						header("Location: " . $this->app["base_url"] . "/".$this->app["name"] . "/login/?nolo");
						die();
					}
				}
			}
		}

	}
	
	public function checkRequest($reqs,$vars) {
		$vals=array();
		foreach ($reqs as $req) {
			if (!array_key_exists($req,$_REQUEST)) {
				$_SESSION["we missing"]=$req;
				return false;
			}
			else {
				$vals["$req"]=$_REQUEST["$req"];
			}
		}
		foreach ($vars as $var) {
			if (array_key_exists($var,$_REQUEST)) {
				$vals["$var"]=$_REQUEST["$var"];
			}
		}
		return $vals;
	}

	public function map($method,$route,$name) {
		$this->app["router"]->map($method,$route,$name);
	}
	
	public function doApp() {

		$this->doLog("Instance Started",$this::L_INFO);
		
		$this->app["TBS"] = new clsTinyButStrong;


		$this->app["router"] = new AltoRouter();
		$this->app["router"]->addMatchTypes(array('l' => '(.+,)*.+'));
		if (!empty($this->app["base_app"]) or $this->app["base_app"]!=="") {
			$this->app["router"]->setBasePath($this->app["base_app"]);
		}
		
		include_once("routes.php");
		
		$match = $this->app["router"]->match();
		
		$this->doLog("App Start - ".json_encode($match),$this::L_INFO);
		
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
		
			$this->doLog("=====> Routing for  ".json_encode($match),$this::L_INFO);
			$this->doLog("=====> Starting ".$this->app["name"]."/".$this->app["section"]." (".json_encode($this->app["params"]).")",$this::L_INFO);
		
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
			if ($this->app["accounts"][$this->app["name"]]) {
				$this->doLog("Doing Security ".json_encode($_SESSION));
				$this->doLog("Accounts Active");
				$this->doSecurity();
			}
			else {
				$this->doLog("Accounts Not Active");
			}
			
			//
			// Set Locale
			//
			if (!setlocale(LC_ALL,$this->app['locale'])) {
				setlocale(LC_ALL,"it_IT");
			}
			
			//
			// Init app variables
			//
			$app_base_path=$this->app["apps_path"]."/".$this->app["name"]."/";
			$sec_base_path=$app_base_path.$this->app["section"]."/";

			$base_url=$this->app["base_url"];

			$app_tpl_path=$app_base_path."templates/";
			$sec_tpl_path=$sec_base_path."templates/";

			$app_vws_path=$app_base_path."views/";
			$sec_vws_path=$sec_base_path."views/";

			$app_name=$this->app["name"];

			$section_name=$this->app["section"];
			
			//
			// Init app (if exists)
			//
			if (stream_resolve_include_path($app_vws_path."init.php")) {
				$this->doLog("Initializing app ".$this->app["name"],$this::L_INFO);
				include_once($app_vws_path."init.php");
			}
			
			
			//
			// Init section (if exists)
			//
			if (stream_resolve_include_path($sec_vws_path."init.php")) {
				$this->doLog("Initializing section ".$this->app["section"],$this::L_INFO);
				include_once($sec_vws_path."init.php");
			}
		
			if ($this->app["skipui"]==false) {
		
				header('Content-type: text/html; charset=UTF-8');

				//
				// Include app header template (if exists)
				//
				$this->doLog("Loading HEAD template for ".$this->app["name"],$this::L_INFO);
				if (stream_resolve_include_path($app_tpl_path."head.htm")) {
					$this->app["TBS"]->LoadTemplate($app_tpl_path."head.htm");
				}
				else {
					$this->doLog("HEAD template not found for ".$this->app["name"],$this::L_INFO);
				}
				
				//
				// Include section header template (if exists)
				//
				$this->doLog("Loading HEAD template for ".$this->app["name"]."/".$this->app["section"],$this::L_INFO);
				if (stream_resolve_include_path($sec_tpl_path."head.htm")) {
					$this->app["TBS"]->LoadTemplate($sec_tpl_path."head.htm","+");
				}
				else {
					$this->doLog("HEAD template not found for ".$this->app["name"]."/".$this->app["section"],$this::L_INFO);
				}
		
				//
				// Include section template (if exists)
				//
				$this->doLog("Loading MAIN template for ".$this->app["name"]."/".$this->app["section"],$this::L_INFO);
				if (stream_resolve_include_path($sec_tpl_path."main.htm")) {
					$this->app["TBS"]->LoadTemplate($sec_tpl_path."main.htm","+");
				}
				else {
					$this->doLog("MAIN template not found for ".$this->app["name"]."/".$this->app["section"],$this::L_INFO);
				}
		
				//
				// Include section tail template (if exists)
				//
				$this->doLog("Loading TAIL template for ".$this->app["name"]."/".$this->app["section"],$this::L_INFO);
				if (stream_resolve_include_path($sec_tpl_path."tail.htm")) {
					$this->app["TBS"]->LoadTemplate($sec_tpl_path."tail.htm","+");
				}
				else {
					$this->doLog("TAIL template not found for ".$this->app["name"]."/".$this->app["section"],$this::L_INFO);
				}
		
			}
			
			//
			// Execute section (if exists)
			//
			if (stream_resolve_include_path($sec_vws_path."main.php")) {
				$this->doLog("Executing section ".$this->app["name"]."/".$this->app["section"],$this::L_INFO);
				include_once($sec_vws_path."main.php");
			}
			else {
				$this->doLog("Section main.php for ".$this->app["name"]."/".$this->app["section"]." not found",$this::L_INFO);
			}
		
			if (!$this->app["skipui"]) {
		
				//
				// Include app tail template (if exists)
				//
				if (stream_resolve_include_path($app_tpl_path."tail.htm")) {
					$this->doLog("Loading TAIL template for ".$this->app["name"],$this::L_INFO);
					$this->app["TBS"]->LoadTemplate($app_tpl_path."tail.htm","+");
				}
				
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
				$this->app["TBS"]->MergeField('app_tpl_path',$app_tpl_path);
				$this->app["TBS"]->MergeField('sec_tpl_path',$sec_tpl_path);
				$this->app["TBS"]->MergeField('app',$this->app["name"]);
				$this->app["TBS"]->MergeField('section',$this->app["section"]);
		
				if(array_key_exists("tail_blocks", $this->app)) {
					foreach($this->app["tail_blocks"] as $block_name => $block_data) {
						$this->doLog("Merging Tail Block $block_name with ".json_encode($block_data));
						$this->app["TBS"]->MergeBlock("$block_name",$block_data);
					}
				}
		
				if(array_key_exists("tail_fields", $this->app)) {
					foreach($this->app["tail_fields"] as $field_name => $field_data) {
						$this->doLog("Merging Tail Field $field_name with '$field_data'");
						$this->app["TBS"]->MergeField("$field_name",$field_data);
					}
				}
				
				$this->app["TBS"]->ObjectRef['fw_obj'] = $this;
				$this->app["TBS"]->MergeField('tokens', '~fw_obj.getString', true);
				$this->app["TBS"]->MergeField('urlencode', '~fw_obj.urlEncode', true);
				$this->app["TBS"]->MergeField('include', '~fw_obj.getInclude', true);
				$this->app["TBS"]->MergeField('sso', '~fw_obj.genSSO', true);
				if ($this->bpme) {
					$this->app["TBS"]->MergeField('bpme', '~bpme_obj.bpmeTBS', true);
				}
		
				$this->app["TBS"]->SetOption('render',TBS_OUTPUT);
				$this->app["TBS"]->Show();
			}
		
			$this->doLog("<===== Ending ".$this->app["name"]."/".$this->app["section"]." (".json_encode($this->app["params"]).")",$this::L_INFO);
		}
		else {
			$this->errRoute();
		}
		
		$this->doLog("<===== Routed for  ".json_encode($match),$this::L_INFO);
		die();

	}

}

class BPME {

	private $app_name;
	private $fw;
	private $db;
	private $logger;
	private $activity_types = array(
		"S" => "Start",
		"F" => "Finish",
		"U" => "Manual",
		"A" => "Automatic",
		"S" => "Sync",
		"C" => "Evaluate condition"
	);

	//
	// $fw - Istanza di framework attiva
	// $name - Nome dell'applicazione sotto la quale gestire i processi 
	//
    public function __construct($fw,$name) {
    	$this->fw=$fw;
    	$this->app_name=$name;
    	$this->db=$fw->app["db_".$name];

    	$this->fw->setBPME(true);

		$this->logger=new Monolog\Logger('bpme');
		
		$dateFormat = "d-m-Y G:i";
		$output = "%datetime% ; %level_name% ; %message% ; %context%\n";
		$formatter = new Monolog\Formatter\LineFormatter($output, $dateFormat);
		
		switch($fw->app["loglevel"]) {
			case "info":
				$ll=Monolog\Logger::INFO;
				break;
			default:
				$ll=Monolog\Logger::DEBUG;
		}
		
		$mainstream=new Monolog\Handler\StreamHandler($fw->app["base_path"]."/logs/bpme.log", $ll);
		$mainstream->setFormatter($formatter);
		
		$this->logger->pushHandler($mainstream);

		$this->fw->app["TBS"]->ObjectRef['bpme_obj'] = $this;
		$this->fw->app["TBS"]->MergeField('bpme', '~bpme_obj.bpmeTBS', true);

	}

	public function bpmeTBS($function,$params) {
		$this->doLog("Requested with function $function and params ".print_r($params,true));
		switch($function) {
			case 'processNameFromProcessInstance':
				return($this->getProcessNameFromProcessInstance($params["id"]));
				break;
			case 'activityNameFromActivityInstance':
				return($this->getActivityNameFromActivityInstance($params["id"]));
				break;
			default:
			return("[Function $function not present]");
		}

	}

	public function engine($function,$params) {
		$this->doLog("Requested with function $function and params ".print_r($params,true));

		switch($function) {
			case 'showActivity':
				return($this->showActivity($params["id"]));
				break;
			case 'followActions':
				return($this->followActions($params["id"],true));
				break;
			default:
				throw new Exception("Function $function not present");
		}
	}

	// Ritorna l'id dell'istanza dell'ultima attività eseguita
	public function startProcess($code,$start='MAIN',$initial_data=array(),$ui=false) {

		$this->doLog("Requested with code $code and start $start and ui $ui",$initial_data);

		$uid=$this->getCurrentUID();

		$sql="select * from processes where code='$code'";
		$rs=$this->db->query($sql);
		try {
			$this->rsCheck($rs);
		}
		catch (Exception $e) {
			$msg=$e->getMessage();
			$this->doLog("F: (P) startProcess | $sql | $msg");
			throw new Exception("Query Error", 0);
		}
		if ($rs->num_rows===0) {
			throw new Exception("Process $code not found", 0);
		}
		$r=$rs->fetch_array(MYSQLI_ASSOC);
		$id_process=$r["id"];

		$sql=sprintf("insert into process_instances (id_process,id_user_created,status,data) values (%d,%d,'R','%s')",$id_process,$uid,json_encode($initial_data));
		$rs=$this->db->query($sql);
		try {
			$this->rsCheck($rs);
		}
		catch (Exception $e) {
			$msg=$e->getMessage();
			$this->doLog("F: (P) startProcess | $sql | $msg");
			throw new Exception("Query Error", 0);
		}
		$id_process_instance=$this->db->insert_id;

		try {
			$id_activity=$this->getActivityID($code,$start);
		}
		catch (Exception $e){
			$msg=$e->getMessage();
			$this->doLog("Cannot create process ( $msg )");
			throw new Exception("Cannot create process ($msg)", 0);
		}


		$id_activity_instance=$this->createActivityInstance($id_process_instance,$id_activity);

		$id_activity_instance=$this->dispatchActivity($id_activity_instance,$ui);

		$this->doLog("Returning process instance $id_process_instance and activity instance $id_activity_instance");

		return(array($id_process_instance,$id_activity_instance));
	}

	private function getProcessInstanceData($id_process_instance) {
		$this->doLog("Requested with process instance $id_process_instance");

		if (!is_numeric($id_process_instance) and !is_int($id_process_instance)) {
			throw new Exception("Process instance id $id_process_instance not valid", 0);
		}
		$sql="select data from process_instances where id=$id_process_instance";
		$rs=$this->db->query($sql);
		try {
			$this->rsCheck($rs);
		}
		catch (Exception $e) {
			$msg=$e->getMessage();
			$this->doLog("$sql ( $msg )");
			throw new Exception("Query Error", 0);
		}
		return(json_decode($rs->fetch_array(MYSQLI_NUM)[0],true));
	}

	private function getProcessIDFromProcessInstance($id_process_instance) {
		$this->doLog("Requested with process instance $id_process_instance");
		if (!is_numeric($id_process_instance) and !is_int($id_process_instance)) {
			throw new Exception("Process instance id $id_process_instance not valid", 0);
		}
		$sql="select id_process from process_instances where id=$id_process_instance";
		$rs=$this->db->query($sql);
		try {
			$this->rsCheck($rs);
		}
		catch (Exception $e) {
			$msg=$e->getMessage();
			$this->doLog("$sql ( $msg )");
			throw new Exception("Query Error", 0);
		}
		return($rs->fetch_array(MYSQLI_NUM)[0]);
	}

	private function getProcessCodeFromProcessInstance($id_process_instance) {
		$this->doLog("Requested with process instance $id_process_instance");
		if (!is_numeric($id_process_instance) and !is_int($id_process_instance)) {
			throw new Exception("Process instance id $id_process_instance not valid", 0);
		}
		$sql="select processes.code from processes join process_instances on processes.id=process_instances.id_process where process_instances.id=$id_process_instance";
		$rs=$this->db->query($sql);
		try {
			$this->rsCheck($rs);
		}
		catch (Exception $e) {
			$msg=$e->getMessage();
			$this->doLog("$sql ( $msg )");
			throw new Exception("Query Error", 0);
		}
		return($rs->fetch_array(MYSQLI_NUM)[0]);
	}

	private function getProcessNameFromProcessInstance($id_process_instance) {
		$this->doLog("Requested with process instance $id_process_instance");
		if (!is_numeric($id_process_instance) and !is_int($id_process_instance)) {
			throw new Exception("Process instance id $id_process_instance not valid", 0);
		}
		$sql="select processes.name from processes join process_instances on processes.id=process_instances.id_process where process_instances.id=$id_process_instance";
		$rs=$this->db->query($sql);
		try {
			$this->rsCheck($rs);
		}
		catch (Exception $e) {
			$msg=$e->getMessage();
			$this->doLog("$sql ( $msg )");
			throw new Exception("Query Error", 0);
		}
		return($rs->fetch_array(MYSQLI_NUM)[0]);
	}

	public function getProcessInstanceFromActivityInstance($id_activity_instance) {
		$this->doLog("Requested with activity instance $id_activity_instance");
		if (!is_numeric($id_activity_instance) and !is_int($id_activity_instance)) {
			throw new Exception("Activity instance id $id_activity_instance not valid", 0);
		}
		$sql="select id_process_instance from activity_instances where id=$id_activity_instance";
		$rs=$this->db->query($sql);
		try {
			$this->rsCheck($rs);
		}
		catch (Exception $e) {
			$msg=$e->getMessage();
			$this->doLog("$sql ( $msg )");
			throw new Exception("Query Error", 0);
		}
		return($rs->fetch_array(MYSQLI_NUM)[0]);
	}

	private function getActivityCodeFromActivityInstance($id_activity_instance) {
		$this->doLog("Requested with activity instance $id_activity_instance");
		if (!is_numeric($id_activity_instance) and !is_int($id_activity_instance)) {
			throw new Exception("Activity instance id $id_activity_instance not valid", 0);
		}
		$sql="select activities.code from activities join activity_instances on activities.id=activity_instances.id_activity where activity_instances.id=$id_activity_instance";
		$rs=$this->db->query($sql);
		try {
			$this->rsCheck($rs);
		}
		catch (Exception $e) {
			$msg=$e->getMessage();
			$this->doLog("$sql ( $msg )");
			throw new Exception("Query Error", 0);
		}
		return($rs->fetch_array(MYSQLI_NUM)[0]);
	}

	private function getActivityNameFromActivityInstance($id_activity_instance) {
		$this->doLog("Requested with activity instance $id_activity_instance");
		if (!is_numeric($id_activity_instance) and !is_int($id_activity_instance)) {
			throw new Exception("Activity instance id $id_activity_instance not valid", 0);
		}
		$sql="select activities.name from activities join activity_instances on activities.id=activity_instances.id_activity where activity_instances.id=$id_activity_instance";
		$rs=$this->db->query($sql);
		try {
			$this->rsCheck($rs);
		}
		catch (Exception $e) {
			$msg=$e->getMessage();
			$this->doLog("$sql ( $msg )");
			throw new Exception("Query Error", 0);
		}
		return($rs->fetch_array(MYSQLI_NUM)[0]);
	}

	private function createActivityInstance($id_process_instance,$id_activity) {

		$this->doLog("Requested with process instance  $id_process_instance and activity $id_activity");

		if (!is_numeric($id_process_instance) and !is_int($id_process_instance)) {
			throw new Exception("Process instance id $id_process_instance not valid", 0);
		}
		if (!is_numeric($id_activity) and !is_int($id_activity)) {
			throw new Exception("Activity id $id_activity not valid", 0);
		}

		$uid=$this->getCurrentUID();

		$id_process=$this->getProcessIDFromProcessInstance($id_process_instance);

		$sql=sprintf("insert into activity_instances (id_activity,id_process,id_process_instance,id_user_created,id_user_assigned) values (%d,%d,%d,%d,%d)",$id_activity,$id_process,$id_process_instance,$uid,$uid);
		$rs=$this->db->query($sql);
		try {
			$this->rsCheck($rs);
		}
		catch (Exception $e) {
			$msg=$e->getMessage();
			$this->doLog("$sql ( $msg )");
			throw new Exception("Query Error", 0);
		}

		$id_activity_instance=$this->db->insert_id;

		return($id_activity_instance);
	}

	private function createActionInstance($id_process_instance,$id_activity_instance_from,$id_action) {

		$this->doLog("Requested with process instance $id_process_instance and activity instance $id_activity_instance_from and action $id_action");
		
		if (!is_numeric($id_process_instance) and !is_int($id_process_instance)) {
			throw new Exception("Process instance id $id_process_instance not valid", 0);
		}
		if (!is_numeric($id_activity_instance_from) and !is_int($id_activity_instance_from)) {
			throw new Exception("Activity instance id $id_activity_instance_from not valid", 0);
		}

		$id_process=$this->getProcessIDFromProcessInstance($id_process_instance);

		$sql=sprintf("insert into action_instances (id_process,id_action,id_activity_instance_from,id_user_executed) values (%d,%d,%d,%d)",$id_process,$id_action,$id_activity_instance_from,$this->getCurrentUID());
		$rs1=$this->db->query($sql);
		try {
			$this->rsCheck($rs1);
		}
		catch (Exception $e) {
			$msg=$e->getMessage();
			$this->doLog("$sql ( $msg )");
			throw new Exception("Query Error", 0);
		}
		$id_action_instance=$this->db->insert_id;
		return($id_action_instance);
	}

	// Ritorna l'id dell'istanza dell'ultima attività eseguita
	private function dispatchActivity($id_activity_instance,$ui=false) {

		$this->doLog("Requested with activity instance  $id_activity_instance and ui $ui");

		if (!is_numeric($id_activity_instance) and !is_int($id_activity_instance)) {
			throw new Exception("Activity instance id $id_activity_instance not valid", 0);
		}

		$sql="select id_activity from activity_instances where id=$id_activity_instance";
		$rs=$this->db->query($sql);
		if ($rs->num_rows===0) {
			throw new Exception("Activity instance $id_activity_instance not found", 0);
		}
		$id_activity=$rs->fetch_array(MYSQLI_NUM)[0];

		$activity_type=$this->getActivityType($id_activity);

		if (!array_key_exists($activity_type,$this->activity_types)) {
			throw new Exception("Activity type $activity_type not allowed", 0);
		}

		switch ($activity_type) {
			case 'S':
				try {
					$this->followActions($id_activity_instance,$ui);
				}
				catch (Exception $e) {
					$msg=$e->getMessage();
					$this->doLog("Cannot follow actions from instance $id_activity_instance ( $msg )");
				}
				return($id_activity_instance);
				break;
			case 'F':
				break;
			case 'U':
				return($id_activity_instance);
				break;
			case 'A':
				try {
					$new_id_activity_instance=$this->executeActivity($id_activity_instance);
				}
				catch (Exception $e) {
					$msg=$e->getMessage();
					$this->doLog("Cannot execute automatic activity instance $id_activity_instance ( $msg )");
				}

				try {
					$this->dispatchActivity($new_id_activity_instance);
				}
				catch (Exception $e) {
					$msg=$e->getMessage();
					$this->doLog("Cannot dispatch activity instance $id_activity_instance ( $msg )");
				}
				return($new_id_activity_instance);
				break;
			case 'S':
				break;
			case 'C':
				break;
		}

	}

	private function executeActivity($id_activity_instance) {
		return($id_action_instance);
	}

	private function showActivity($id_activity_instance) {
		$id_process_instance=$this->getProcessInstanceFromActivityInstance($id_activity_instance);
		$data=$this->getProcessInstanceData($id_process_instance);
		$this->fw->AddMerge("block","process_data",$data);
		$this->fw->app["TBS"]->LoadTemplate($this->app_name."/bpme/templates/".$this->getProcessCodeFromProcessInstance($id_process_instance)."_".$this->getActivityCodeFromActivityInstance($id_activity_instance).".htm","+");
	}

	private function followActions($id_activity_instance_from,$ui=false) {

		$this->doLog("Requested with activty instance from $id_activity_instance_from and ui $ui");

		if (!is_numeric($id_activity_instance_from) and !is_int($id_activity_instance_from)) {
			throw new Exception("Activity instance id $id_activity_instance_from not valid", 0);
		}

		$sql="select * from actions where id_activity_from=(select id_activity from activity_instances where id=$id_activity_instance_from)";
		$rs=$this->db->query($sql);
		try {
			$this->rsCheck($rs);
		}
		catch (Exception $e) {
			$msg=$e->getMessage();
			$this->doLog("$sql ( $msg )");
			throw new Exception("Query Error", 0);
		}

		while ($r=$rs->fetch_array(MYSQLI_ASSOC)) {
			$id_action=$r["id"];

			$id_process_instance=$this->getProcessInstanceFromActivityInstance($id_activity_instance_from);

			$id_action_instance=$this->createActionInstance($id_process_instance,$id_activity_instance_from,$id_action);

			if (!empty($r["entry_condition"])) {
				list($evaluation,$ok)=checkActionCondition($id_activity_instance_from,$id_action,$r["entry_condition"]);
				$sql="update action_instances set entry_condition_evaluation='$evaluation' where id=$id_action_instance";
				$rs1=$this->db->query($sql);
				try {
					$this->rsCheck($rs1);
				}
				catch (Exception $e) {
					$msg=$e->getMessage();
					$this->doLog("$sql ( $msg )");
					throw new Exception("Query Error", 0);
				}
				if (!$ok) {
					break;
				}
			}
			$this->executeAction($id_action_instance,$ui);
		}

		return true;

	}

	private function checkActionCondition($id_activity_instance,$id_action,$condition) {
		$this->doLog("Call $id_activity_instance $id_action $condition");
		return array("",true);
	}

	private function executeAction($id_action_instance,$ui=false) {
		$this->doLog("Requested with action instance $id_action_instance and ui $ui");

		if (!is_numeric($id_action_instance) and !is_int($id_action_instance)) {
			throw new Exception("Activity instance id $id_action_instance not valid", 0);
		}

		//Concludo l'activity precedente
		$sql="update activity_instances set date_completed=now(), id_user_completed=".$this->getCurrentUID()." where id=(select id_activity_instance_from from action_instances where id=$id_action_instance)";
		$rs=$this->db->query($sql);
		try {
			$this->rsCheck($rs);
		}
		catch (Exception $e) {
			$msg=$e->getMessage();
			$this->doLog("$sql ( $msg )");
			throw new Exception("Query Error", 0);
		}

		//Definisco l'activity successiva
		$sql="select id_activity_to from actions where id=(select id_action from action_instances where id=$id_action_instance)";
		$rs=$this->db->query($sql);
		try {
			$this->rsCheck($rs);
		}
		catch (Exception $e) {
			$msg=$e->getMessage();
			$this->doLog("$sql ( $msg )");
			throw new Exception("Query Error", 0);
		}

		$id_activity_to=$rs->fetch_array(MYSQLI_NUM)[0];

		//Cerco l'istanza di processo relativa
		$sql="select id_activity_instance_from from action_instances where id=$id_action_instance";
		$rs=$this->db->query($sql);
		try {
			$this->rsCheck($rs);
		}
		catch (Exception $e) {
			$msg=$e->getMessage();
			$this->doLog("$sql ( $msg )");
			throw new Exception("Query Error", 0);
		}

		$id_activity_instance=$rs->fetch_array(MYSQLI_NUM)[0];

		$id_process_instance=$this->getProcessInstanceFromActivityInstance($id_activity_instance);

		//Creo l'istanza di activity di arrivo
		$id_activity_instance_to=$this->createActivityInstance($id_process_instance,$id_activity_to);

		//Chiudo l'action
		$sql="update action_instances set id_activity_instance_to=$id_activity_instance_to, date_executed=now(), id_user_executed=".$this->getCurrentUID();
		$rs=$this->db->query($sql);
		try {
			$this->rsCheck($rs);
		}
		catch (Exception $e) {
			$msg=$e->getMessage();
			$this->doLog("$sql ( $msg )");
			throw new Exception("Query Error", 0);
		}

		//Esecuo l'activity di arrivo
		$this->dispatchActivity($id_activity_instance_to,$ui);

		return true;
	}

	public function getAvailableActivities($uid=0,$id_process_instance=0) {
		$this->doLog("Requested with uid $uid and process instance $id_process_instance");

		if (!is_numeric($uid) and !is_int($uid)) {
			throw new Exception("User id $uid not valid", 0);
		}
		if (!is_numeric($id_process_instance) and !is_int($id_process_instance)) {
			throw new Exception("Process instance id $id_process_instance not valid", 0);
		}

		$sql="
			select
			activitie_instance.id as id,
			activities.code as code,
			activities.name as name,
			processes.code as process_code,
			processes.name as process_name
			from activity_instances join activities on activities.id=activity_instances.id_activity join processes on processes.id=activity_instances.id_process where date_completed is null
		";
		if ($uid!==0) {
			$sql.=" and id_user_assigned=$uid";			
		}

		if ($id_process_instance!==0) {
			$sql.=" and activity_instances.id_process_instance=$id_process_instance";
		}

		$rs=$this->db->query($sql);
		try {
			$this->rsCheck($rs);
		}
		catch (Exception $e) {
			$msg=$e->getMessage();
			$this->doLog("$sql ( $msg )");
			throw new Exception("Query Error", 0);
		}

		return($this->fw->fetchAllAssoc($rs));
	}

	private function getCurrentUID() {
		return $this->fw->app["uid"];
	}

	private function getActivityID($process_code,$activity_name) {
		$sql="select id from processes where code='$process_code'";
		$rs=$this->db->query($sql);
		if ($rs->num_rows===0) {
			throw new Exception("Process $code not found", 0);
			$this->doLog("Process $code not found");
		}
		$id_process=$rs->fetch_array(MYSQLI_NUM)[0];
		$sql="select id from activities where id_process=$id_process";
		if (!empty($activity_type)) {

			if (array_key_exists($activity_type,$this->activity_types)) {
				$sql.=" and activity_type='$activity_type'";
			}
			else {
				$this->doLog("Activity type $activity_type not allowed");
				throw new Exception("Activity type $activity_type not allowed", 0);
			}
		}
		$rs=$this->db->query($sql);
		try {
			$this->rsCheck($rs);
		}
		catch (Exception $e) {
			$msg=$e->getMessage();
			$this->doLog("$sql ( $msg )");
			throw new Exception("Query Error", 0);
		}
		return($rs->fetch_array(MYSQLI_NUM)[0]);
	}

	private function getActivityType($id_activity) {
		if (!is_numeric($id_activity) and !is_int($id_activity)) {
			throw new Exception("Activity id $id_activity not valid", 0);
		}

		$sql="select activity_type from activities where id=$id_activity";
		$rs=$this->db->query($sql);
		if ($rs->num_rows===0) {
			throw new Exception("Activity id $id_activity not found", 0);
		}

		return ($rs->fetch_array(MYSQLI_NUM)[0]);
	}

	private function doLog($msg,$context='N',$id_instance=0,$level=APPcelerate::L_DEBUG) {
		if (!is_numeric($id_instance) and !is_int($id_instance)) {
			throw new Exception("ID instance $id_instance not valid", 0);
		}

		$debug=debug_backtrace()[1];
		$caller_file=str_replace($_SERVER["DOCUMENT_ROOT"],"",$debug["file"]);
		$caller_line=$debug["line"];
		$caller_function=$debug["function"];

		switch ($context) {
			case "P":
				$where="Process";
				$sql="select processes.code,process_instances.id,0,0 from process_instances join processes on processes.id=process_instances.id_process where id=$id_instance";
				break;
			case "A":
				$where="Activity";
				$sql="select activities.code,activity_instances.id_proces,activity_instances.id,0 from activity_instances join processes on activities.id=activity_instances.id_activity where id=$id_instance";
				break;
			case "F":
				$where="Action";
				$sql="select actions.code,action_instances.id_process,action_instances.id_activity,action_instances.id from action_instances join actions on actions.id=action_instances.id_action where id=$id_instance";
				break;
			default:
				$where="BPME";
				$sql="select 'BPME','BPME','BPME','BPME'";
		}
		$rs=$this->db->query($sql);
		list($code,$process,$action,$activity)=$rs->fetch_array(MYSQLI_NUM);
		if (array_key_exists("uname",$this->fw->app)) {
			$uname=$this->fw->app["uname"];
		}
		else {
			$uname="NOLOGGEDUSER";
		}

		$acontext=array(
		);

		$msg="$uname ; $caller_file $caller_line $caller_function ; $where ; $code ; $process; $activity ; $action ; ".$msg;

		switch($level) {
			default:
				$this->logger->addRecord($level,$msg,$acontext);
		}
		
	}

	private function rsCheck($rs) {
		if ($rs) {
			return true;
		}

		$error=$this->db->error;

		throw new Exception($error, 0);
		

	}

}
