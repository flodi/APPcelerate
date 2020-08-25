<?
global $page_title, $page_main, $TBS, $app, $id_evento, $id_attivita, $id_ospite;
$page_title="DW Home";
$page_main="Nessun Evento Assegnato";

$TBS->LoadTemplate($app["templates_base_path"]."dw/home.htm","+");

$sql="select code from products";
$rs=$app["db".$app["name"]]->query($sql);
sqlError($rs,$sql);
$codes=fetchAllAssoc($rs);

$app["tail_blocks"]=array("bCodes" => $codes)

?>