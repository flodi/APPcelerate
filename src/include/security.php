<?
global $app;

if (!empty($_SESSION[$app["name"]."_ap_uid"])) {
	$app['uid']=$_SESSION[$app["name"]."_ap_uid"];
	$app['uname']=$_SESSION[$app["name"]."_ap_uname"];
	if(array_key_exists($app["name"]."_ap_locale", $_SESSION)) {
		$app['locale']=$_SESSION[$app["name"]."_ap_locale"];
	}
}
else {
	if (!empty($_REQUEST["login"]) and !empty($_REQUEST["password"])) {
		$sql="select id from users where app='". $app["name"] ."' and login='" . $_REQUEST["login"] . "' and pwd='" . $_REQUEST["password"] . "'";
		$rs=$app["db_".$app["name"]]->query($sql);
		sqlError($rs,$sql);
		switch ($rs->num_rows) {
			case 1:
				$row=$rs->fetch_row();
				$app['uid']=$row[0];
				$app['uname']=$_REQUEST["login"];
				$_SESSION[$app["name"]."_ap_uid"]=$app['uid'];
				$_SESSION[$app["name"]."_ap_uname"]=$app['uname'];
				$sql="select locale from languages where id=(select id_language from users where id=".$app["uid"].")";
				$rs1=$app["db_".$app["name"]]->query($sql);
				sqlError($rs1,$sql);
				if ($rs1->num_rows!=0) {
					$app["locale"]=$rs1->fetch_row()[0];
					$_SESSION[$app["name"]."_ap_locale"]=$app['locale'];
				}
				header("Location: ".$app["base_url"]."/".$app["name"]."/");
				break;
			case 0:
				header("Location: ".$app["base_url"]."/".$app["name"]."/login/?wrong");
				break;
			default:
				header("Location: ".$app["base_url"]."/".$app["name"]."/login/?multi");
				break;
		}
	}
	else {
		unset($app['uid']);
		unset($app['uname']);
		if (!(strpos($_SERVER['REQUEST_URI'],"/login/")) and !(strpos($_SERVER['REQUEST_URI'],"/logout/"))) {
			header("Location: " . $app["base_url"] . "/".$app["name"] . "/login/?nolo");
		}
	}
}
?>