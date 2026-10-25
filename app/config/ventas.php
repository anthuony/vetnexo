<?php
require_once __DIR__.'/database.php';
function av_money($value): float {
    if(!is_scalar($value)||!is_numeric($value)||!is_finite((float)$value)||(float)$value<0||(float)$value>99999999) throw new RuntimeException('Importe no válido.');
    return round((float)$value,2);
}
function av_stock(PDO $db,int $product,int $delta,string $reason,int $user,?int $presentation=null): void {
    if(!$db->inTransaction()) throw new LogicException('El inventario requiere una transacción.');
    $q=$db->prepare('SELECT * FROM productos WHERE id=? FOR UPDATE');$q->execute([$product]);$p=$q->fetch();
    if(!$p)throw new RuntimeException('Producto no encontrado.');
    $old=(int)$p['stock_actual'];
    if($presentation){$q=$db->prepare('SELECT stock_actual FROM medicamento_presentaciones WHERE id=? AND medicamento_id=? FOR UPDATE');$q->execute([$presentation,$product]);$v=$q->fetchColumn();if($v===false)throw new RuntimeException('Presentación no válida.');$old=(int)$v;}
    $new=$old+$delta;if($new<0)throw new RuntimeException('Stock insuficiente para '.$p['nombre'].'. Disponible: '.$old);
    if($presentation)$db->prepare('UPDATE medicamento_presentaciones SET stock_actual=? WHERE id=?')->execute([$new,$presentation]);
    else $db->prepare('UPDATE productos SET stock_actual=? WHERE id=?')->execute([$new,$product]);
    if($delta)$db->prepare('INSERT INTO movimientos_inventario(producto_id,presentacion_id,tipo,cantidad,cantidad_anterior,cantidad_nueva,motivo,usuario_id) VALUES(?,?,?,?,?,?,?,?)')->execute([$product,$presentation,$delta>0?'entrada':'salida',abs($delta),$old,$new,mb_substr($reason,0,200),$user]);
}
function av_restore_invoice_stock(PDO $db,int $id,int $user): void {
    $q=$db->prepare("SELECT producto_id,presentacion_id,SUM(cantidad) AS cantidad FROM factura_detalles WHERE factura_id=? AND tipo_item='producto' GROUP BY producto_id,presentacion_id ORDER BY producto_id,presentacion_id");$q->execute([$id]);
    foreach($q->fetchAll() as $r)av_stock($db,(int)$r['producto_id'],(int)$r['cantidad'],'Reverso factura #'.$id,$user,$r['presentacion_id']?(int)$r['presentacion_id']:null);
}
function av_invoice_items(PDO $db,array $items): array {
    if(!$items||count($items)>200)throw new RuntimeException('Agregue entre 1 y 200 productos o servicios.');
    $out=[];$sub=0;$tax=0;
    foreach($items as $item){
        $type=$item['tipo_item']??'';$id=(int)($item['item_id']??0);
        if(!in_array($type,['producto','servicio'],true)||$id<1)throw new RuntimeException('Seleccione un producto o servicio válido de la búsqueda.');
        $qty=filter_var($item['cantidad']??null,FILTER_VALIDATE_INT);if(!$qty||$qty<1||$qty>100000)throw new RuntimeException('Cantidad no válida.');
        $q=$db->prepare('SELECT * FROM '.($type==='producto'?'productos':'servicios').' WHERE id=? AND activo=1');$q->execute([$id]);$p=$q->fetch();if(!$p)throw new RuntimeException('El producto o servicio ya no está disponible.');
        $presentation=null;$description=$p['nombre'];
        if($type==='producto'&&!empty($p['es_medicamento_con_presentaciones'])){
            $presentation=(int)($item['presentacion_id']??0);$q=$db->prepare('SELECT * FROM medicamento_presentaciones WHERE id=? AND medicamento_id=? AND activo=1');$q->execute([$presentation,$id]);$mp=$q->fetch();if(!$mp)throw new RuntimeException('Seleccione la presentación del medicamento.');$description.=' · '.$mp['nombre_presentacion'];
        }
        $price=av_money($item['precio_unitario']??0);$discount=av_money($item['descuento']??0);$gross=round($qty*$price,2);if($discount>$gross)throw new RuntimeException('El descuento supera el importe.');
        $net=round($gross-$discount,2);$iva=$type==='producto'?(int)$p['aplica_iva']:(!empty($item['aplica_iva'])?1:0);
        $rate=av_money($type==='producto'?$p['porcentaje_iva']:($item['porcentaje_iva']??0));if($rate>100)throw new RuntimeException('Porcentaje de IVA no válido.');$amount=$iva?round($net*$rate/100,2):0;
        $out[]=['type'=>$type,'id'=>$id,'presentation'=>$presentation,'description'=>$description,'qty'=>$qty,'price'=>$price,'discount'=>$discount,'net'=>$net,'iva'=>$iva,'rate'=>$rate,'tax'=>$amount];$sub+=$net;$tax+=$amount;
    }
    return [$out,round($sub,2),round($tax,2)];
}
function av_save_invoice(PDO $db,array $data,int $user,?int $invoiceId=null): int {
    $db->beginTransaction();
    try {
        if($invoiceId){
            $q=$db->prepare('SELECT * FROM facturas WHERE id=? FOR UPDATE');$q->execute([$invoiceId]);$old=$q->fetch();
            if(!$old||$old['estado']!=='pending'||(float)$old['pagado']>0)throw new RuntimeException('Solo se pueden editar facturas pendientes sin pagos.');
            $q=$db->prepare('SELECT COUNT(*) FROM pedidos WHERE factura_id=?');$q->execute([$invoiceId]);if($q->fetchColumn())throw new RuntimeException('Esta factura se administra desde su pedido.');
        }
        $name=trim($data['cliente_nombre']??'');$doc=trim($data['cliente_identificacion']??'');$date=$data['fecha_emision']??date('Y-m-d');
        if($name===''||$doc===''||!av_valid_date($date))throw new RuntimeException('Indique nombre, documento y fecha válidos.');
        [$items,$sub,$tax]=av_invoice_items($db,$data['items']??[]);$total=round($sub+$tax,2);if($total>99999999)throw new RuntimeException('Total fuera de rango.');
        $q=$db->prepare('SELECT id FROM clientes WHERE documento_identidad=? LIMIT 1');$q->execute([$doc]);$client=$q->fetchColumn();
        if(!$client){$db->prepare('INSERT INTO clientes(nombre,documento_identidad,telefono,direccion) VALUES(?,?,?,?)')->execute([$name,$doc,$data['cliente_telefono']??'',$data['cliente_direccion']??'']);$client=$db->lastInsertId();}
        $method=match($data['forma_pago']??'CONTADO'){'CONTADO','DIVISAS'=>'cash','PAGO_MOVIL','TRANSFERENCIA'=>'transfer','TARJETA_DEBITO','TARJETA_CREDITO'=>'card',default=>throw new RuntimeException('Método de pago no válido.')};
        if($invoiceId){
            if($old['inventario_descontado'])av_restore_invoice_stock($db,$invoiceId,$user);
            $db->prepare('DELETE FROM factura_detalles WHERE factura_id=?')->execute([$invoiceId]);
            $db->prepare('UPDATE facturas SET cliente_id=?,fecha_emision=?,subtotal=?,impuestos=?,total=?,metodo_pago=?,notas=?,inventario_descontado=1 WHERE id=?')->execute([$client,$date,$sub,$tax,$total,$method,$data['observaciones']??'',$invoiceId]);
        }else{
            $db->exec("INSERT IGNORE INTO secuencias_app(nombre,valor) VALUES('factura',0)");$q=$db->query("SELECT valor FROM secuencias_app WHERE nombre='factura' FOR UPDATE");$seq=(int)$q->fetchColumn();$max=(int)$db->query("SELECT COALESCE(MAX(CAST(numero_factura AS UNSIGNED)),0) FROM facturas WHERE numero_factura REGEXP '^[0-9]+$'")->fetchColumn();$seq=max($max,$seq)+1;
            $db->prepare("UPDATE secuencias_app SET valor=? WHERE nombre='factura'")->execute([$seq]);$number=str_pad((string)$seq,6,'0',STR_PAD_LEFT);
            $db->prepare("INSERT INTO facturas(numero_factura,cliente_id,fecha_emision,fecha_vencimiento,subtotal,impuestos,total,estado,metodo_pago,notas,usuario_id,inventario_descontado) VALUES(?,?,?,DATE_ADD(?,INTERVAL 30 DAY),?,?,?,'pending',?,?,?,1)")->execute([$number,$client,$date,$date,$sub,$tax,$total,$method,$data['observaciones']??'',$user]);$invoiceId=(int)$db->lastInsertId();
        }
        usort($items,fn($a,$b)=>[$a['id'],$a['presentation']]<=>[$b['id'],$b['presentation']]);
        $q=$db->prepare('INSERT INTO factura_detalles(factura_id,tipo_item,servicio_id,producto_id,presentacion_id,descripcion,cantidad,precio_unitario,descuento,subtotal,aplica_iva,porcentaje_iva,iva_item) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)');
        foreach($items as $i){if($i['type']==='producto')av_stock($db,$i['id'],-$i['qty'],'Factura #'.$invoiceId,$user,$i['presentation']);$q->execute([$invoiceId,$i['type'],$i['type']==='servicio'?$i['id']:null,$i['type']==='producto'?$i['id']:null,$i['presentation'],$i['description'],$i['qty'],$i['price'],$i['discount'],$i['net'],$i['iva'],$i['rate'],$i['tax']]);}
        $db->commit();return $invoiceId;
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}
function av_cart_totals(PDO $db): array {
    $sub=0;$tax=0;
    foreach($_SESSION['carrito']??[] as $id=>$item){$q=$db->prepare('SELECT * FROM productos WHERE id=? AND activo=1');$q->execute([$id]);$p=$q->fetch();$qty=(int)($item['cantidad']??0);if(!$p||$qty<1){unset($_SESSION['carrito'][$id]);continue;}$net=round((float)$p['precio_venta']*$qty,2);$sub+=$net;$tax+=!empty($p['aplica_iva'])?round($net*(float)$p['porcentaje_iva']/100,2):0;}
    return ['subtotal'=>round($sub,2),'iva'=>round($tax,2),'total'=>round($sub+$tax,2)];
}
