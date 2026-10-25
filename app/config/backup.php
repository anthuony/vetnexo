<?php
function av_backup_sql(PDO $db,string $path): void {
    $fh=fopen($path,'wb');if(!$fh)throw new RuntimeException('No se pudo escribir el respaldo.');
    try{
        fwrite($fh,"-- Respaldo VetNexo ".date('c')."\nSET FOREIGN_KEY_CHECKS=0;\nSET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\nSET NAMES utf8mb4;\n");
        foreach($db->query("SHOW FULL TABLES WHERE Table_type='BASE TABLE'")->fetchAll(PDO::FETCH_NUM) as $r){
            $table='`'.str_replace('`','``',$r[0]).'`';$create=$db->query('SHOW CREATE TABLE '.$table)->fetch(PDO::FETCH_NUM)[1];fwrite($fh,"DROP TABLE IF EXISTS $table;\n$create;\n");
            foreach($db->query('SELECT * FROM '.$table) as $row){$cols=array_map(fn($k)=>'`'.str_replace('`','``',$k).'`',array_keys($row));$vals=array_map(fn($v)=>$v===null?'NULL':$db->quote((string)$v),array_values($row));fwrite($fh,'INSERT INTO '.$table.' ('.implode(',',$cols).') VALUES ('.implode(',',$vals).");\n");}
        }
        fwrite($fh,"SET FOREIGN_KEY_CHECKS=1;\n");
    }finally{fclose($fh);}
}

function av_execute_sql(PDO $db,string $sql): void {
    // Consume todos los resultados para detectar errores incluso al final del SQL.
    $statement=$db->query($sql);
    if($statement){do{if($statement->columnCount())$statement->fetchAll();}while($statement->nextRowset());$statement->closeCursor();}
}
