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

		$this->writeLog($this->app[$app_name."_logger"],$level,$msg,$context);

	}

	public function writeLog($logger,$level,$msg,$context) {

		if ($level>=$this->app["loglevel"]) {
			if (array_key_exists("uname",$this->app)) {
				$uname=$this->app["uname"];
			}
			else {
				$uname="NOLOGGEDUSER";
			}

			$debug=debug_backtrace()[2];
			if (array_key_exists("file",$debug)) {
				$caller_file=str_replace($_SERVER["DOCUMENT_ROOT"],"",$debug["file"]);
			}
			else {
				$caller_file="-";
			}
			if (array_key_exists("line",$debug)) {
				$caller_line=$debug["line"];
			}
			else {
				$caller_line="-";
			}
			$caller_function=$debug["function"];

			$msg="$uname ; $caller_file $caller_line $caller_function ; ".$msg;

			switch($level) {
				default:
					$logger->addRecord($level,$msg,$context);
			}
		}
	}

	public function setBPME($v) {
		$this->bpme=$v;
	}

	//
	// INIT
	//
    public function __construct() {
		ini_set('display_errors', 1);
		ini_set('display_startup_errors', 1);
		error_reporting(E_ALL);

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
		$this->app["skipsec"]=false;

		$this->app["apps_path"]=$base_path."/apps";

		$this->app["base_path"]=$base_path;
		$dotenv = new Dotenv\Dotenv($this->app["base_path"], 'app.config');
		$dotenv->load();

		$this->app["base_url"]=getenv('BASE_URL');

		$this->app["apps"]=explode("|",getenv('APPS'));

		$this->app["default_app"]=getenv('DEFAULT_APP');

		$this->app["locale"]=getenv('DEFAULT_LANGUAGE');

		$this->app["loglevel"]=constant("APPcelerate::L_".strtoupper(getenv('LOGLEVEL')));

		$this->app["aws_key"]=getenv('AWS_KEY');
		$this->app["aws_code"]=getenv('AWS_CODE');

		$this->app["from_email"]=getenv('FROM_EMAIL');

		$this->app["session_mins"]=getenv('SESSION_MINS');
		ini_set("session.gc_maxlifetime",60*$this->app["session_mins"]);
		session_set_cookie_params(60*$this->app["session_mins"],"/");

		$this->app["bootstrap"]=getenv('BOOTSTRAP_VERSION');
		$this->app["fontawesome"]=getenv('FONTAWESOME_VERSION');

		$this->app["envlevel"]=getenv('ENVLEVEL');
		if (empty($this->app["envlevel"])) {
			$this->app["envlevel"]="DEVELOPMENT";
		}


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
			// Se è la prima riga, controllo che ci siano tutte le intestazioni corrette
			if ($first) {

				$first=false;
				$excel=array();
				$missings=array();

				foreach ($columns as $excel_field => $table_field) {
					$found=false;
					foreach ($r as $excel_field_no => $excel_field_name) {
						if ($excel_field===$excel_field_name) {
							$excel[$excel_field_no]=$table_field;
							$found=true;
							break;
						}
					}

					if (!$found) {
						$missings[]=$excel_field;
					}

				}

				if (count($missings)!=0) {
					throw new Exception("Missing columns: ".implode(",",$missings));
				}
			}
			// Se non è la prima riga, scrivo i dati
			else {

				// Skip empty rows
				$empty=0;
				foreach ($r as $key => $value) {
					if (empty($value)) {
						// Conto i campi vuoti per vedere se è una riga vuota
						$empty++;
					}
				}
				// Se è una riga vuota la salto
				if ($empty==count(array_keys($r))) {
					break;
				}

				// Inserisco una riga vuota
				$sql="insert into $tmptable (mytmpid) values (NULL)";
				$rs=$this->app["db_".$this->app["name"]]->query($sql);
				$err=$this->ISsqlError($rs,$sql);
				if ($err) {
					throw new Exception("SQL Error $err");
				}

				// Recuper l'ID della riga
				$id=$this->app["db_".$this->app["name"]]->insert_id;

				// Inserisco i valori campo per campo
				foreach ($excel as $i => $name) {
					if (array_key_exists($i, $r)) {
						$sql="update $tmptable set `$name`='".$this->app["db_".$this->app["name"]]->escape_string(trim($r[$i],"_"))."' where mytmpid=$id";
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

	public function excel2Table_2($file,$columns,$addcolumns,$temporary=true) {
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


		$reader = new \PhpOffice\PhpSpreadsheet\Reader\Xlsx();
		$reader->setReadDataOnly(true);
		$reader->setLoadSheetsOnly(["OSPITI"]);
			
		try {
			$x = $reader->load($file);
		}
		catch (Exception $e) {
			throw new Exception($e->getMessage());
		}

		$first=true;
		foreach ($x as $r) {
			// Se è la prima riga, controllo che ci siano tutte le intestazioni corrette
			if ($first) {

				$first=false;
				$excel=array();
				$missings=array();

				foreach ($columns as $excel_field => $table_field) {
					$found=false;
					foreach ($r as $excel_field_no => $excel_field_name) {
						if ($excel_field===$excel_field_name) {
							$excel[$excel_field_no]=$table_field;
							$found=true;
							break;
						}
					}

					if (!$found) {
						$missings[]=$excel_field;
					}

				}

				if (count($missings)!=0) {
					throw new Exception("Missing columns: ".implode(",",$missings));
				}
			}
			// Se non è la prima riga, scrivo i dati
			else {

				// Skip empty rows
				$empty=0;
				foreach ($r as $key => $value) {
					if (empty($value)) {
						// Conto i campi vuoti per vedere se è una riga vuota
						$empty++;
					}
				}
				// Se è una riga vuota la salto
				if ($empty==count(array_keys($r))) {
					break;
				}

				// Inserisco una riga vuota
				$sql="insert into $tmptable (mytmpid) values (NULL)";
				$rs=$this->app["db_".$this->app["name"]]->query($sql);
				$err=$this->ISsqlError($rs,$sql);
				if ($err) {
					throw new Exception("SQL Error $err");
				}

				// Recuper l'ID della riga
				$id=$this->app["db_".$this->app["name"]]->insert_id;

				// Inserisco i valori campo per campo
				foreach ($excel as $i => $name) {
					if (array_key_exists($i, $r)) {
						$sql="update $tmptable set `$name`='".$this->app["db_".$this->app["name"]]->escape_string($r[$i])."' where mytmpid=$id";
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
			$this->doLog("Failed SQL query - Query => $query, Error => ".$error,APPcelerate::L_ERROR);
			return ($error);
		}
		else {
			return (false);
		}
	}

	public function DBsqlError($recordset,$query) {
		if (!$recordset) {
			$this->doLog("Failed SQL query - Query => $query, Error => ".$this->app["db_".$this->app["name"]]->error,APPcelerate::L_ERROR);
			throw new Exception("Database error, please contact support", 1);
		}
	}

	// DEPRECATED
	public function sqlError($recordset,$query) {
		if (!$recordset) {
			$this->doLog("Failed SQL query - Query => $query, Error => ".$this->app["db_".$this->app["name"]]->error,APPcelerate::L_ERROR);
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

	public function getUserFullName($uid) {
		$sql="select fullname from users where id=$uid";
		$rs=$this->app["db_".$this->app["name"]]->query($sql);
		$this->sqlError($rs,$sql);
		return($rs->fetch_array(MYSQLI_NUM)[0]);
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
		<script src="https://code.jquery.com/jquery-3.3.1.min.js" integrity="sha256-FgpCb/KJQlLNfOu91ta32o/NMZxltwRo8QtmkMRdAu8=" crossorigin="anonymous"></script>
		<script src="https://code.jquery.com/ui/1.12.1/jquery-ui.min.js" integrity="sha256-VazP97ZCwtekAsvgPBSUwPFKdrwD3unUfSGVYrahUqU=" crossorigin="anonymous"></script>
		<script src="/vendor/flodi/appcelerate/src/include/js/jquery-migrate-3.0.0.min.js"></script>';
					if ($this->app["bootstrap"]==3) {
						$c.='
		<script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/js/bootstrap.min.js" integrity="sha384-Tc5IQib027qvyjSMfHjOMaLkfuWVxZxUPnCJA7l2mCWNIpG9mGCD8wGNIcPD7Txa" crossorigin="anonymous"></script>
						';
					}
					if ($this->app["bootstrap"]==4) {
						$c.='
		<script src="https://maxcdn.bootstrapcdn.com/bootstrap/4.0.0/js/bootstrap.min.js" integrity="sha384-JZR6Spejh4U02d8jOt6vLEHfe/JQGiRRSQQxSfFWpi1MquVdAyjUar5+76PVCmYl" crossorigin="anonymous"></script>
						';
					}
					$c.='
		<script type="text/javascript" src="https://cdnjs.cloudflare.com/ajax/libs/tabulator/3.3.3/js/tabulator.min.js"></script>
		<script type="text/javascript" src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.12.8/xlsx.core.min.js"></script>
		<script type="text/javascript" src="https://cdnjs.cloudflare.com/ajax/libs/FileSaver.js/1.3.8/FileSaver.min.js"></script>
		<script type="text/javascript" src="https://cdnjs.cloudflare.com/ajax/libs/TableExport/5.0.0/js/tableexport.min.js"></script>
		<script type="text/javascript" src="https://cdnjs.cloudflare.com/ajax/libs/moment.js/2.22.1/moment-with-locales.min.js"></script>
		<script src="https://unpkg.com/jspdf@latest/dist/jspdf.min.js"></script>
		<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.12.0/xlsx.full.min.js"></script>
		<script src="/vendor/flodi/appcelerate/src/include/js/moment-with-locales.js"></script>
		<script src="/vendor/flodi/appcelerate/src/include/js/parsley.min.js"></script>
		<script src="/vendor/flodi/appcelerate/src/include/js/i18n/it.js"></script>
		<script src="/vendor/flodi/appcelerate/src/include/js/jquery.form.js"></script>
		<script src="/vendor/flodi/appcelerate/src/include/js/select2.full.min.js"></script>
		<script src="/vendor/flodi/appcelerate/src/include/js/bootstrap-editable.min.js"></script>
		<script src="/vendor/flodi/appcelerate/src/include/js/typeaheadjs.js"></script>
		<script src="/vendor/flodi/appcelerate/src/include/js/garlic.min.js"></script>
		<script src="/vendor/flodi/appcelerate/src/include/js/jquery.fixedheadertable.min.js"></script>
		<script src="/vendor/flodi/appcelerate/src/include/js/appcelerate.js"></script>
					';
					break;
				case "css":
					$c='
		<link rel="stylesheet" href="https://code.jquery.com/ui/1.12.1/themes/base/jquery-ui.css">
					';
					if ($this->app["bootstrap"]==3 or !array_key_exists("bootstrap", $this->app) or empty($this->app["bootstrap"])) {
						$c.='
		<link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/css/bootstrap.min.css" integrity="sha384-BVYiiSIFeK1dGmJRAkycuHAHRg32OmUcww7on3RYdg4Va+PmSTsz/K68vbdEjh4u" crossorigin="anonymous">
		<link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/css/bootstrap-theme.min.css" integrity="sha384-rHyoN1iRsVXV4nD0JutlnGaslCJuC7uwjduW9SVrLvRYooPp2bWYgmgJQIXwl/Sp" crossorigin="anonymous">
						';
					}
					if ($this->app["bootstrap"]==4) {
						$c.='
		<link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/4.0.0/css/bootstrap.min.css" integrity="sha384-Gn5384xqQ1aoWXA+058RXPxPg6fy4IWvTNh0E263XmFcJlSAwiGgFAW/dAiS6JXm" crossorigin="anonymous">
						';
					}
					$c.='
		<link href="https://cdnjs.cloudflare.com/ajax/libs/tabulator/3.3.3/css/tabulator.min.css" rel="stylesheet">
		<link href="https://cdnjs.cloudflare.com/ajax/libs/TableExport/5.0.0/css/tableexport.min.css" rel="stylesheet">
		<link rel="stylesheet" href="/vendor/flodi/appcelerate/src/include/css/parsley.css">
		<link rel="stylesheet" href="/vendor/flodi/appcelerate/src/include/css/jquery.treetable.css">
		<link rel="stylesheet" href="/vendor/flodi/appcelerate/src/include/css/jquery.treetable.theme.default.css">
		<link rel="stylesheet" href="/vendor/flodi/appcelerate/src/include/css/select2-bootstrap.min.css">
		<link rel="stylesheet" href="/vendor/flodi/appcelerate/src/include/css/typeahead.js-bootstrap.css">
		<link rel="stylesheet" href="/vendor/flodi/appcelerate/src/include/css/bootstrap-editable.css">';
					if($this->app["fontawesome"]==5) {
						$c.='
		<script defer src="https://use.fontawesome.com/releases/v5.0.6/js/all.js"></script>
						';
					}
					else {
						$c.='
		<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.7.0/css/font-awesome.min.css">
						';
					}
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

	public function  sendEmail($body, $subject, $from, $to, $cc=array(),$bcc=array(), $files=array()) {

		if (!is_array($to)) {
			$to=array($to);
		}

		if (!is_array($bcc)) {
			$bcc=array($bcc);
		}

		$m = new SimpleEmailServiceMessage();
		$m->setFrom($from);
		$m->addReplyTo($from);
		$m->setReturnPath($from);

		foreach ($files as $name => $path) {
		    $m->addAttachmentFromFile("$name",$path,'application/octet-stream', "<$name>" , 'inline');
		}

		$m->addTo($to);
		$m->addCC($cc);
		$m->addBCC($bcc);
		$m->setSubject($subject);
		$m->setMessageFromString(strip_tags($body), $body);
		$ses = new SimpleEmailService($this->app["aws_key"], $this->app["aws_code"], 'email.eu-west-1.amazonaws.com', true);
		$result = $ses->sendEmail($m);
		$ses_messageid = $result['MessageId'];
		$ses_requestid = $result['RequestId'];

		$sql = "insert into ses_log (messageid, requestid, object) value ('$ses_messageid','$ses_requestid','".addslashes(json_encode($m))."')";
		$rs = $this->app["db_".$this->app["name"]]->query($sql);
		$this->sqlError($rs, $sql);
	}

	public function errRoute($redir=true) {
		$actual_link = (isset($_SERVER['HTTPS']) ? "https" : "http") . "://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]";
		$this->doLog("Route Error, restarting $actual_link",APPcelerate::L_ERROR);
		if ($redir) {
			echo "
<html>
	<head>
		<meta http-equiv=\"refresh\" content=\"5;url=".$this->app["base_url"]."\" />
    </head>
    <body>
        <h1>Error: you tried to to something that it is not allowed</h1>
        <h2>You'll be redirected to home page in 5 seconds...</h2>
        <i>Info:<br>".$actual_link."<br>".$_SERVER["SERVER_NAME"]."<br>".$_SERVER["DOCUMENT_ROOT"]."</i>
    </body>
</html>";
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

	public function logged($app="") {
		foreach ($this->app["apps"] as $app_name) {
			if (!empty($app) and $app_name!==$app) {
				continue;
			}
			if (!empty($_SESSION[$app_name."_ap_uid"])) {
				return (true);
			}
		}
		return (false);
	}

	public function userAppAble($uid,$app) {
		$sql="select * from users where id=$uid and app like '%|$app|%'";
		$rs=$this->app["db_".$this->app["name"]]->query($sql);
		$this->DBsqlError($rs,$sql);
		if ($rs->num_rows>0) {
			return true;
		}
		else {
			return false;
		}
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
						$this->doLog("[SECURITY OK] Found user");
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
					$this->doLog($_SERVER['REQUEST_URI']." is not login or logout page => Security Error",APPcelerate::L_ERROR);
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
		session_start(array("gc_maxlifetime" =>1800, "cookie_lifetime" => 1800, ));

		$this->doLog("Instance Started",$this::L_INFO);

		$this->app["TBS"] = new clsTinyButStrong;


		$this->app["router"] = new AltoRouter();
		$this->app["router"]->addMatchTypes(array('l' => '(\d+?,?)+'));

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
				$this->doLog("Initializing section after security ".$this->app["section"],$this::L_INFO);
				include_once($sec_vws_path."init.php");
			}

			//
			// Security
			//
			if ($this->app["accounts"][$this->app["name"]] and !$this->app["skipsec"]) {
				$this->doLog("Doing Security ".json_encode($_SESSION));
				$this->doLog("Accounts Active");
				$this->doSecurity();
			}
			else {
				$this->doLog("Accounts Not Active");
			}

			//
			// Init app after security (if exists)
			//
			if (stream_resolve_include_path($app_vws_path."init_ws.php")) {
				$this->doLog("Initializing app after security ".$this->app["name"],$this::L_INFO);
				include_once($app_vws_path."init_ws.php");
			}

			//
			// Init section after security (if exists)
			//
			if (stream_resolve_include_path($sec_vws_path."init_ws.php")) {
				$this->doLog("Initializing section ".$this->app["section"],$this::L_INFO);
				include_once($sec_vws_path."init_ws.php");
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
				// Include section tail template (if exists)
				//
				$this->doLog("Loading TAIL template for ".$this->app["name"]."/".$this->app["section"],$this::L_INFO);
				if (stream_resolve_include_path($sec_tpl_path."tail.htm")) {
					$this->app["TBS"]->LoadTemplate($sec_tpl_path."tail.htm","+");
				}
				else {
					$this->doLog("TAIL template not found for ".$this->app["name"]."/".$this->app["section"],$this::L_INFO);
				}

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
						$this->doLog("Merging Tail Block $block_name");
						$this->app["TBS"]->MergeBlock("$block_name",$block_data);
					}
				}

				if(array_key_exists("tail_fields", $this->app)) {
					foreach($this->app["tail_fields"] as $field_name => $field_data) {
						$this->doLog("Merging Tail Field $field_name");
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

	}

}
?>