<?php

class MysqlDiff
{
    public $conn = [];
    
    public $error_add_tables = [];
    public $success_add_tables = [];
    public $create_table_sqls = [];
    public $error_repair_tables = [];
    public $success_repair_tables = [];
    public $repair_fields = [];

    public function __construct(array $conf)
    {
        $dbms = 'mysql';

        $master = $conf['master'];
        $slave = $conf['slave'];

        $dsn = sprintf("mysql:host=%s;dbname=%s", $master['host'], $master['db']);
        $dbh_conn_master = new PDO($dsn, $master['user'], $master['pwd']);

        $dsn = sprintf("mysql:host=%s;dbname=%s", $slave['host'], $slave['db']);
        $dbh_conn_slave = new PDO($dsn, $slave['user'], $slave['pwd']);

        $this->conn['master'] = $dbh_conn_master;
        $this->conn['slave'] = $dbh_conn_slave;
    }

    public function listTables()
    {
        $sql = 'SHOW TABLES;';
        $query_master = $this->conn['master']->query($sql);
        $query_slave = $this->conn['slave']->query($sql);
        $query_master = $query_master->fetchAll(PDO::FETCH_COLUMN);
        $query_slave = $query_slave->fetchAll(PDO::FETCH_COLUMN);
        return [$query_master, $query_slave];
    }

    public function getCreateTableSql($table)
    {
        $sql = 'SHOW CREATE TABLE ' . $table . ';';
        $query = $this->conn['master']->query($sql);
        $row = $query->fetch(PDO::FETCH_ASSOC);
        $this->create_table_sqls[$table] = $row['Create Table'];
        return $this->create_table_sqls[$table];
    }

    public function addTables($table)
    {
        $_sql = $this->getCreateTableSql($table);
        $ret = $this->conn['slave']->exec($_sql);
        if ($ret !== 0) {
            $this->error_add_tables[] = $table;
        }
        $this->success_add_tables[] = $table;
    }

    public function repairTable($table)
    {
        $this->getCreateTableSql($table);

        $_sql = 'DESC ' . $table;

        $stmt = $this->conn['master']->prepare($_sql);  
        $stmt->execute();
        $master_table_fields = $stmt->fetchAll();
        $master_table_fields = array_column($master_table_fields, 'Field');

        $stmt = $this->conn['slave']->prepare($_sql);  
        $stmt->execute();  
        $slave_table_fields = $stmt->fetchAll();
        $slave_table_fields = array_column($slave_table_fields, 'Field');

        foreach ($master_table_fields as $field) {
            if (!in_array($field, $slave_table_fields)) {
                $_str = $this->create_table_sqls[$table];
                $pattern = sprintf('/`%s`.*?(?=,\s|\s\))/s', $field);
                preg_match($pattern, $_str, $matchs);

                $tmp = $matchs[0] . ';';
                $repair_sql = sprintf('ALTER TABLE %s ADD %s', $table, $tmp);

                $ret = $this->conn['slave']->exec($repair_sql);
                if ($ret !== 0) {
                    $this->error_repair_tables[] = $table;
                }
                $this->success_repair_tables[] = $table;
            }
        }
    }

    public function run()
    {
        list($master_tables, $slave_tables) = $this->listTables();

        foreach ($master_tables as $table) {
            if (!in_array($table, $slave_tables)) {
                $this->addTables($table);
            }
            else
            {
                $this->repairTable($table);
            }
        }
    }

    public function getAddTables()
    {
        return [array_unique($this->success_add_tables), array_unique($this->error_add_tables)];
    }

    public function getRepairTables()
    {
        return [array_unique($this->success_repair_tables), array_unique($this->error_repair_tables)];
    }

}

$conf = require dirname(__FILE__) . '/config.php';

$md = new MysqlDiff($conf);
$md->run();

list($success_add_tables, $error_add_tables) = $md->getAddTables();
list($success_repair_tables, $error_repair_tables) = $md->getRepairTables();

echo sprintf("Success add tables:\t%s\n", implode(',', $success_add_tables));
echo sprintf("Error add tables:\t%s\n", implode(',', $error_add_tables));

echo sprintf("Success repair tables:\t%s\n", implode(',', $success_repair_tables));
echo sprintf("Error repair tables:\t%s\n", implode(',', $error_repair_tables));