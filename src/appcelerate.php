<?
/*
 * (c) Fabrizio Lodi <flodi@e-scientia.eu>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

class APPcelerate {
	
	public $app;
	
    public function __construct() {
	    
		register_shutdown_function('logCrash');

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
		$formatter = new LineFormatter($output, $dateFormat);
		
		$mainstream=new StreamHandler($this->app["base_path"]."/logs/appcelerate.log", Logger::DEBUG);
		$mainstream->setFormatter($formatter);
		
		$this->app["main_logger"]->pushHandler($mainstream);
		
		# Apps Log
		foreach ($this->app["apps"] as $app_name) {
			$this->app[$app_name."_ravenc"]=new Raven_Client('https://f2b7f556cda845cb83c8c7faa8de9134:9b0f361e29a74b89971d7f2486dff827@sentry.io/97912');
			$this->app[$app_name."_ravenh"]= new RavenHandler($this->app[$app_name."_ravenc"]);
			$this->app[$app_name."_ravenh"]->setFormatter(new LineFormatter("%message% %context% %extra%\n"));
			$this->app[$app_name."_logger"]=new Logger($app_name);
			$this->app[$app_name."_log_stream"]=new StreamHandler($this->app["base_path"]."/logs/".$app_name.".log", Logger::DEBUG);
			$this->app[$app_name."_log_stream"]->setFormatter($formatter);
			$this->app[$app_name."_logger"]->pushHandler($this->app[$app_name."_log_stream"]);
			$this->app[$app_name."_logger"]->pushHandler($this->app[$app_name."_ravenh"]);
		}
		
		define ("L_DEBUG",100);
		define ("L_INFO",200);
		define ("L_NOTICE",250);
		define ("L_WARNING",300);
		define ("L_ERROR",400);
		define ("L_CRITICAL",500);
		define ("L_ALERT",550);
		define ("L_EMERCENCY",600);

	}

	private function logCrash() {
		$e=error_get_last();
	
		if ($e['type']) {
			$msg=sprintf("Type %u File %s Line %u Message %s",$e["type"],$e["file"],$e["line"],$e["message"]);
			doLog($msg);
		}
	}

}
