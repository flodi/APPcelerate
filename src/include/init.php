<?
session_start();

//
// BASE path
//
$base_path="/var/www/appcelerator";

//
// Define includes path
//
$include_path=$base_path."/include";
$vendor_path=$base_path."/vendor";
$views_path=$base_path."/views";
if (set_include_path(get_include_path() . PATH_SEPARATOR . $include_path . PATH_SEPARATOR . $vendor_path. PATH_SEPARATOR . $views_path)==false) {
	die("Cannot set include path.");
}

//
// Include Composer libraries
//
include_once("autoload.php");
include_once("tinybutstrong/tinybutstrong/plugins/tbs_plugin_html.php");
use Endroid\QrCode\QrCode;

//
// Init app values array
//
global $app;
$app["base_path"]=$base_path;
$app["templates_path"]=$base_path."/templates/";
$dotenv = new Dotenv\Dotenv($app["base_path"], 'app.config');
$dotenv->load();

$app["base_url"]=getenv('BASE_URL');

$app["skipui"]=false;

$app["apps"]=explode("|",getenv('APPS'));

# Define Apps
$app["base_app"]=getenv('BASE_APP');
$app["default_app"]=getenv('DEFAULT_APP');
$app["home"]["app"]="/app/";

# Default locale
$app["locale"]=getenv('DEFAULT_LANGUAGE');

# Define Additional templates
foreach ($app["apps"] as $app_name) {
	$add_tpl=getenv('ADD_TPL_'.$app_name);
	if ($add_tpl==="Y") {
		$app["addtemplates"][$app_name]=$app_name."_additional_template.htm";
	}
}

//
// Init Log
//
use Monolog\Logger;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\RavenHandler;

Raven_Autoloader::register();

$app["main_logger"]=new Logger('main');

$dateFormat = "d-m-Y G:i";
$output = "%datetime% ; %level_name% ; %message% ; %context%\n";
$formatter = new LineFormatter($output, $dateFormat);

$mainstream=new StreamHandler($app["base_path"]."/logs/main.log", Logger::DEBUG);
$mainstream->setFormatter($formatter);

$app["main_logger"]->pushHandler($mainstream);

# Apps Log
foreach ($app["apps"] as $app_name) {
	$app[$app_name."_ravenc"]=new Raven_Client('https://f2b7f556cda845cb83c8c7faa8de9134:9b0f361e29a74b89971d7f2486dff827@sentry.io/97912');
	$app[$app_name."_ravenh"]= new RavenHandler($app[$app_name."_ravenc"]);
	$app[$app_name."_ravenh"]->setFormatter(new LineFormatter("%message% %context% %extra%\n"));
	$app[$app_name."_logger"]=new Logger('app');
	$app[$app_name."_log_stream"]=new StreamHandler($app["base_path"]."/logs/".$app_name.".log", Logger::DEBUG);
	$app[$app_name."_log_stream"]->setFormatter($formatter);
	$app[$app_name."_logger"]->pushHandler($app[$app_name."_log_stream"]);
	$app[$app_name."_logger"]->pushHandler($app[$app_name."_ravenh"]);
}

define ("L_DEBUG",100);
define ("L_INFO",200);
define ("L_NOTICE",250);
define ("L_WARNING",300);
define ("L_ERROR",400);
define ("L_CRITICAL",500);
define ("L_ALERT",550);
define ("L_EMERCENCY",600);

//
// Init Php-Console
//
$app["pcc"]=PhpConsole\Connector::getInstance();
$app["pcc"]->setSourcesBasePath($app["base_path"]);
$app["pch"]=PhpConsole\Handler::getInstance();
$app["pch"]->setHandleErrors(true);
$app["pch"]->setHandleExceptions(true);
$app["pch"]->setCallOldHandlers(true);
$app["pch"]->start();

//
// DB Connection Init
//
$db_address=getenv('DB_ADDRESS');
$db_user=getenv('DB_USER');
$db_password=getenv('DB_PASSWORD');

foreach ($app["apps"] as $app_name) {
	$db_name=getenv('DB_NAME_'.$app_name);
	$app["db_".$app_name] = new mysqli($db_address, $db_user, $db_password, $db_name);
	if ($app["db_".$app_name]->connect_error) {
	    die("Failed to connect to MySQL: doing new mysqli($db_address, $db_user, $db_password, $db_name) (".$app["db_".$app_name]->connect_errno.") ".$app["db_".$app_name]->connect_error);
	}
	$app["db_".$app_name]->set_charset("utf8");
}


//
// Tools Functions
//
function sqlError($recordset,$query) {
	global $app;
	
	if (!$recordset) {
		doLog("Failed SQL query - Query => $query, Error => ".$app["db_".$app["name"]]->error);
		die("Database error, please contact support\n");
	}
}

function destroySession() {
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

function fetchAll ($recordset) {
	$data = [];
	while ($row = $recordset->fetch_array(MYSQLI_NUM)) {
    	$data[] = $row;
	}
	return $data;
}
function fetchAllAssoc ($recordset) {
	$data = [];
	while ($row = $recordset->fetch_array(MYSQLI_ASSOC)) {
    	$data[] = $row;
	}
	return $data;
}

function getString($token) {
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

function getInclude($type,$params) {
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

function errRoute() {
	global $app;

	doLog("Route Error, restarting ".$_SERVER["REQUEST_URI"]);
	
	header("Location: ".$app["base_url"]."/");

}

function stringForHTML($field,&$value) {
	global $app;

	$value=utf8_encode($value);
}

function doLog($msg,$level=L_DEBUG) {
	global $app;

	if (array_key_exists("name", $app) and $app["name"]!=="init") {
		$app_name=$app["name"];
	}
	else {
		$app_name="main";
	}
	
	if (array_key_exists("section",$app)) {
		$context=array($app["section"]);
	}
	else {
		$context=array("main");
	}

	switch($level) {
		default:
			$app[$app_name."_logger"]->addRecord($level,$msg,$context);
	}
	
}

//
// Specific Functions
//

function sendEmail($body,$subject,$to,$cc) {
	global $app;	
	
	$to=array_map('trim',explode(";",$to));

	$m = new SimpleEmailServiceMessage();
	$m->setFrom('info@momo.it');
	$m->addReplyTo('info@momo.it');
	$m->setReturnPath('info@momo.it');
	$m->addTo($to);
	//$m->addBCC("flodi@e-scientia.eu");
	if (!empty($cc)) {
		$m->addCC($cc);
	}
	$m->setSubject($subject);
	//echo "<pre>"; print_r($m); "echo </pre>";die();
	$m->setMessageFromString(strip_tags($body), $body);
	$ses = new SimpleEmailService('AKIAIRAT2OMHJPFTQ3BA', 'NHlrCErSQCL3MJzytrs/IvdcVthi0J2royCOAFc4','email.eu-west-1.amazonaws.com',true);
	$result=$ses->sendEmail($m);
	$ses_messageid=$result["MessageId"];
	$ses_requestid=$result["RequestId"];

	$sql="insert into ses_log (messageid, requestid, object) value ('$ses_messageid','$ses_requestid','".json_encode($m)."')";
	$rs=$app["dbdw"]->query($sql);
	sqlError($rs,$sql);
}

function genQRCode($code) {
	$qrCode = new QrCode();
	$qrCode
		->setText($code)
		->setSize(300)
		->setPadding(10)
		->setErrorCorrection('high')
		->setForegroundColor(array('r' => 0, 'g' => 0, 'b' => 0, 'a' => 0))
		->setBackgroundColor(array('r' => 255, 'g' => 255, 'b' => 255, 'a' => 0))
	;
	
	return($qrCode->get());
}

function getEventi($uid) {
	global $db;
	
	$sql="select id,nome from evento where id in (select id_evento from accessi_evento where id_accesso=$uid)";
	$rs=$app["db"]->query($sql);
	sqlError($rs,$sql);
	
	return(fetchAllAssoc($rs));
}

function getAttivita($id_evento) {
	global $db;
	
	if ($id_evento==-1) {
		return(array());
	}
	else {
		$sql="select id,nome from attivita where id in (select id_attivita from attivita_evento where id_evento=$id_evento)";
		$rs=$app["db"]->query($sql);
		sqlError($rs,$sql);
		
		return(fetchAllAssoc($rs));
	}
}

function getOspiti($id_evento,$id_attivita) {
	global $db;
	
	if ($id_evento==-1) {
		return(array());
	}
	else {
		if ($id_attivita==-1) {
			$sql="select id,nome from ospite where id in (select id_ospite from ospiti_evento where id_evento=$id_evento)";
		}
		else {
			$sql="select id,nome from ospite where id in (select id_ospite_evento from ospite_attivita_evento where id_evento=$id_evento and id_attivita=$id_attivita)";
		}
		$rs=$app["db"]->query($sql);
		sqlError($rs,$sql);
		
		return(fetchAllAssoc($rs));
	}
}

function getLuogoName($field,&$value) {
	global $app;
	
	$id=$value;
	
	$sql="select luogo from luoghi where id=$id";
	$rs=$app["db"]->query($sql);
	sqlError($rs,$sql);
	$value=utf8_encode($rs->fetch_row()[0]);
}

function formatDate($field,&$value) {
	$t=strtotime($value);
	$value = date("d/m/y", $t);
}

function trunc250($field,&$value) {
	$value=substr($value, 0, 250)."...";
}

function addMerge($type,$field,$var) {
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

function kickOut() {
	header("Location:	/".$app[name]."/login/");
	die();
}

function ATTIVITA_GetData($field,&$value) {
	global $app;
	$id=$value;
	$inizio=false;
	$fine=false;
	
	$sql="select data_inizio, ora_inizio, data_fine, ora_fine from attivita where id=$id";
	$rs=$app["db"]->query($sql);
	sqlError($rs,$sql);
	$dati=$rs->fetch_row();
	
	if (!empty($dati[0])) {
		$data=date("d F Y",strtotime($dati[0]));
		$inizio=true;
	}
	else {
		$data="";
	}
	
	if (!empty($dati[1])) {
		if ($inizio) {
			$data.=" ".date("H:i",strtotime($dati[1]));
		}
		else {
			$data.=$dati[1];
		}
	}

	if (!empty($dati[2])) {
		if ($inizio) {
			$data.=" - ".date("d F Y",strtotime($dati[2]));
			$fine=true;
		}
		else {
			$data.=date("d F Y",strtotime($dati[2]));
		}
	}
	
	if (!empty($dati[3])) {
		if ($fine) {
			$data.=" ".date("H:i",strtotime($dati[3]));
		}
		else if ($inizio) {
			$data.=" - ".date("H:i",strtotime($dati[3]));
		}
		else {
			$data.=date("H:i",strtotime($dati[3]));
		}
	}
	
	$value=$data;
	
}

function ATTIVITA_GetLuogo($field,&$value) {
	global $app;
	$id=$value;

	$sql="select id_luogo_inizio, id_luogo_fine from attivita where id=$id";
	$rs=$app["db"]->query($sql);
	sqlError($rs,$sql);
	$dati=$rs->fetch_row();
	
	if (empty($dati[1]) or $dati[0]==$dati[1]) {
		$due=false;
	}
	else {
		$due=true;
	}
	
	$sql="select luogo from luoghi where id=".$dati[0];
	$rs=$app["db"]->query($sql);
	sqlError($rs,$sql);
	$luogo=$rs->fetch_row()[0];
	
	if ($due) {
		$sql="select luogo from luoghi where id=".$dati[1];
		$rs=$app["db"]->query($sql);
		sqlError($rs,$sql);
		$luogo.=" - ".$rs->fetch_row()[0];
	}
	
	$value=$luogo;
}
?>
