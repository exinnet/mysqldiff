<?php
$host_master = "";
$user_master = "";
$pwd_master = "";
$conn_master = mysql_connect("$host_master","$user_master","$pwd_master");
mysql_select_db("$db_master", $conn_master);


$host_slave = "";
$user_slave = "";
$pwd_slave = "";
$db_slave = "";
$conn_slave = mysql_connect("$host_slave","$user_slave","$pwd_slave");
mysql_select_db("$db_slave", $conn_slave);


$res_master = mysql_query("show tables", $conn_master);
$tables_master = array();
while ($row = mysql_fetch_array($res_master, MYSQL_ASSOC)) {
    $tables_master[$row["Tables_in_products_center_v1"]] = 1;
}
 
$res_slave = mysql_query("show tables", $conn_slave);
$tables_slave = array();
while ($row = mysql_fetch_array($res_slave, MYSQL_ASSOC)) {
    $tables_slave[$row["Tables_in_products_center_v1"]] = 1;
}

$error_add_tables = array();
$error_repair_tables = array();
foreach ($tables_master as $k => $v) {
	if (!isset($tables_slave[$k])) {
		add_table($k);
	} else {
		repair_table($k);
	}
}

function add_table($table) {
	global $conn_master, $conn_slave, $error_add_tables;
	$res = mysql_query("show create table $table", $conn_master);
	$row = mysql_fetch_array($res, MYSQL_ASSOC);
	$res = mysql_query($row["Create Table"], $conn_slave);
	if ($res) {
		echo "add table [$table][success]\n";
	} else {
		echo "add table [$table][fail]";
		$error_add_tables[$table] = "fail to add table";
	}
}

function repair_table($table) {
	global $conn_master, $conn_slave, $error_repair_tables;
	$res_slave = mysql_query("select COLUMN_NAME from information_schema.COLUMNS where table_name = '$table'", $conn_slave);
	$column_slave = array();
	while($row = mysql_fetch_array($res_slave, MYSQL_ASSOC))
	{
		$column_slave[$row["COLUMN_NAME"]] = 1;
	}
	$res_master = mysql_query("select COLUMN_NAME from information_schema.COLUMNS where table_name = '$table'", $conn_master);
	while($row = mysql_fetch_array($res_master, MYSQL_ASSOC))
	{
		$column = $row["COLUMN_NAME"];
		$res = true;
		if (!isset($column_slave[$column])) {
			$repair_sql = get_repair_sql($table, $column);
			$res = mysql_query($repair_sql, $conn_slave);
		}
		if ($res == false) {
			$error_repair_tables[$table][] = $column;
			echo "table $table add column [$column] [fail]\n";
		} else {
			echo "table $table add column [$column] [success]\n";
		}
	}
}

function get_repair_sql($table, $column) {
	global $conn_master;
	$res = mysql_query("show create table $table", $conn_master);
	$row = mysql_fetch_array($res, MYSQL_ASSOC);
	$pattern = "/`$column`(.*?),\n/s";
	preg_match($pattern, $row["Create Table"], $matchs);
	$tmp = trim($matchs[0]);
	$offset = strlen($tmp) - 1;
	$tmp[$offset] = ";";
	$sql = "alter table $table add ".$tmp;
	return $sql;
}
?>
