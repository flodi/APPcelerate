<? /** @noinspection ALL */

/*
 * (c) Fabrizio Lodi <flodi@e-scientia.eu>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

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
		"C" => "Counterpart",
		"T" => "Third Party"
	);

	//
	// $fw - Istanza di framework attiva
	// $name - Nome dell'applicazione sotto la quale gestire i processi
	//
    public function __construct($fw,$name) {
    	$this->fw=$fw;
    	$this->app_name=$name;
    	$this->db=$this->fw->app["db_".$name];

    	$this->fw->setBPME(true);

		$this->logger=new Monolog\Logger('bpme');

		$dateFormat = "d-m-Y G:i";
		$output = "%datetime% ; %level_name% ; %message% ; %context%\n";
		$formatter = new Monolog\Formatter\LineFormatter($output, $dateFormat);

		switch($this->fw->app["loglevel"]) {
			case "info":
				$ll=Monolog\Logger::INFO;
				break;
			default:
				$ll=Monolog\Logger::DEBUG;
		}

		$mainstream=new Monolog\Handler\StreamHandler($this->fw->app["base_path"]."/logs/bpme.log", $ll);
		$mainstream->setFormatter($formatter);

		$this->logger->pushHandler($mainstream);

		$this->fw->app["TBS"]->ObjectRef['bpme_obj'] = $this;
		$this->fw->app["TBS"]->MergeField('bpme', '~bpme_obj.bpmeTBS', true);

	}

	private function getAlerts() {
		$sql="select * from alerts where alert_done=false order by severity desc";
		$rs=$this->db->query($sql);
		try {
			$this->rsCheck($rs);
		}
		catch (Exception $e) {
			$msg=$e->getMessage();
			$this->doLog("Error getting alerts | $sql | $msg",APPcelerate::L_ERROR);
			throw new Exception("Query Error", 0);
		}
		$alerts=array();
		$i=0;
		while ($r=$rs->fetch_array(MYSQLI_ASSOC)) {
			$alerts[$i]["id"]=$r["id"];
			$alerts[$i]["severity"]=json_decode($r["severity"],true);
			$alerts[$i]["data"]=$r["specific_data"];
			$alerts[$i]["id_process_instance"]=$r["id_process_instance"];
			$alerts[$i]["id_activity_instance"]=$r["id_activity_instance"];
			if (array_key_exists("id_process_instance", $r) and !empty($r["id_process_instance"])) {
				$alerts[$i]["processo"]=$this->getProcessNameFromProcessInstance($r["id_process_instance"]);
			}
			else {
				$alerts[$i]["processo"]="";
			}
			if (array_key_exists("id_activity_instance", $r) and !empty($r["id_activity_instance"])) {
				$alerts[$i]["activity"]=$this->getActivityNameFromActivityInstance($r["id_activity_instance"]);
			}
			else {
				$alerts[$i]["activity"]="";
			}
			$i++;
		}
		return($alerts);
	}

	private function getLastActivities() {
		$uid=$this->getCurrentUID();
		$sql="select * from activity_instances where date_completed is null and (id_actor_assigned is null or id_actor_assigned=$uid or id_actor_created=$uid) order by date_created desc limit 10";
		$rs=$this->db->query($sql);
		try {
			$this->rsCheck($rs);
		}
		catch (Exception $e) {
			$msg=$e->getMessage();
			$this->doLog("Error getting alerts | $sql | $msg",APPcelerate::L_ERROR);
			throw new Exception("Query Error", 0);
		}
		$i=0;
		$last=array();
		while ($r=$rs->fetch_array(MYSQLI_ASSOC)) {
			$last[$i]["id"]=$r["id"];
			$last[$i]["id_process_instance"]=$r["id_process_instance"];
			$last[$i]["process"]=$this->getProcessNameFromProcessInstance($r["id_process_instance"]);
			$last[$i]["activity"]=$this->getActivityNameFromActivityInstance($r["id"]);
			$i++;
		}
		return($last);
	}

	// Ritorna l'id dell'istanza dell'ultima attività eseguita
	private function startProcess($code,$start,$initial_data,$ui) {

		$this->doLog("Requested with code $code and start $start and ui $ui",$initial_data,APPcelerate::L_DEBUG);

		$uid=$this->getCurrentUID();

		$sql="select * from processes where code='$code'";
		$rs=$this->db->query($sql);
		try {
			$this->rsCheck($rs);
		}
		catch (Exception $e) {
			$msg=$e->getMessage();
			$this->doLog("F: (P) startProcess | $sql | $msg",APPcelerate::L_ERROR);
			throw new Exception("Query Error", 0);
		}
		if ($rs->num_rows===0) {
			throw new Exception("Process $code not found", 0);
		}
		$r=$rs->fetch_array(MYSQLI_ASSOC);
		$id_process=$r["id"];

		$serialized_data=$this->db->real_escape_string(json_encode($initial_data));

		$sql=sprintf("insert into process_instances (id_process,id_actor_created,id_user_assigned,status,data) values (%d,%d,%d,'R','%s')",$id_process,$uid,$uid,$serialized_data);
		$rs=$this->db->query($sql);
		try {
			$this->rsCheck($rs);
		}
		catch (Exception $e) {
			$msg=$e->getMessage();
			$this->doLog("F: (P) startProcess | $sql | $msg",APPcelerate::L_ERROR);
			throw new Exception("Query Error", 0);
		}
		$id_process_instance=$this->db->insert_id;

		try {
			$id_activity=$this->getActivityID($code,$start);
		}
		catch (Exception $e){
			$msg=$e->getMessage();
			$this->doLog("Cannot create process ( $msg )",APPcelerate::L_ERROR);
			throw new Exception("Cannot create process ($msg)", 0);
		}

		$id_activity_instance=$this->createActivityInstance(0,$id_process_instance,$id_activity,$ui);

		$this->dispatchActivity($id_activity_instance,$ui);

		$this->doLog("Returning process instance $id_process_instance and activity instance $id_activity_instance",APPcelerate::L_DEBUG);

		return(array($id_process_instance,$id_activity_instance));
	}

	public function setProcessInstanceData($id_process_instance,$data) {
		$this->doLog("Requested with process instance $id_process_instance and data ".print_r($data,true),APPcelerate::L_DEBUG);

		if (!is_numeric($id_process_instance) and !is_int($id_process_instance)) {
			throw new Exception("Process instance id $id_process_instance not valid", 0);
		}

		if (!is_array($data)) {
			throw new Exception("Process data ".print_r($data,true)." not valid", 0);
		}

		$sql="select data from process_instances where id=$id_process_instance";
		$rs=$this->db->query($sql);
		try {
			$this->rsCheck($rs);
		}
		catch (Exception $e) {
			$msg=$e->getMessage();
			$this->doLog("$sql ( $msg )",APPcelerate::L_ERROR);
			throw new Exception("Query Error", 0);
		}
		$d=$rs->fetch_array(MYSQLI_NUM)[0];
		if (empty($d)) {
			$d=array();
		}
		else {
			$d=json_decode($d,true);
		}

		foreach ($data as $key => $val) {
			if (is_null($data[$key])) {
				unset($data[$key]);
				if (array_key_exists($key, $d)) {
					unset($d[$key]);
				}
			}
		}

		$new_d=array_merge($d,$data);

		$sql="update process_instances set data='".addslashes(json_encode($new_d))."' where id=$id_process_instance";
		$rs=$this->db->query($sql);
		try {
			$this->rsCheck($rs);
		}
		catch (Exception $e) {
			$msg=$e->getMessage();
			$this->doLog("$sql ( $msg )",APPcelerate::L_ERROR);
			throw new Exception("Query Error", 0);
		}

		return true;
	}

	private function getProcessInstanceNote($id_process_instance) {
		$this->doLog("Requested with process instance $id_process_instance",APPcelerate::L_DEBUG);

		if (!is_numeric($id_process_instance) and !is_int($id_process_instance)) {
			throw new Exception("Process instance id $id_process_instance not valid", 0);
		}
		$sql="select note from process_instances where id=$id_process_instance";
		$rs=$this->db->query($sql);
		try {
			$this->rsCheck($rs);
		}
		catch (Exception $e) {
			$msg=$e->getMessage();
			$this->doLog("$sql ( $msg )",APPcelerate::L_ERROR);
			throw new Exception("Query Error", 0);
		}
		return($rs->fetch_array(MYSQLI_NUM)[0]);
	}

	private function getProcessInstanceData($id_process_instance,$all=true,$type) {
		$this->doLog("Requested with process instance $id_process_instance and all $all and type $type",APPcelerate::L_DEBUG);

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
			$this->doLog("$sql ( $msg )",APPcelerate::L_ERROR);
			throw new Exception("Query Error", 0);
		}
		$d=json_decode($rs->fetch_array(MYSQLI_NUM)[0],true);

		$i=0;
		$data=array();
		foreach($d as $key => $value) {
			if (!is_string($key)) {
				die("ERRORE DI SISTEMA, CONTATTARE IL SUPPORTO<br>bpme,key,270,notstring<br>".var_dump($d));
			}
			if (substr($key, 0, 1) !== "_" or $all) {
				if (substr($value, 0, 1) === "@") {
					$value=json_encode($this->getProcessDataFieldFromDB($value));
				}
				switch($type) {
					case "list":
						$data[$i]["key"]=$key;
						$data[$i++]["value"]=$value;
						break;

					case "array":
					case "block":
						$data[$key]=$value;
						break;
				}
			}
		}
		if ($type==="block") {
			$data=array($data);
		}
		return($data);
	}

	private function getProcessDataFieldFromDB($token) {

		list($table,$field_list,$where_field_list,$where_value_list)=explode(":", $token);

		$table=substr($table,1);

		if (empty($field_list)) {
			throw new Exception("Field list is mandatory", 0);
		}

		$sql="select $field_list from $table";

		if (!empty($where_field_list) and !empty($where_value_list)) {
			$where_fields=explode(",",$where_field_list);
			$where_values=explode(",",$where_value_list);

			if(count($where_fields)!=count($where_values)) {
				throw new Exception("Where fields and values must have the same length", 0);
			}

			if(count($where_fields)!=0) {
				$sql.=" where";
				for($i=0;$i<count($where_fields);$i++) {
					if (!is_numeric($where_values[$i])) {
						$where_value_list[$i]="'".$this->db->real_escape_string($where_values[$i])."'";
					}
					$sql.=" ".$where_fields[$i]."=".$where_values[$i];
				}
			}

		}

		$rs=$this->db->query($sql);
		try {
			$this->rsCheck($rs);
		}
		catch (Exception $e) {
			$msg=$e->getMessage();
			$this->doLog("$sql ( $msg )",APPcelerate::L_ERROR);
			throw new Exception("Query Error", 0);
		}
		$value=$this->fw->fetchAllAssoc($rs);

		return($value);
	}

	private function getActorType($id_actor) {
		$this->doLog("Requested with actor id $id_actor",APPcelerate::L_DEBUG);
		if (!is_numeric($id_actor) and !is_int($id_actor)) {
			throw new Exception("Actor id $id_actor not valid", 0);
		}
		$sql="select id from users where id=$id_actor";
		$rs=$this->db->query($sql);
		try {
			$this->rsCheck($rs);
		}
		catch (Exception $e) {
			$msg=$e->getMessage();
			$this->doLog("$sql ( $msg )",APPcelerate::L_ERROR);
			throw new Exception("Query Error", 0);
		}
		if($rs->num_rows==0) {
			$sql="select id from partecipanti where id=$id_actor";
			$rs=$this->db->query($sql);
			try {
				$this->rsCheck($rs);
			}
			catch (Exception $e) {
				$msg=$e->getMessage();
				$this->doLog("$sql ( $msg )",APPcelerate::L_ERROR);
				throw new Exception("Query Error", 0);
			}
			if ($rs->num_rows==0) {
				$type="N";
			}
			else {
				$type="P";
			}
		}
		else {
			$type="U";
		}
		return($type);
	}

	private function getProcessIDFromProcessInstance($id_process_instance) {
		$this->doLog("Requested with process instance $id_process_instance",APPcelerate::L_DEBUG);
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
			$this->doLog("$sql ( $msg )",APPcelerate::L_ERROR);
			throw new Exception("Query Error", 0);
		}
		return($rs->fetch_array(MYSQLI_NUM)[0]);
	}

	private function getProcessCodeFromProcessInstance($id_process_instance) {
		$this->doLog("Requested with process instance $id_process_instance",APPcelerate::L_DEBUG);
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
			$this->doLog("$sql ( $msg )",APPcelerate::L_DEBUG);
			throw new Exception("Query Error", 0);
		}
		return($rs->fetch_array(MYSQLI_NUM)[0]);
	}

	private function getProcessCodeFromActivityID($id_activity) {
		$this->doLog("Requested with actvity id $id_activity",APPcelerate::L_DEBUG);
		if (!is_numeric($id_activity) and !is_int($id_activity)) {
			throw new Exception("Activity id $id_activity not valid", 0);
		}
		$sql="select processes.code from processes join activities on processes.id=activities.id_process where activities.id=$id_activity";
		$rs=$this->db->query($sql);
		try {
			$this->rsCheck($rs);
		}
		catch (Exception $e) {
			$msg=$e->getMessage();
			$this->doLog("$sql ( $msg )",APPcelerate::L_ERROR);
			throw new Exception("Query Error", 0);
		}
		return($rs->fetch_array(MYSQLI_NUM)[0]);
	}

	private function getProcessNameFromProcessInstance($id_process_instance) {
		$this->doLog("Requested with process instance $id_process_instance",APPcelerate::L_DEBUG);

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
			$this->doLog("$sql ( $msg )",APPcelerate::L_ERROR);
			throw new Exception("Query Error", 0);
		}
		return($rs->fetch_array(MYSQLI_NUM)[0]);
	}

	private function getProcessInstanceOwner($id_process_instance) {
		$this->doLog("Requested with process instance $id_process_instance",APPcelerate::L_DEBUG);

		if (!is_numeric($id_process_instance) and !is_int($id_process_instance)) {
			throw new Exception("Process instance id $id_process_instance not valid", 0);
		}

		$sql="select id_actor_created from process_instances where id=$id_process_instance";
		$rs=$this->db->query($sql);
		try {
			$this->rsCheck($rs);
		}
		catch (Exception $e) {
			$msg=$e->getMessage();
			$this->doLog("$sql ( $msg )",APPcelerate::L_ERROR);
			throw new Exception("Query Error", 0);
		}
		return($rs->fetch_array(MYSQLI_NUM)[0]);
	}

	private function getProcessInstanceIDFromActivityInstanceID($id_activity_instance) {
		$this->doLog("Requested with activity instance $id_activity_instance",APPcelerate::L_DEBUG);
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
			$this->doLog("$sql ( $msg )",APPcelerate::L_ERROR);
			throw new Exception("Query Error", 0);
		}
		return($rs->fetch_array(MYSQLI_NUM)[0]);
	}

	private function getActivityThirdParty($id_activity) {
		$this->doLog("Requested with activity $id_activity",APPcelerate::L_DEBUG);
		if (!is_numeric($id_activity) and !is_int($id_activity)) {
			throw new Exception("Activity id $id_activity not valid", 0);
		}
		$sql="select id_tp from activities where id=$id_activity";
		$rs=$this->db->query($sql);
		try {
			$this->rsCheck($rs);
		}
		catch (Exception $e) {
			$msg=$e->getMessage();
			$this->doLog("$sql ( $msg )",APPcelerate::L_ERROR);
			throw new Exception("Query Error", 0);
		}
		return($rs->fetch_array(MYSQLI_NUM)[0]);
	}

	private function getActivityCodeFromActivityInstance($id_activity_instance) {
		$this->doLog("Requested with activity instance $id_activity_instance",APPcelerate::L_DEBUG);
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
			$this->doLog("$sql ( $msg )",APPcelerate::L_ERROR);
			throw new Exception("Query Error", 0);
		}
		return($rs->fetch_array(MYSQLI_NUM)[0]);
	}

	private function getProcessInstanceCounterpart($id_process_instance) {
		$this->doLog("Requested with process instance $id_process_instance",APPcelerate::L_DEBUG);
		if (!is_numeric($id_process_instance) and !is_int($id_process_instance)) {
			throw new Exception("Process instance id $id_process_instance not valid", 0);
		}

		$sql="select id_counterpart from process_instances where id=$id_process_instance";
		$rs=$this->db->query($sql);
		try {
			$this->rsCheck($rs);
		}
		catch (Exception $e) {
			$msg=$e->getMessage();
			$this->doLog("$sql ( $msg )",APPcelerate::L_ERROR);
			throw new Exception("Query Error", 0);
		}
		return($rs->fetch_array(MYSQLI_NUM)[0]);
	}

	private function setProcessInstanceCounterpart($id_process_instance,$id_actor) {
		$this->doLog("Requested with process instance $id_process_instance and actor $id_actor",APPcelerate::L_DEBUG);
		if (!is_numeric($id_process_instance) and !is_int($id_process_instance)) {
			throw new Exception("Process instance id $id_process_instance not valid", 0);
		}
		if (!is_numeric($id_actor) and !is_int($id_actor)) {
			throw new Exception("Actor id $id_actor not valid", 0);
		}

		$sql="update process_instances set id_counterpart=$id_actor where id=$id_process_instance";
		$rs=$this->db->query($sql);
		try {
			$this->rsCheck($rs);
		}
		catch (Exception $e) {
			$msg=$e->getMessage();
			$this->doLog("$sql ( $msg )",APPcelerate::L_ERROR);
			throw new Exception("Query Error", 0);
		}
	}

	private function getActivityNameFromActivityInstance($id_activity_instance) {
		$this->doLog("Requested with activity instance $id_activity_instance",APPcelerate::L_DEBUG);
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
			$this->doLog("$sql ( $msg )",APPcelerate::L_ERROR);
			throw new Exception("Query Error", 0);
		}
		return($rs->fetch_array(MYSQLI_NUM)[0]);
	}

	private function getActivityInstanceContext($id_activity_instance) {
		$this->doLog("Requested with activity instance $id_activity_instance",APPcelerate::L_DEBUG);
		if (!is_numeric($id_activity_instance) and !is_int($id_activity_instance)) {
			throw new Exception("Activity instance id $id_activity_instance not valid", 0);
		}
		$sql="
			select
				activities.name as name,
				activities.code as code,
				activities.description as description,
				activities.activity_type as type,
				processes.name as process_name,
				processes.code as process_code,
				processes.description as process_descrption
			from
				activities
					join processes on activities.id_process=processes.id
					join activity_instances on activities.id=activity_instances.id_activity
			where
				activity_instances.id=$id_activity_instance
		";
		$rs=$this->db->query($sql);
		try {
			$this->rsCheck($rs);
		}
		catch (Exception $e) {
			$msg=$e->getMessage();
			$this->doLog("$sql ( $msg )",APPcelerate::L_ERROR);
			throw new Exception("Query Error", 0);
		}
		$context=$this->fw->fetchAllAssoc($rs);

		return($context);
	}

	private function getActionInstanceIDFromActivityInstanceTo($id_activity_instance_to) {
		$this->doLog("Requested with activity instance $id_activity_instance_to",APPcelerate::L_DEBUG);
		if (!is_numeric($id_activity_instance_to) and !is_int($id_activity_instance_to)) {
			throw new Exception("Activity instance id $id_activity_instance_to not valid", 0);
		}

		$id_process_instance=$this->getProcessInstanceIDFromActivityInstanceID($id_activity_instance_to);

		$sql="select id from action_instances where id_process_instance=$id_process_instance and id_activity_instance_to=$id_activity_instance_to order by id asc limit 1";

		$rs=$this->db->query($sql);
		try {
			$this->rsCheck($rs);
		}
		catch (Exception $e) {
			$msg=$e->getMessage();
			$this->doLog("$sql ( $msg )",APPcelerate::L_ERROR);
			throw new Exception("Query Error", 0);
		}
		if ($rs->num_rows==0) {
			return(-1);
		}
		return($rs->fetch_array(MYSQLI_NUM)[0]);

	}

	private function getActionNameFromActionInstance($id_action_instance) {
		$this->doLog("Requested with action instance $id_action_instance",APPcelerate::L_DEBUG);
		if (!is_numeric($id_action_instance) and !is_int($id_action_instance)) {
			throw new Exception("Action instance id $id_action_instance not valid", 0);
		}
		$sql="select actions.name from actions join action_instances on actions.id=activity_instances.id_activity where action_instances.id=$id_action_instance";
		$rs=$this->db->query($sql);
		try {
			$this->rsCheck($rs);
		}
		catch (Exception $e) {
			$msg=$e->getMessage();
			$this->doLog("$sql ( $msg )",APPcelerate::L_ERROR);
			throw new Exception("Query Error", 0);
		}
		return($rs->fetch_array(MYSQLI_NUM)[0]);
	}

	private function getCounterpartCodeFromActivityInstance($id_activity_instance) {
		$this->doLog("Requested with activity instance  $id_activity_instance",APPcelerate::L_DEBUG);

		if (!is_numeric($id_activity_instance) and !is_int($id_activity_instance)) {
			throw new Exception("Activity instance id $id_process_instance not valid", 0);
		}

		$sql="select id_actor_assigned from activity_instances where id=$id_activity_instance";
		$rs=$this->db->query($sql);
		try {
			$this->rsCheck($rs);
		}
		catch (Exception $e) {
			$msg=$e->getMessage();
			$this->doLog("$sql ( $msg )",APPcelerate::L_ERROR);
			throw new Exception("Query Error", 0);
		}
		$idaa=$rs->fetch_array(MYSQLI_NUM)[0];

		$sql="select code from ospiti where id=(select id_ospite from partecipanti where id=$idaa)";
		$rs=$this->db->query($sql);
		try {
			$this->rsCheck($rs);
		}
		catch (Exception $e) {
			$msg=$e->getMessage();
			$this->doLog("$sql ( $msg )",APPcelerate::L_ERROR);
			throw new Exception("Query Error", 0);
		}
		$code=$rs->fetch_array(MYSQLI_NUM)[0];

		return($code);
	}

	private function assignActivity($id_activity_instance,$id_actor) {
		$this->doLog("Requested with activity instance  $id_activity_instance and actor id $id_actor",APPcelerate::L_DEBUG);

		if (!is_numeric($id_activity_instance) and !is_int($id_activity_instance)) {
			throw new Exception("Activity instance id $id_process_instance not valid", 0);
		}
		if (!is_numeric($id_actor) and !is_int($id_actor)) {
			throw new Exception("Actor id $id_actor not valid", 0);
		}

		$sql="update activity_instances set id_actor_assigned=$id_actor where id=$id_activity_instance";
		$rs=$this->db->query($sql);
		try {
			$this->rsCheck($rs);
		}
		catch (Exception $e) {
			$msg=$e->getMessage();
			$this->doLog("$sql ( $msg )",APPcelerate::L_ERROR);
			throw new Exception("Query Error", 0);
		}
	}

	private function createActivityInstance($id_activity_instance_prec,$id_process_instance,$id_activity,$ui=false) {
		global $id_activity_instance_opening;

		$this->doLog("Requested with activity instance prec id $id_activity_instance_prec and process instance id $id_process_instance and activity id $id_activity and ui $ui",APPcelerate::L_DEBUG);

		if (!is_numeric($id_activity_instance_prec) and !is_int($id_activity_instance_prec)) {
			throw new Exception("Activity instance prec id $id_activity_instance_prec not valid", 0);
		}
		if (!is_numeric($id_process_instance) and !is_int($id_process_instance)) {
			throw new Exception("Process instance id $id_process_instance not valid", 0);
		}
		if (!is_numeric($id_activity) and !is_int($id_activity)) {
			throw new Exception("Activity id $id_activity not valid", 0);
		}

		$uid=$this->getNextUID($id_activity_instance_prec,$id_activity);

		$id_process=$this->getProcessIDFromProcessInstance($id_process_instance);
		if ($ui and $this->getActorType($uid)==='U') {
			$id_actor_assigned=$uid;
		}
		else {
			$id_actor_assigned="null";
		}

		$fingerprint=uniqid();

		$sync=$this->isActivitySync($id_activity);

		if ($sync==1) {
			$sql="select id from activity_instances where id_activity=$id_activity and id_process_instance=$id_process_instance";
			$rs=$this->db->query($sql);
			try {
				$this->rsCheck($rs);
			}
			catch (Exception $e) {
				$msg=$e->getMessage();
				$this->doLog("$sql ( $msg )",APPcelerate::L_ERROR);
				throw new Exception("Query Error", 0);
			}

			if ($rs->num_rows>0) {
				$id_activity_instance=$rs->fetch_array(MYSQLI_NUM)[0];

				return($id_activity_instance);
			}

		}

		$sql=sprintf("insert into activity_instances (fingerprint,id_activity,id_process,id_process_instance,id_actor_created,id_actor_assigned) values ('%s',%d,%d,%d,%d,%s)",$fingerprint,$id_activity,$id_process,$id_process_instance,$uid,$id_actor_assigned);
		$rs=$this->db->query($sql);
		try {
			$this->rsCheck($rs);
		}
		catch (Exception $e) {
			$msg=$e->getMessage();
			$this->doLog("$sql ( $msg )",APPcelerate::L_ERROR);
			throw new Exception("Query Error", 0);
		}

		$sql="select id from activity_instances where fingerprint='$fingerprint'";
		$rs1=$this->db->query($sql);
		try {
			$this->rsCheck($rs1);
		}
		catch (Exception $e) {
			$msg=$e->getMessage();
			$this->doLog("$sql ( $msg )",APPcelerate::L_ERROR);
			throw new Exception("Query Error", 0);
		}
		$id_activity_instance=$rs1->fetch_array(MYSQLI_NUM)[0];

		return($id_activity_instance);
	}

	private function clearAlert($id_alert) {
		$this->doLog("Requested with id alert $id_alert",APPcelerate::L_DEBUG);
		if (!is_numeric($id_alert) and !is_int($id_alert)) {
			throw new Exception("Alert id $id_alert not valid", 0);
		}

		$sql="update alerts set alert_done=1 where id=$id_alert";
		$rs=$this->db->query($sql);
		try {
			$this->rsCheck($rs);
		}
		catch (Exception $e) {
			$msg=$e->getMessage();
			$this->doLog("$sql ( $msg )",APPcelerate::L_ERROR);
			throw new Exception("Query Error", 0);
		}
	}

	private function setProcessAlert($id_process_instance, $id_activity_instance, $alarm_data, $severity=3) {
		$this->doLog("Requested with process instance $id_process_instance and activity instance $id_activity_instance and data $alarm_data",APPcelerate::L_DEBUG);
		if (!is_numeric($id_process_instance) and !is_int($id_process_instance)) {
			throw new Exception("Process instance id $id_process_instance not valid", 0);
		}
		if (!is_numeric($id_activity_instance) and !is_int($id_activity_instance)) {
			throw new Exception("Activity instance id $id_activity_instance not valid", 0);
		}

		$sql="insert into alerts (id_process_instance,id_activity_instance,specific_data,severity) values ($id_process_instance, $id_activity_instance, '$alarm_data', $severity)";
		$rs=$this->db->query($sql);
		try {
			$this->rsCheck($rs);
		}
		catch (Exception $e) {
			$msg=$e->getMessage();
			$this->doLog("$sql ( $msg )",APPcelerate::L_ERROR);
			throw new Exception("Query Error", 0);
		}

	}

	private function executeActivityInstanceOpenCode($id_activity_instance) {
		$this->doLog("Requested with activity instance $id_activity_instance",APPcelerate::L_DEBUG);
		if (!$this->getActivityInstanceVisibility($id_activity_instance)) {
			$this->doLog("Activity instance $id_activity_instance not visible, returning",APPcelerate::L_DEBUG);
			return;
		}
		$id_process_instance=$this->getProcessInstanceIDFromActivityInstanceID($id_activity_instance);
		$opening_script=$this->app_name."/bpme/views/".$this->getProcessCodeFromProcessInstance($id_process_instance)."_".$this->getActivityCodeFromActivityInstance($id_activity_instance)."_OPEN.php";
		if (stream_resolve_include_path($opening_script)) {
			include($opening_script);
		}
		else {
			$this->doLog("Cannot open $opening_script",APPcelerate::L_ERROR);
		}
	}

	private function createActionInstance($id_process_instance,$id_activity_instance_from,$id_action,$inbranch=0) {

		$this->doLog("Requested with process instance $id_process_instance and activity instance $id_activity_instance_from and action $id_action",APPcelerate::L_DEBUG);

		if (!is_numeric($id_process_instance) and !is_int($id_process_instance)) {
			throw new Exception("Process instance id $id_process_instance not valid", 0);
		}
		if (!is_numeric($id_activity_instance_from) and !is_int($id_activity_instance_from)) {
			throw new Exception("Activity instance id $id_activity_instance_from not valid", 0);
		}

		$id_process=$this->getProcessIDFromProcessInstance($id_process_instance);

		$fingerprint=uniqid();

		$sql=sprintf("insert into action_instances (fingerprint,id_process,id_action,id_process_instance,id_activity_instance_from,id_actor_executed,inbranch) values ('%s',%d,%d,%d,%d,%d,%d)",$fingerprint,$id_process,$id_action,$id_process_instance,$id_activity_instance_from,$this->getCurrentUID($id_activity_instance_from),$inbranch);
		$rs1=$this->db->query($sql);
		try {
			$this->rsCheck($rs1);
		}
		catch (Exception $e) {
			$msg=$e->getMessage();
			$this->doLog("$sql ( $msg )",APPcelerate::L_ERROR);
			throw new Exception("Query Error", 0);
		}

		$sql="select id from action_instances where fingerprint='$fingerprint'";
		$rs1=$this->db->query($sql);
		try {
			$this->rsCheck($rs1);
		}
		catch (Exception $e) {
			$msg=$e->getMessage();
			$this->doLog("$sql ( $msg )",APPcelerate::L_ERROR);
			throw new Exception("Query Error", 0);
		}
		$id_action_instance=$rs1->fetch_array(MYSQLI_NUM)[0];
		return($id_action_instance);
	}

	// Ritorna l'id dell'istanza dell'ultima attività eseguita
	private function dispatchActivity($id_activity_instance,$ui=false) {

		$this->doLog("Requested with activity instance  $id_activity_instance and ui $ui",APPcelerate::L_DEBUG);

		if (!is_numeric($id_activity_instance) and !is_int($id_activity_instance)) {
			throw new Exception("Activity instance id $id_activity_instance not valid", 0);
		}

		$sql="select id_activity,id_actor_created from activity_instances where id=$id_activity_instance";
		$rs=$this->db->query($sql);
		if ($rs->num_rows===0) {
			throw new Exception("Activity instance $id_activity_instance not found", 0);
		}
		$r=$rs->fetch_array(MYSQLI_NUM);
		$id_activity=$r[0];
		$id_actor_created=$r[1];

		$activity_type=$this->getActivityType($id_activity);

		if (!array_key_exists($activity_type,$this->activity_types)) {
			throw new Exception("Activity type $activity_type not allowed", 0);
		}

		$this->doLog("Activity type of $id_activity_instance is $activity_type",APPcelerate::L_DEBUG);

		switch ($activity_type) {
			case 'S':
				$this->assignActivity($id_activity_instance,$this->getCurrentUID());
				try {
					$this->followActions($id_activity_instance,$ui);
				}
				catch (Exception $e) {
					$msg=$e->getMessage();
					$this->doLog("Cannot follow actions from instance $id_activity_instance ( $msg )",APPcelerate::L_ERROR);
				}
				break;
			case 'F':
				$this->assignActivity($id_activity_instance,$id_actor_created);
				break;
			case 'U':
				break;
			case 'A':
				try {
					$this->executeActivity($id_activity_instance);
				}
				catch (Exception $e) {
					$msg=$e->getMessage();
					$this->doLog("Cannot execute automatic activity instance $id_activity_instance ( $msg )",APPcelerate::L_ERROR);
				}

				try {
					$this->followActions($id_activity_instance,$ui);
				}
				catch (Exception $e) {
					$msg=$e->getMessage();
					$this->doLog("Cannot follow actions from activity instance $id_activity_instance ( $msg )",APPcelerate::L_ERROR);
				}
				break;
			case 'T':
				$this->executeThirdPartyActivity($id_activity_instance);
				break;
			case 'C':
				$this->executeCounterpartActivity($id_activity_instance);
				break;
			case 'E':
				break;
		}

	}

private function getCTypeFromProcessID($id_process) {
	$this->doLog("Requested with process $id_process_instance",APPcelerate::L_DEBUG);

	if (!is_numeric($id_process) and !is_int($id_process)) {
		throw new Exception("Process id $id_process not valid", 0);
	}

	$sql="select counterpart_type from processes where id=$id_process";
	$rs=$this->db->query($sql);
	try {
		$this->rsCheck($rs);
	}
	catch (Exception $e) {
		$msg=$e->getMessage();
		$this->doLog("$sql ( $msg )",APPcelerate::L_ERROR);
		throw new Exception("Query Error", 0);
	}
	return($rs->fetch_array(MYSQLI_NUM)[0]);
}


private function executeCounterpartActivity($id_activity_instance) {
		$this->doLog("Requested with activty instance  $id_activity_instance",APPcelerate::L_DEBUG);

		if (!is_numeric($id_activity_instance) and !is_int($id_activity_instance)) {
			throw new Exception("Activity instance id $id_activity_instance not valid", 0);
		}

		$id_process_instance=$this->getProcessInstanceIDFromActivityInstanceID($id_activity_instance);

		$id_counterpart=$this->getProcessInstanceCounterpart($id_process_instance);

		$this->assignActivity($id_activity_instance,$id_counterpart);

		$TBSC = new clsTinyButStrong;
		$TBSC->LoadTemplate($this->app_name."/bpme/templates/STEP_COUNT_EMAIL.htm");

		$data=$this->getProcessInstanceData($id_process_instance,true,"block");
		$TBSC->MergeBlock("bPdata",$data);

		$sql="select * from activities where code='".$this->getActivityCodeFromActivityInstance($id_activity_instance)."' and id_process=".$this->getProcessIDFromProcessInstance($id_process_instance);
		$rs=$this->db->query($sql);
		try {
			$this->rsCheck($rs);
		}
		catch (Exception $e) {
			$msg=$e->getMessage();
			$this->doLog("$sql ( $msg )",APPcelerate::L_ERROR);
			throw new Exception("Query Error", 0);
		}
		$activity[0]=$rs->fetch_array(MYSQLI_ASSOC);
		$TBSC->MergeBlock("bActivity",$activity);

		$id_process=$this->getProcessIDFromProcessInstance($id_process_instance);
		$counterpart_type=$this->getCTypeFromProcessID($id_process);

		switch($counterpart_type) {
			case "U":
				$sql="select * from ospiti where id=(select id_ospite from partecipanti where id=$id_counterpart)";
				$rs=$this->db->query($sql);
				try {
					$this->rsCheck($rs);
				}
				catch (Exception $e) {
					$msg=$e->getMessage();
					$this->doLog("$sql ( $msg )", APPcelerate::L_ERROR);
					throw new Exception("Query Error", 0);
				}
				$counterpart=$this->fw->fetchAllAssoc($rs);
				break;
			case "C":
				$sql="select contact_name as nome, \"\" as cognome,contact_email as mail,* from clienti where id=$id_counterpart";
				$rs=$this->db->query($sql);
				try {
					$this->rsCheck($rs);
				}
				catch (Exception $e) {
					$msg=$e->getMessage();
					$this->doLog("$sql ( $msg )", APPcelerate::L_ERROR);
					throw new Exception("Query Error", 0);
				}
				$counterpart=$this->fw->fetchAllAssoc($rs);
				break;
		}
		$TBSC->MergeBlock("bCount",$counterpart);

		$url=$this->fw->app["base_url"]."/bpme/case/$id_activity_instance/";
		$TBSC->MergeField("url","$url");

		$id_owner=$this->getProcessInstanceOwner($id_process_instance);
		$sql="select fullname,mobile from users where id=$id_owner";
		$rs=$this->db->query($sql);
		try {
			$this->rsCheck($rs);
		}
		catch (Exception $e) {
			$msg=$e->getMessage();
			$this->doLog("$sql ( $msg )",APPcelerate::L_ERROR);
			throw new Exception("Query Error", 0);
		}
		$mitt=$rs->fetch_array(MYSQLI_ASSOC);

		$TBSC->MergeField("mitt",$mitt);

		$TBSC->Show(TBS_NOTHING);
		$mail=$TBSC->Source;

		$subject=sprintf("#%d %s > %s > Richiesta riscontro - Messaggio Automatico",$id_activity_instance,$data[0]["_mail_object"],$counterpart[0]["nome"]." ".$counterpart[0]["cognome"]);

		$from=$data[0]["_mail_from"];
		$bcc[0]=$data[0]["_mail_from"];

		if(!is_array($counterpart[0])) {
			die("ERRORE DI SISTEMA, CONTATTARE IL SUPPORTO<br>bpme,notarray,1035<br>".var_dump($counterpart));
		}

		if (array_key_exists("email",$counterpart[0]) and !empty($counterpart[0]["email"])) {
			$to[0]=$counterpart[0]["email"];
		}

		if (empty($to)) {
			$this->doLog("Counterpart with id ".$counterpart[0]["id"]." does not have any email, sent to myself",APPcelerate::L_WARNING);
			$to[0]=$from;
			$this->setProcessAlert($id_process_instance,$id_activity_instance, $counterpart[0]["nome"]." ".$counterpart[0]["cognome"]." non ha un email");
		}

		$cc=array();
/*		if (array_key_exists("email_aziendale",$counterpart[0]) and !empty($counterpart[0]["email_aziendale"])) {
			$cc[]=$counterpart[0]["email_aziendale"];
		}
		if (array_key_exists("email_personale",$counterpart[0]) and !empty($counterpart[0]["email_personale"])) {
			$cc[]=$counterpart[0]["email_personale"];
		}
*/
		$this->fw->sendEmail($mail, $subject, $data[0]["_mail_from"], $to,$cc,$bcc);

	}

	private function executeThirdPartyActivity($id_activity_instance) {
		$this->doLog("Requested with activty instance  $id_activity_instance",APPcelerate::L_DEBUG);

		if (!is_numeric($id_activity_instance) and !is_int($id_activity_instance)) {
			throw new Exception("Activity instance id $id_activity_instance not valid", 0);
		}

		$id_process_instance=$this->getProcessInstanceIDFromActivityInstanceID($id_activity_instance);

		$id_third_party=$this->getActivityThirdParty($this->getActivityIDFromActivityInstance($id_activity_instance));

		$this->assignActivity($id_activity_instance,$id_third_party);

		$TBSC = new clsTinyButStrong;
		$TBSC->LoadTemplate($this->app_name."/bpme/templates/STEP_TP_EMAIL.htm");

		$data=$this->getProcessInstanceData($id_process_instance,true,"block");
		$TBSC->MergeBlock("bPdata",$data);

		$sql="select * from activities where code='".$this->getActivityCodeFromActivityInstance($id_activity_instance)."' and id_process=".$this->getProcessIDFromProcessInstance($id_process_instance);
		$rs=$this->db->query($sql);
		try {
			$this->rsCheck($rs);
		}
		catch (Exception $e) {
			$msg=$e->getMessage();
			$this->doLog("$sql ( $msg )",APPcelerate::L_ERROR);
			throw new Exception("Query Error", 0);
		}
		$activity[0]=$rs->fetch_array(MYSQLI_ASSOC);
		$TBSC->MergeBlock("bActivity",$activity);

		$sql="select * from fornitori where id=(select id_fornitore from process_third_parties where id=$id_third_party)";
		$rs=$this->db->query($sql);
		try {
			$this->rsCheck($rs);
		}
		catch (Exception $e) {
			$msg=$e->getMessage();
			$this->doLog("$sql ( $msg )",APPcelerate::L_ERROR);
			throw new Exception("Query Error", 0);
		}
		$third_party=$this->fw->fetchAllAssoc($rs);
		$TBSC->MergeBlock("bTP",$third_party);

		$url=$this->fw->app["base_url"]."/bpme/case/$id_activity_instance/";
		$TBSC->MergeField("url","$url");

		$id_owner=$this->getProcessInstanceOwner($id_process_instance);
		$sql="select fullname,mobile from users where id=$id_owner";
		$rs=$this->db->query($sql);
		try {
			$this->rsCheck($rs);
		}
		catch (Exception $e) {
			$msg=$e->getMessage();
			$this->doLog("$sql ( $msg )",APPcelerate::L_ERROR);
			throw new Exception("Query Error", 0);
		}
		$mitt=$rs->fetch_array(MYSQLI_ASSOC);

		$TBSC->MergeField("mitt",$mitt);

		$TBSC->Show(TBS_NOTHING);
		$mail=$TBSC->Source;

		$subject=sprintf("#%d %s > %s > Richiesta riscontro - Messaggio Automatico",$id_activity_instance,$data[0]["_mail_object"],$third_party[0]["ragsoc"]." ".$third_party[0]["nome_struttura"]);

		$from=$data[0]["_mail_from"];
		$bcc[0]=$data[0]["_mail_from"];

		if (array_key_exists("riferimento_email",$third_party[0]) and !empty($third_party[0]["riferimento_email"])) {
			$to[0]=$third_party[0]["riferimento_email"];
		}

		if (empty($to)) {
			$this->doLog("Third party with id ".$third_party[0]["id"]." does not have any email, sent to myself",APPcelerate::L_WARNING);
			$to[0]=$from;
			$this->setProcessAlert($id_process_instance,$id_activity_instance, $third_party[0]["ragsoc"]." ".$third_party[0]["nome_struttura"]." non ha un email");
		}

		$cc=array();

		$this->fw->sendEmail($mail, $subject, $data[0]["_mail_from"], $to,$cc,$bcc);

	}

	private function executeActivity($id_activity_instance) {
		global $id_activity_instance_action;

		$this->doLog("Requested with activty instance $id_activity_instance",APPcelerate::L_DEBUG);
		if (!is_numeric($id_activity_instance) and !is_int($id_activity_instance)) {
			throw new Exception("Activity instance id $id_activity_instance not valid", 0);
		}

		if(!$this->getActivityInstanceVisibility($id_activity_instance)) {
			$this->doLog("NO VISIBILITY for activity instance $id_activity_instance",APPcelerate::L_DEBUG);
			return;
		}

		$this->executeActivityInstanceOpenCode($id_activity_instance);

		$this->assignActivity($id_activity_instance,$this->getCurrentUID($id_activity_instance));

		$id_process_instance=$this->getProcessInstanceIDFromActivityInstanceID($id_activity_instance);
		$auto_script=$this->app_name."/bpme/views/".$this->getProcessCodeFromProcessInstance($id_process_instance)."_".$this->getActivityCodeFromActivityInstance($id_activity_instance)."_AUTO.php";
		if (stream_resolve_include_path($auto_script)) {
			include($auto_script);
		}
	}

	private function testActivity($activity,$process_data=array(),$context="") {
		$this->doLog("Requested with activty $activity",APPcelerate::L_DEBUG);

		$id_activity=$this->getActivityIDFromActivityCode($activity);

		$this->fw->AddMerge("field","context",$context);
		$this->fw->AddMerge("block","process_data",$process_data);
		$this->fw->AddMerge("field","piid","0");
		$this->fw->AddMerge("field","note","");
		$this->fw->AddMerge("field","aiid","0");
		$tmpl=$this->app_name."/bpme/templates/".$this->getProcessCodeFromActivityID($id_activity)."_".$activity.".htm";
		$this->fw->app["TBS"]->LoadTemplate($tmpl,"+");
	}

	private function showActivity($this_id_activity_instance) {
		global $id_activity_instance,$id_process_instance;

		$id_activity_instance=$this_id_activity_instance;

		$this->doLog("Requested with activty instance $id_activity_instance",APPcelerate::L_DEBUG);
		if (!is_numeric($id_activity_instance) and !is_int($id_activity_instance)) {
			throw new Exception("Activity instance id $id_activity_instance not valid", 0);
		}

		$this->executeActivityInstanceOpenCode($id_activity_instance);

		if($this->getActivityTypeFromActivityInstance($id_activity_instance)==="U") {
			$this->assignActivity($id_activity_instance,$this->getCurrentUID());
		}

		$id_process_instance=$this->getProcessInstanceIDFromActivityInstanceID($id_activity_instance);

		$type=$this->getActivityTypeFromActivityInstance($id_activity_instance);

		switch($type) {
			case "T":
			case "C":
				$data=$this->getProcessInstanceData($id_process_instance,false,"block");
				$code=$this->getCounterpartCodeFromActivityInstance($id_activity_instance);
				$this->fw->AddMerge("field","ccode",$code);
				break;
			default:
				$data=$this->getProcessInstanceData($id_process_instance,false,"list");
		}

		$data_raw=$this->getProcessInstanceData($id_process_instance,true,"array");

		$context=$this->getActivityInstanceContext($id_activity_instance);
		$note=$this->getProcessInstanceNote($id_process_instance);

		$this->fw->AddMerge("block","context",$context);
		$this->fw->AddMerge("block","process_data",$data);
		$this->fw->AddMerge("field","process_data_raw",$data_raw);
		$this->fw->AddMerge("field","piid",$id_process_instance);
		$this->fw->AddMerge("field","piid",$id_process_instance);
		$this->fw->AddMerge("field","note",$note);
		$this->fw->AddMerge("field","aiid",$id_activity_instance);

		$visible=$this->getActivityInstanceVisibility($id_activity_instance);

		if ($visible==1) {
			$tmpl=$this->app_name."/bpme/templates/".$this->getProcessCodeFromProcessInstance($id_process_instance)."_".$this->getActivityCodeFromActivityInstance($id_activity_instance).".htm";
		}
		else {
			$tmpl=$this->app_name."/bpme/templates/STEP_NOVISIBLE.htm";
		}
		$this->fw->app["TBS"]->LoadTemplate($tmpl,"+");
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
			$this->doLog("$sql ( $msg )",APPcelerate::L_ERROR);
			throw new Exception("Query Error", 0);
		}

		while ($r=$rs->fetch_array(MYSQLI_ASSOC)) {
			$id_action=$r["id"];

			$id_process_instance=$this->getProcessInstanceIDFromActivityInstanceID($id_activity_instance_from);

			$sync=$this->isActivitySync($this->getActivityIDFromActivityInstance($id_activity_instance_from));

			$branch=$this->isActivityBranch($this->getActivityIDFromActivityInstance($id_activity_instance_from));

			if ($branch==0 and $sync==0) {
				$id_action_instance_prec=$this->getActionInstanceIDFromActivityInstanceTo($id_activity_instance_from);
				if ($id_action_instance_prec<>-1) {
					$branch=$this->isActionInstanceInBranch($id_action_instance_prec);
				}
			}

			$id_action_instance=$this->createActionInstance($id_process_instance,$id_activity_instance_from,$id_action,$branch);

			if (!empty($r["entry_condition"])) {
				list($evaluation,$ok)=$this->checkActionCondition($id_activity_instance_from,$id_action,$r["entry_condition"]);
				$this->doLog("Condition evaluation returned with evaluation='$evaluation' and ok=".(($ok) ? 'true' : 'false'),APPcelerate::L_DEBUG);
				$sql="update action_instances set entry_condition_evaluation='$evaluation' where id=$id_action_instance";
				$rs1=$this->db->query($sql);
				try {
					$this->rsCheck($rs1);
				}
				catch (Exception $e) {
					$msg=$e->getMessage();
					$this->doLog("$sql ( $msg )",APPcelerate::L_ERROR);
					throw new Exception("Query Error", 0);
				}
				if (!$ok) {
					$this->doLog("Condition failed, skipping",APPcelerate::L_DEBUG);
					if ($this->isActionInstanceInBranch($id_action_instance)==1) {
						$this->doLog("In branch, negating",APPcelerate::L_DEBUG);
						$this->negateBranch($id_action_instance);
					}
					continue;
				}
			}
			$this->executeAction($id_action_instance,$ui);
		}

		if ($ui) {
			$type=$this->getActivityTypeFromActivityInstance($id_activity_instance_from);
			$context=$this->getActivityInstanceContext($id_activity_instance_from);

			$this->fw->AddMerge("block","context",$context);

			$tasks=$this->getAvailableActivities($this->getCurrentUID($id_activity_instance_from),$id_process_instance);

			$this->fw->AddMerge("field","piid",$id_process_instance);
			$this->fw->AddMerge("field","aiid","$id_activity_instance_from");
			$this->fw->AddMerge("block","bTasks",$tasks);

			switch ($type) {
				case "S":
				case "U":
					$this->fw->app["TBS"]->LoadTemplate($this->app_name."/bpme/templates/STEP_RESULT.htm","+");
					break;
				case "T":
					$this->fw->app["TBS"]->LoadTemplate($this->app_name."/bpme/templates/STEP_RESULT_TP.htm","+");
					break;
				case "C":
					$this->fw->app["TBS"]->LoadTemplate($this->app_name."/bpme/templates/STEP_RESULT_COUNTERPART.htm","+");
					break;
				case "F":
					$this->fw->app["TBS"]->LoadTemplate($this->app_name."/bpme/templates/STEP_LAST.htm","+");
					break;
			}
		}

		return true;

	}

	private function checkActionCondition($id_activity_instance,$id_action,$condition) {
		$this->doLog("Requested with $id_activity_instance $id_action $condition",APPcelerate::L_DEBUG);

		if (!is_numeric($id_activity_instance) and !is_int($id_activity_instance)) {
			throw new Exception("Activity instance id $id_action_instance not valid", 0);
		}

		$condition=strtoupper($condition);

		$id_process_instance=$this->getProcessInstanceIDFromActivityInstanceID($id_activity_instance);
		$data=$this->getProcessInstanceData($id_process_instance,true,"array");

		$confirm=strtoupper($data["lastconfirm"]);
		if ($confirm===$condition) {
			$r=true;
		}
		else {
			$r=false;
		}
		return(array($confirm,$r));
	}

	private function negateBranch($id_action_instance,$ui=false) {

		return;

		$this->doLog("Requested with action instance $id_action_instance and ui $ui",APPcelerate::L_DEBUG);

		if (!is_numeric($id_action_instance) and !is_int($id_action_instance)) {
			throw new Exception("Action instance id $id_action_instance not valid", 0);
		}

		$sql="select id_activity_instance_from,id_action from action_instances where id=$id_action_instance";
		$rs=$this->db->query($sql);
		try {
			$this->rsCheck($rs);
		}
		catch (Exception $e) {
			$msg=$e->getMessage();
			$this->doLog("$sql ( $msg )",APPcelerate::L_ERROR);
			throw new Exception("Query Error", 0);
		}
		list($id_activity_instance_from,$id_action)=$rs->fetch_array(MYSQLI_NUM);

		$id_process_instance=$this->getProcessInstanceIDFromActivityInstanceID($id_activity_instance_from);

		$sql="select id_activity_to from actions where id=$id_action";
		$rs=$this->db->query($sql);
		try {
			$this->rsCheck($rs);
		}
		catch (Exception $e) {
			$msg=$e->getMessage();
			$this->doLog("$sql ( $msg )",APPcelerate::L_ERROR);
			throw new Exception("Query Error", 0);
		}
		$id_activity_to=$rs->fetch_array(MYSQLI_NUM)[0];

		$id_activity_instance_to=$this->createActivityInstance($id_activity_instance_from,$id_process_instance,$id_activity_to);

		$sync=$this->isActivitySync($id_activity_to);

		if ($sync==1) {
			return;
		}

		$id_actor=$this->getCurrentUID($id_activity_instance_from);

		$sql="update action_instances set followed=0, id_actor_executed=$id_actor, date_executed=now() where id=$id_action_instance";
		$rs=$this->db->query($sql);
		try {
			$this->rsCheck($rs);
		}
		catch (Exception $e) {
			$msg=$e->getMessage();
			$this->doLog("$sql ( $msg )",APPcelerate::L_ERROR);
			throw new Exception("Query Error", 0);
		}

		$sql="select id,id_activity_to from actions where id_activity_from=$id_activity_to and id_activity_to not in (select id_activity from activity_instances where id_process_instance=$id_process_instance)";
		$rs=$this->db->query($sql);
		try {
			$this->rsCheck($rs);
		}
		catch (Exception $e) {
			$msg=$e->getMessage();
			$this->doLog("$sql ( $msg )",APPcelerate::L_ERROR);
			throw new Exception("Query Error", 0);
		}
		while ($r=$rs->fetch_array(MYSQLI_NUM)) {
			$id_action=$r[0];
			$id_activity_from=$r[1];

			$id_activity_instance_from=$this->createActivityInstance($id_activity_instance_to,$id_process_instance,$id_activity_from);

			$id_action_instance=$this->createActionInstance($id_process_instance,$id_activity_instance_from,$id_action,1);

			$this->negateBranch($id_action_instance);

		}

	}

	private function executeAction($id_action_instance,$ui=false) {
		global $id_activity_instance_closing;

		$this->doLog("Requested with action instance $id_action_instance and ui $ui",APPcelerate::L_DEBUG);

		if (!is_numeric($id_action_instance) and !is_int($id_action_instance)) {
			throw new Exception("Action instance id $id_action_instance not valid", 0);
		}

		$sql="select id_activity_instance_from from action_instances where id=$id_action_instance";
		$rs=$this->db->query($sql);
		try {
			$this->rsCheck($rs);
		}
		catch (Exception $e) {
			$msg=$e->getMessage();
			$this->doLog("$sql ( $msg )",APPcelerate::L_ERROR);
			throw new Exception("Query Error", 0);
		}
		$id_activity_instance_from=$rs->fetch_array(MYSQLI_NUM)[0];

		$id_process_instance=$this->getProcessInstanceIDFromActivityInstanceID($id_activity_instance_from);

		//Concludo l'activity precedente ed eseguo closing script, se non era già conclusa
		$sql="select id from activity_instances where date_completed is null and id_actor_completed is null and id=$id_activity_instance_from";
		$rs=$this->db->query($sql);
		try {
			$this->rsCheck($rs);
		}
		catch (Exception $e) {
			$msg=$e->getMessage();
			$this->doLog("$sql ( $msg )",APPcelerate::L_ERROR);
			throw new Exception("Query Error", 0);
		}
		if ($rs->num_rows>0) {

			$sql="update activity_instances set date_completed=now(), id_actor_completed=".$this->getCurrentUID($id_activity_instance_from)." where id=$id_activity_instance_from";
			$rs=$this->db->query($sql);
			try {
				$this->rsCheck($rs);
			}
			catch (Exception $e) {
				$msg=$e->getMessage();
				$this->doLog("$sql ( $msg )",APPcelerate::L_ERROR);
				throw new Exception("Query Error", 0);
			}

			// Prepario variabile per _CLOSE script
			$id_activity_instance=$id_activity_instance_from;

			$closing_script=$this->app_name."/bpme/views/".$this->getProcessCodeFromProcessInstance($id_process_instance)."_".$this->getActivityCodeFromActivityInstance($id_activity_instance_from)."_CLOSE.php";
			if (stream_resolve_include_path($closing_script)) {
				include($closing_script);
			}

		}

		//Definisco l'activity successiva
		$sql="select id_activity_to from actions where id=(select id_action from action_instances where id=$id_action_instance)";
		$rs=$this->db->query($sql);
		try {
			$this->rsCheck($rs);
		}
		catch (Exception $e) {
			$msg=$e->getMessage();
			$this->doLog("$sql ( $msg )",APPcelerate::L_ERROR);
			throw new Exception("Query Error", 0);
		}
		$id_activity_to=$rs->fetch_array(MYSQLI_NUM)[0];

		//Creo l'istanza di activity di arrivo
		$id_activity_instance_to=$this->createActivityInstance($id_activity_instance_from,$id_process_instance,$id_activity_to,$ui);

		//Chiudo l'action
		$sql="update action_instances set id_activity_instance_to=$id_activity_instance_to, date_executed=now(), id_actor_executed=".$this->getCurrentUID($id_activity_instance_from)." where id=$id_action_instance";
		$rs=$this->db->query($sql);
		try {
			$this->rsCheck($rs);
		}
		catch (Exception $e) {
			$msg=$e->getMessage();
			$this->doLog("$sql ( $msg )",APPcelerate::L_ERROR);
			throw new Exception("Query Error", 0);
		}

		// Se ci sono altre actions in attesa di essere eseguite, visibility = 0
		if ($this->isActivityInstanceSync($id_activity_instance_to)==0 or $this->getActivityInstanceWaitingInActions($id_process_instance, $id_activity_instance_to)==0) {
			$this->dispatchActivity($id_activity_instance_to,$ui);
		}
		else {
			try {
				$this->setActivityInstanceVisibility($id_activity_instance_to,0);
			}
			catch (Exception $e) {
				$this->doLog("Cannot set Activity Instance visibility for id '$id_activity_instance_to'",APPcelerate::L_ERROR);
			}
		}


	}

	private function getActivityInstanceWaitingInActions($id_process_instance, $id_activity_instance_to) {
		$this->doLog("Requested with activity instance to id $id_activity_instance_to",APPcelerate::L_DEBUG);

		if (!is_numeric($id_activity_instance_to) and !is_int($id_activity_instance_to)) {
			throw new Exception("Activity instance to id $id_activity_instance_to not valid", 0);
		}

		$id_activity=$this->getActivityIDFromActivityCode($this->getActivityCodeFromActivityInstance($id_activity_instance_to));

		$sql="select count(id) from actions where id_activity_to=$id_activity";
		$rs=$this->db->query($sql);
		try {
			$this->rsCheck($rs);
		}
		catch (Exception $e) {
			$msg=$e->getMessage();
			$this->doLog("$sql ( $msg )",APPcelerate::L_ERROR);
			throw new Exception("Query Error", 0);
		}
		$n_expected=$rs->fetch_array(MYSQLI_NUM)[0];

		$sql="select count(action_instances.id) from actions join action_instances on actions.id=action_instances.id_action where actions.id_activity_to=$id_activity and action_instances.id_process_instance=$id_process_instance";
		$rs=$this->db->query($sql);
		try {
			$this->rsCheck($rs);
		}
		catch (Exception $e) {
			$msg=$e->getMessage();
			$this->doLog("$sql ( $msg )",APPcelerate::L_ERROR);
			throw new Exception("Query Error", 0);
		}
		$n_executed=$rs->fetch_array(MYSQLI_NUM)[0];

		return($n_expected-$n_executed);
	}

	private function setActivityInstanceVisibility($id_activity_instance,$visible=1) {
		$this->doLog("Requested with activity instance $id_activity_instance",APPcelerate::L_DEBUG);

$visible=1;

		if (!is_numeric($id_activity_instance) and !is_int($id_activity_instance)) {
			throw new Exception("Activity instance id $id_activity_instance not valid", 0);
		}

		$sql="update activity_instances set visible=$visible where id=$id_activity_instance";
		$rs=$this->db->query($sql);
		try {
			$this->rsCheck($rs);
		}
		catch (Exception $e) {
			$msg=$e->getMessage();
			$this->doLog("$sql ( $msg )",APPcelerate::L_ERROR);
			throw new Exception("Query Error", 0);
		}
	}

	private function getActivityInstanceVisibility($id_activity_instance) {
		$this->doLog("Requested with activity instance $id_activity_instance",APPcelerate::L_DEBUG);

		if (!is_numeric($id_activity_instance) and !is_int($id_activity_instance)) {
			throw new Exception("Activity instance id $id_activity_instance not valid", 0);
		}

		$sql="select visible from activity_instances where id=$id_activity_instance";
		$rs=$this->db->query($sql);
		try {
			$this->rsCheck($rs);
		}
		catch (Exception $e) {
			$msg=$e->getMessage();
			$this->doLog("$sql ( $msg )",APPcelerate::L_ERROR);
			throw new Exception("Query Error", 0);
		}

		return(1);

		return($rs->fetch_array(MYSQLI_NUM)[0]);
	}

	private function isActivityBranch($id_activity) {
		$this->doLog("Requested with activity id $id_activity",APPcelerate::L_DEBUG);

		if (!is_numeric($id_activity) and !is_int($id_activity)) {
			throw new Exception("Activity instance id $id_activity not valid", 0);
		}

		$sql="select branch from activities where id=$id_activity";
		$rs=$this->db->query($sql);
		try {
			$this->rsCheck($rs);
		}
		catch (Exception $e) {
			$msg=$e->getMessage();
			$this->doLog("$sql ( $msg )",APPcelerate::L_ERROR);
			throw new Exception("Query Error", 0);
		}

		return($rs->fetch_array(MYSQLI_NUM)[0]);
	}

	private function isActivitySync($id_activity) {
		$this->doLog("Requested with activity id $id_activity",APPcelerate::L_DEBUG);

		if (!is_numeric($id_activity) and !is_int($id_activity)) {
			throw new Exception("Activity instance id $id_activity not valid", 0);
		}

		$sql="select sync from activities where id=$id_activity";
		$rs=$this->db->query($sql);
		try {
			$this->rsCheck($rs);
		}
		catch (Exception $e) {
			$msg=$e->getMessage();
			$this->doLog("$sql ( $msg )",APPcelerate::L_ERROR);
			throw new Exception("Query Error", 0);
		}

		return($rs->fetch_array(MYSQLI_NUM)[0]);
	}

	private function isActionInstanceInBranch($id_action_instance) {
		$this->doLog("Requested with action instance $id_action_instance",APPcelerate::L_DEBUG);

		if (!is_numeric($id_action_instance) and !is_int($id_action_instance)) {
			throw new Exception("Action instance id $id_action_instance not valid", 0);
		}

		$sql="select inbranch from action_instances where id=$id_action_instance";
		$rs=$this->db->query($sql);
		try {
			$this->rsCheck($rs);
		}
		catch (Exception $e) {
			$msg=$e->getMessage();
			$this->doLog("$sql ( $msg )",APPcelerate::L_ERROR);
			throw new Exception("Query Error", 0);
		}

		return($rs->fetch_array(MYSQLI_NUM)[0]);
	}

	private function isActivityInstanceSync($id_activity_instance) {
		$this->doLog("Requested with activity instance $id_activity_instance",APPcelerate::L_DEBUG);

		if (!is_numeric($id_activity_instance) and !is_int($id_activity_instance)) {
			throw new Exception("Activity instance id $id_activity_instance not valid", 0);
		}

		$sql="select sync from activities where id=(select id_activity from activity_instances where id=$id_activity_instance)";
		$rs=$this->db->query($sql);
		try {
			$this->rsCheck($rs);
		}
		catch (Exception $e) {
			$msg=$e->getMessage();
			$this->doLog("$sql ( $msg )",APPcelerate::L_ERROR);
			throw new Exception("Query Error", 0);
		}

		return($rs->fetch_array(MYSQLI_NUM)[0]);
	}

	private function getActivityInstanceType($id_activity_instance) {
		$this->doLog("Requested with activity instance $id_activity_instance",APPcelerate::L_DEBUG);

		if (!is_numeric($id_activity_instance) and !is_int($id_activity_instance)) {
			throw new Exception("Activity instance id $id_activity_instance not valid", 0);
		}

		$sql="select activity_type from activities where id=(select id_activity from activity_instances where id=$id_activity_instance)";
		$rs=$this->db->query($sql);
		try {
			$this->rsCheck($rs);
		}
		catch (Exception $e) {
			$msg=$e->getMessage();
			$this->doLog("$sql ( $msg )",APPcelerate::L_ERROR);
			throw new Exception("Query Error", 0);
		}

		return($rs->fetch_array(MYSQLI_NUM)[0]);
	}

	private function getAvailableActivities($uid=0,$id_process_instance=0) {
		$this->doLog("Requested with uid $uid and process instance $id_process_instance",APPcelerate::L_DEBUG);

		if (!is_numeric($uid) and !is_int($uid)) {
			throw new Exception("User id $uid not valid", 0);
		}
		if (!is_numeric($id_process_instance) and !is_int($id_process_instance)) {
			throw new Exception("Process instance id $id_process_instance not valid", 0);
		}

		$sql="
			select
			activity_instances.id as id,
			activities.code as code,
			activities.name as name,
			processes.code as process_code,
			processes.name as process_name
			from activity_instances join activities on activities.id=activity_instances.id_activity join processes on processes.id=activity_instances.id_process where activity_instances.date_completed is null and activities.activity_type in ('U')
		";
		if ($uid!==0) {
			$sql.=" and activity_instances.id_actor_assigned=$uid";
		}
		else {
			$sql.=" and activity_instances.id_actor_assigned is null";
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
			$this->doLog("$sql ( $msg )",APPcelerate::L_ERROR);
			throw new Exception("Query Error", 0);
		}

		return($this->fw->fetchAllAssoc($rs));
	}

	private function getNextUID($id_activity_instance_prec,$id_activity) {
		$this->doLog("Requested with Activity instance prec id $id_activity_instance_prec",APPcelerate::L_DEBUG);
		if (!is_numeric($id_activity_instance_prec) and !is_int($id_activity_instance_prec)) {
			throw new Exception("Activity instance prec id $id_activity_instance_prec not valid", 0);
		}

		$this->doLog("Requested with Activity id $id_activity",APPcelerate::L_DEBUG);
		if (!is_numeric($id_activity) and !is_int($id_activity)) {
			throw new Exception("Activity id $id_activity not valid", 0);
		}

		$type=$this->getActivityType($id_activity);

		if($type==='U') {
			$pid=$this->getProcessInstanceIDFromActivityInstanceID($id_activity_instance_prec);
			$uid=$this->getProcessInstanceCreatedUser($pid);
		}
		else {
			if (array_key_exists("uid",$this->fw->app)) {
				$uid=$this->fw->app["uid"];
			}
			else {
				$uid=$this->getCurrentUID($id_activity_instance_prec);
			}
		}

		return($uid);

	}

	private function getCurrentUID($id_activity_instance=0) {
		$this->doLog("Requested with Activity instance id $id_activity_instance",APPcelerate::L_DEBUG);
		if (!is_numeric($id_activity_instance) and !is_int($id_activity_instance)) {
			throw new Exception("Activity instance id $id_activity_instance not valid", 0);
		}

		if ($id_activity_instance==0) {
			if (array_key_exists("uid",$this->fw->app)) {
				return $this->fw->app["uid"];
			}
			else {
				return ("null");
			}
		}
		else {

			$uid=$this->getActivityInstanceAssignedActor($id_activity_instance);
			if ($uid!=-1) {
				return($uid);
			}
			else {
				$uid=$this->getActivityInstanceCreatedUser($id_activity_instance);
				return($uid);
			}
		}

	}

	private function getProcessInstanceCreatedUser($id_process_instance) {
		$this->doLog("Requested with Process instance id $id_process_instance",APPcelerate::L_DEBUG);
		if (!is_numeric($id_process_instance) and !is_int($id_process_instance)) {
			throw new Exception("Process instance id $id_process_instance not valid", 0);
		}
		$sql="select id_actor_created from process_instances where id=$id_process_instance";
		$rs=$this->db->query($sql);
		try {
			$this->rsCheck($rs);
		}
		catch (Exception $e) {
			$msg=$e->getMessage();
			$this->doLog("$sql ( $msg )",APPcelerate::L_ERROR);
			throw new Exception("Query Error", 0);
		}
		return($rs->fetch_array(MYSQLI_NUM)[0]);
	}

	private function getActivityInstanceAssignedActor($id_activity_instance) {
		$this->doLog("Requested with Activity instance id  $id_activity_instance",APPcelerate::L_DEBUG);
		if (!is_numeric($id_activity_instance) and !is_int($id_activity_instance)) {
			throw new Exception("Activity instance id $id_activity_instance not valid", 0);
		}
		$sql="select id_actor_assigned from activity_instances where id=$id_activity_instance";
		$rs=$this->db->query($sql);
		try {
			$this->rsCheck($rs);
		}
		catch (Exception $e) {
			$msg=$e->getMessage();
			$this->doLog("$sql ( $msg )",APPcelerate::L_ERROR);
			throw new Exception("Query Error", 0);
		}
		$id=$rs->fetch_array(MYSQLI_NUM)[0];
		if ($rs->num_rows!=0 and !empty($id)) {
			return($id);
		}
		else {
			return(-1);
		}
	}

	private function getActivityInstanceCreatedUser($id_activity_instance) {
		$this->doLog("Requested with Activity instance id $id_activity_instance",APPcelerate::L_DEBUG);
		if (!is_numeric($id_activity_instance) and !is_int($id_activity_instance)) {
			throw new Exception("Activity instance id $id_activity_instance not valid", 0);
		}
		$sql="select id_actor_created from activity_instances where id=$id_activity_instance";
		$rs=$this->db->query($sql);
		try {
			$this->rsCheck($rs);
		}
		catch (Exception $e) {
			$msg=$e->getMessage();
			$this->doLog("$sql ( $msg )",APPcelerate::L_ERROR);
			throw new Exception("Query Error", 0);
		}
		return($rs->fetch_array(MYSQLI_NUM)[0]);
	}

	private function getActivityID($process_code,$activity_code) {
		$this->doLog("Requested with process_code $process_code and activity_code $activity_code",APPcelerate::L_DEBUG);

		$sql="select id from processes where code='$process_code'";
		$rs=$this->db->query($sql);
		if ($rs->num_rows===0) {
			throw new Exception("Process $code not found", 0);
			$this->doLog("Process $code not found",APPcelerate::L_ERROR);
		}
		$id_process=$rs->fetch_array(MYSQLI_NUM)[0];

		$sql="select id from activities where id_process=$id_process and code='$activity_code'";
		$rs=$this->db->query($sql);
		try {
			$this->rsCheck($rs);
		}
		catch (Exception $e) {
			$msg=$e->getMessage();
			$this->doLog("$sql ( $msg )",APPcelerate::L_ERROR);
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

	private function getActivityCode($id_activity) {
		if (!is_numeric($id_activity) and !is_int($id_activity)) {
			throw new Exception("Activity id $id_activity not valid", 0);
		}

		$sql="select code from activities where id=$id_activity";
		$rs=$this->db->query($sql);
		if ($rs->num_rows===0) {
			throw new Exception("Activity id $id_activity not found", 0);
		}

		return ($rs->fetch_array(MYSQLI_NUM)[0]);
	}

	public function getActivityIDFromActivityCode($activity) {
		$sql="select id from activities where code='$activity'";
		$rs=$this->db->query($sql);
		if ($rs->num_rows===0) {
			throw new Exception("Activity id $id_activity not found", 0);
		}

		return ($rs->fetch_array(MYSQLI_NUM)[0]);
	}

	public function getActivityIDFromActivityInstance($id_activity_instance) {
		$sql="select id_activity from activity_instances where id='$id_activity_instance'";
		$rs=$this->db->query($sql);
		if ($rs->num_rows===0) {
			throw new Exception("Activity id $id_activity not found", 0);
		}

		return ($rs->fetch_array(MYSQLI_NUM)[0]);
	}

	private function getActivityTypeFromActivityInstance($id_activity_instance) {
		if (!is_numeric($id_activity_instance) and !is_int($id_activity_instance)) {
			throw new Exception("Activity instance id $id_activity_instance not valid", 0);
		}

		$sql="select activity_type from activities where id=".$this->getActivityFromActivityInstance($id_activity_instance);
		$rs=$this->db->query($sql);
		if ($rs->num_rows===0) {
			throw new Exception("Activity id $id_activity not found", 0);
		}

		return ($rs->fetch_array(MYSQLI_NUM)[0]);
	}

	private function getActivityFromActivityInstance($id_activity_instance) {
		if (!is_numeric($id_activity_instance) and !is_int($id_activity_instance)) {
			throw new Exception("Activity instance id $id_activity_instance not valid", 0);
		}

		$sql="select id_activity from activity_instances where id=$id_activity_instance";
		$rs=$this->db->query($sql);
		if ($rs->num_rows===0) {
			throw new Exception("Activity id $id_activity not found", 0);
		}

		return ($rs->fetch_array(MYSQLI_NUM)[0]);
	}

	private function isActivityInstanceOpen($id_activity_instance) {
		if (!is_numeric($id_activity_instance) and !is_int($id_activity_instance)) {
			throw new Exception("Activity instance id $id_activity_instance not valid", 0);
		}

		$sql="select date_completed from activity_instances where id=$id_activity_instance";
		$rs=$this->db->query($sql);
		if ($rs->num_rows===0) {
			throw new Exception("Activity id $id_activity not found", 0);
		}

		$res=$rs->fetch_array(MYSQLI_NUM)[0];

		if(empty($res)) {
			return (true);
		}
		else {
			return (false);
		}
	}

	private function doLog($msg,$context='N',$id_instance=0,$level=APPcelerate::L_DEBUG) {
		if (!is_numeric($id_instance) and !is_int($id_instance)) {
			throw new Exception("ID instance $id_instance not valid", 0);
		}

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

		$acontext=array(
			$code,
			$process,
			$activity,
			$action
		);

		$msg="$where ".$msg;

		$this->fw->writeLog($this->logger,$level,$msg,$acontext);

	}

	private function getCases($uid) {
		global $id_cliente;

		if (!is_numeric($uid) and !is_int($uid)) {
			throw new Exception("Uid $uid not valid", 0);
		}

		$sql="
			select
				activity_instances.id as aiid,
				activities.id as aid,
				activities.name as name,
				activities.code as code,
				processes.name as pname,
				processes.code as pcode,
				process_instances.data as data,
				process_instances.id as caseid
			from
				process_instances
					join processes on process_instances.id_process=processes.id
					left join partecipanti on process_instances.id_counterpart=partecipanti.id
					left join ospiti on partecipanti.id_ospite=ospiti.id
					join activity_instances on process_instances.id=activity_instances.id_process_instance
					join activities on activity_instances.id_activity=activities.id
			where
				(ospiti.id_cliente=$id_cliente or ospiti.id_cliente is null)
				and (activity_instances.id_actor_assigned=$uid or activity_instances.id_actor_assigned is null)
				and activity_instances.date_completed is null
				and activity_instances.visible=1
				and process_instances.status='R'
		";
		$rs=$this->db->query($sql);
		try {
			$this->rsCheck($rs);
		}
		catch (Exception $e) {
			$msg=$e->getMessage();
			$this->doLog("$sql ( $msg )",APPcelerate::L_ERROR);
			throw new Exception("Query Error", 0);
		}

		return($this->fw->fetchAllAssoc($rs));
	}

	private function rsCheck($rs) {
		if ($rs) {
			return true;
		}

		$error=$this->db->error;

		throw new Exception($error, 0);


	}

	public function bpmeTBS($function,$params) {
		$this->doLog("Requested with function $function and params ".print_r($params,true),APPcelerate::L_DEBUG);
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

	public function getProcessInstanceGraph($id_process_instance,$type="R") {
		$graph = new Fhaculty\Graph\Graph();
		$graph->setAttribute("graphviz.graph.bgcolor","transparent");

		if ($type==="R") {
			$id_process=$this->getProcessIDFromProcessInstance($id_process_instance);
		}
		else {
			$id_process=$id_process_instance;
			$id_process_instance=0;
		}

		$sql="select * from processes where id=$id_process";
		$rs=$this->fw->app["db_programmi"]->query($sql);
		$this->fw->DBsqlError($rs,$sql);
		$process=$rs->fetch_array(MYSQLI_ASSOC);

		if ($id_process_instance!=0) {
			$sql="select * from process_instances where id=$id_process_instance";
			$rs=$this->fw->app["db_programmi"]->query($sql);
			try {
				$this->fw->DBsqlError($rs,$sql);
			}
			catch (Exception $e) {
				$msg=$e->getMessage();
				$this->doLog("Sql Error | $sql | $msg",APPcelerate::L_ERROR);
				throw new Exception("Query Error", 0);
			}
			$process_instance=$rs->fetch_array(MYSQLI_ASSOC);
		}

		$graph = new Fhaculty\Graph\Graph();
		$graph->setAttribute("graphviz.graph.bgcolor","transparent");
		$graph->setAttribute("graphviz.graph.labelloc","t");
		$graph->setAttribute("graphviz.graph.label",$process["code"]." - ".$process["name"]);

		$sql="select * from activities where id_process=$id_process";
		$rs=$this->fw->app["db_programmi"]->query($sql);
		try {
			$this->fw->DBsqlError($rs,$sql);
		}
		catch (Exception $e) {
			$msg=$e->getMessage();
			$this->doLog("Sql Error | $sql | $msg",APPcelerate::L_ERROR);
			throw new Exception("Query Error", 0);
		}
		while ($r=$rs->fetch_array(MYSQLI_ASSOC)) {

			$activity=$r;

			$label=$activity["code"]."\n".$activity["name"];

			$node[$activity["id"]]=$graph->createVertex($label);
			$node[$activity["id"]]->setAttribute("graphviz.fontsize","10");
			$node[$activity["id"]]->setAttribute("graphviz.style","filled");
			$node[$activity["id"]]->setAttribute("graphviz.splines","curved");

			switch($activity["activity_type"]) {
				case "S":
					$node[$activity["id"]]->setAttribute("graphviz.shape","proteinstab");
					break;
				case "F":
					$node[$activity["id"]]->setAttribute("graphviz.shape","proteasesite");
					break;
				case "A":
					$node[$activity["id"]]->setAttribute("graphviz.shape","component");
					break;
				case "C":
					$node[$activity["id"]]->setAttribute("graphviz.shape","signature");
					break;
				case "T":
					$node[$activity["id"]]->setAttribute("graphviz.shape","cds");
					break;
				case "U":
					$node[$activity["id"]]->setAttribute("graphviz.shape","box");
					break;
			}

			if($id_process_instance!=0) {
				$sql="select * from activity_instances where id_process_instance=$id_process_instance and id_activity=".$activity["id"]." order by date_created desc limit 1";
				$rs1=$this->fw->app["db_programmi"]->query($sql);
				try {
					$this->fw->DBsqlError($rs1,$sql);
				}
				catch (Exception $e) {
					$msg=$e->getMessage();
					$this->doLog("Sql Error | $sql | $msg",APPcelerate::L_ERROR);
					throw new Exception("Query Error", 0);
				}

				if($rs1->num_rows>0) {

					$activity_instance=$rs1->fetch_array(MYSQLI_ASSOC);

					$d=date_parse_from_format("Y-m-d H:i:s",$activity_instance["date_created"]);
					$v=$d["day"]."/".$d["month"]."/".$d["year"];
					$label.="\nStarted ".$v;

					$type=$this->getActorType($activity_instance["id_actor_created"]);

					if ($type==("U")) {
						$sql="select login from users where id=".$activity_instance["id_actor_created"];
					}
					else {
						$sql="select concat(nome,' ',cognome) from ospiti where id=(select id_ospite from partecipanti where id=".$activity_instance["id_actor_created"].")";
					}
					$rs1=$this->fw->app["db_programmi"]->query($sql);
					try {
						$this->fw->DBsqlError($rs1,$sql);
					}
					catch (Exception $e) {
						$msg=$e->getMessage();
						$this->doLog("Sql Error | $sql | $msg",APPcelerate::L_ERROR);
						throw new Exception("Query Error", 0);
					}
					$nome=$rs1->fetch_array(MYSQLI_NUM)[0];
					$label.=" by ".$nome;

					if(!empty($activity_instance["date_completed"])) {
						$d=date_parse_from_format("Y-m-d H:i:s",$activity_instance["date_completed"]);
						$v=$d["day"]."/".$d["month"]."/".$d["year"];
						$label.="\nCompleted ".$v;
						$node[$activity["id"]]->setAttribute("graphviz.fillcolor","grey");
					}
					else {
						$node[$activity["id"]]->setAttribute("graphviz.fillcolor","green");
					}
					$node[$activity["id"]]->setAttribute("graphviz.label","$label");
				}
				else {
					$node[$activity["id"]]->setAttribute("graphviz.fillcolor","white");
				}
			}
			else {
				$node[$activity["id"]]->setAttribute("graphviz.fillcolor","white");
			}

		}

		$sql="select * from actions where id_process=$id_process";
		$rs=$this->fw->app["db_programmi"]->query($sql);
		try {
			$this->fw->DBsqlError($rs1,$sql);
		}
		catch (Exception $e) {
			$msg=$e->getMessage();
			$this->doLog("Sql Error | $sql | $msg",APPcelerate::L_ERROR);
			throw new Exception("Query Error", 0);
		}
		while ($r=$rs->fetch_array(MYSQLI_ASSOC)) {

			$edge=$node[$r["id_activity_from"]]->createEdgeTo($node[$r["id_activity_to"]]);
			if (!empty($r["entry_condition"])) {
				$edge->setAttribute("graphviz.label",$r["entry_condition"]);
				$edge->setAttribute("graphviz.minlen","2");
			}

			$edge->setAttribute("graphviz.style","diagonals");

		}

		$graphviz = new Graphp\GraphViz\GraphViz();
		$graphviz->setFormat("png");
		$img=$graphviz->createImageSrc($graph);

		return($img);

	}

	public function engine($function,$params) {
		$this->doLog("Requested with function $function and params ".print_r($params,true),APPcelerate::L_DEBUG);

		switch($function) {
			case 'getCases':
				if (!array_key_exists("id",$params)) {
					throw new Exception("Missing 'id' params", 0);
				}

				$uid=$this->fw->app["uid"];
				return($this->getCases($uid));
				break;
			case 'showActivity':
				if (!array_key_exists("id",$params)) {
					throw new Exception("Missing 'id' params", 0);
				}
				return($this->showActivity($params["id"]));
				break;
			case 'testActivity':
				if (!array_key_exists("code",$params)) {
					throw new Exception("Missing 'code' params", 0);
				}
				if (!array_key_exists("process_data",$params)) {
					$process_data=array();
				}
				else {
					$process_data=$params["process_data"];
				}
				if (!array_key_exists("context",$params)) {
					$context="";
				}
				else {
					$context=$params["context"];
				}
				return($this->testActivity($params["code"],$process_data,$context));
				break;
			case 'followActions':
				if (!array_key_exists("id",$params)) {
					throw new Exception("Missing 'id' params", 0);
				}
				return($this->followActions($params["id"],true));
				break;
			case 'dispatchActivity':
				if (!array_key_exists("id",$params)) {
					throw new Exception("Missing 'id' params", 0);
				}
				return($this->dispatchActivity($params["id"],true));
				break;
			case 'startProcess':
				if (!array_key_exists("code",$params)) {
					throw new Exception("Missing 'code' params", 0);
				}
				if (!array_key_exists("start",$params)) {
					$params["start"]="MAIN";
				}
				if (!array_key_exists("data",$params)) {
					$params["data"]=array();
				}
				if (!array_key_exists("ui",$params)) {
					$params["ui"]=false;
				}
				$r=$this->startProcess($params["code"],$params["start"],$params["data"],$params["ui"]);
				return($r);
				break;
			case 'getProcessInstanceData':
				if (!array_key_exists("id",$params)) {
					throw new Exception("Missing 'id' params", 0);
				}
				return($this->getProcessInstanceData($params["id"],true,"array"));
				break;
			case 'setProcessInstanceData':
				if (!array_key_exists("id",$params)) {
					throw new Exception("Missing 'id' params", 0);
				}
				if (!array_key_exists("data",$params)) {
					throw new Exception("Missing 'data' params", 0);
				}
				return($this->setProcessInstanceData($params["id"],$params["data"]));
				break;
			case 'getProcessInstanceCounterpart':
				if (!array_key_exists("id",$params)) {
					throw new Exception("Missing 'id' params", 0);
				}
				return($this->getProcessInstanceCounterpart($params["id"]));
				break;
			case 'getProcessInstanceIDFromActivityInstanceID':
				if (!array_key_exists("id",$params)) {
					throw new Exception("Missing 'id' params", 0);
				}
				return($this->getProcessInstanceIDFromActivityInstanceID($params["id"]));
				break;
			case 'setProcessInstanceCounterpart':
				if (!array_key_exists("id_process_instance",$params)) {
					throw new Exception("Missing 'id_process_instance' params", 0);
				}
				if (!array_key_exists("id_actor",$params)) {
					throw new Exception("Missing 'id_actor' params", 0);
				}
				$this->setProcessInstanceCounterpart($params["id_process_instance"],$params["id_actor"]);
				break;
			case 'getAlerts':
				$alerts=$this->getAlerts();
				return($alerts);
				break;
			case 'clearAlert':
				$this->clearAlert($params["id"]);
				break;
			case 'getLastActivities':
				$last=$this->getLastActivities();
				return($last);
				break;
			case 'isActivityInstanceOpen':
				if (!array_key_exists("id",$params)) {
					throw new Exception("Missing 'id' params", 0);
				}
				if($this->isActivityInstanceOpen($params["id"])) {
					return (true);
				}
				else {
					return (false);
				}
				break;
			default:
				throw new Exception("Function $function not present");
		}
	}

}
?>
