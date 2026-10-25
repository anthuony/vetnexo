<?php
require_once __DIR__.'/ventas.php';
function av_order_stock(PDO $db,array $order,int $user): void {
    if(!empty($order['stock_reservado']))return;
    $q=$db->prepare('SELECT producto_id,cantidad FROM pedido_detalles WHERE pedido_id=? ORDER BY producto_id');$q->execute([$order['id']]);
    foreach($q->fetchAll() as $d)av_stock($db,(int)$d['producto_id'],-(int)$d['cantidad'],'Reserva pedido #'.$order['numero_pedido'],$user);
    $db->prepare('UPDATE pedidos SET stock_reservado=1 WHERE id=?')->execute([$order['id']]);
}
function av_order_invoice(PDO $db,array $order,int $user): int {
    if(!empty($order['factura_id']))return (int)$order['factura_id'];
    $method=$order['metodo_pago']==='efectivo'?'cash':($order['metodo_pago']==='tarjeta'?'card':'transfer');
    $db->prepare("INSERT INTO facturas(numero_factura,cliente_id,fecha_emision,fecha_vencimiento,subtotal,impuestos,total,pagado,estado,metodo_pago,fecha_pago,notas,usuario_id,inventario_descontado) VALUES(?,?,NOW(),CURDATE(),?,?,?,?,'paid',?,NOW(),?,?,1)")->execute(['PED-'.$order['numero_pedido'],$order['cliente_id'],$order['subtotal'],$order['impuestos'],$order['total'],$order['total'],$method,'Pedido #'.$order['numero_pedido'],$user]);
    $id=(int)$db->lastInsertId();$q=$db->prepare('SELECT d.*,p.nombre FROM pedido_detalles d JOIN productos p ON p.id=d.producto_id WHERE d.pedido_id=?');$q->execute([$order['id']]);$rows=$q->fetchAll();$taxUsed=0;
    foreach($rows as $k=>$d){$tax=$d['iva_item']!==null?(float)$d['iva_item']:round((float)$order['impuestos']*(float)$d['subtotal']/max(0.01,(float)$order['subtotal']),2);if($k===count($rows)-1)$tax=round((float)$order['impuestos']-$taxUsed,2);$taxUsed+=$tax;$rate=$d['porcentaje_iva']??($d['subtotal']>0?round($tax/$d['subtotal']*100,2):0);
        $db->prepare("INSERT INTO factura_detalles(factura_id,tipo_item,producto_id,descripcion,cantidad,precio_unitario,descuento,subtotal,aplica_iva,porcentaje_iva,iva_item) VALUES(?,'producto',?,?,?,?,0,?,?,?,?)")->execute([$id,$d['producto_id'],$d['nombre'],$d['cantidad'],$d['precio_unitario'],$d['subtotal'],$tax>0?1:0,$rate,$tax]);
    }
    $db->prepare('INSERT INTO pagos_facturas(factura_id,monto,metodo_pago,fecha_pago,notas,usuario_id) VALUES(?,?,?,CURDATE(),?,?)')->execute([$id,$order['total'],$method,'Pago de pedido #'.$order['numero_pedido'],$user]);
    $db->prepare('UPDATE pedidos SET factura_id=? WHERE id=?')->execute([$id,$order['id']]);return $id;
}
function av_order_log(PDO $db,array $o,int $user,string $action,string $message): void {
    $db->prepare('INSERT INTO logs_verificacion_pagos(pedido_id,usuario_id,accion,observaciones) VALUES(?,?,?,?)')->execute([$o['id'],$user,$action,$message]);
    $db->prepare("INSERT INTO notificaciones_cliente(cliente_id,tipo,titulo,mensaje) VALUES(?,'pedido_estado',?,?)")->execute([$o['cliente_id'],'Pedido #'.$o['numero_pedido'],$message]);
}
function av_verify_payment(PDO $db,int $id,int $user,string $action,string $reason=''): void {
    if(!in_array($action,['verificar','rechazar'],true))throw new RuntimeException('Acción no válida.');
    $db->beginTransaction();try{
        $q=$db->prepare('SELECT pedido_id FROM pagos WHERE id=?');$q->execute([$id]);$orderId=$q->fetchColumn();
        $q=$db->prepare('SELECT * FROM pedidos WHERE id=? FOR UPDATE');$q->execute([$orderId]);$o=$q->fetch();
        $q=$db->prepare('SELECT * FROM pagos WHERE id=? FOR UPDATE');$q->execute([$id]);$p=$q->fetch();
        if(!$o||!$p||$p['estado']!=='pendiente'||!in_array($o['estado'],['pendiente_pago','pendiente_verificacion','pendiente'],true)||(int)$o['pago_id']!==$id)throw new RuntimeException('El pago ya fue procesado o no corresponde al pedido pendiente.');
        if($action==='verificar'){
            if(abs((float)$p['monto']-(float)$o['total'])>0.005)throw new RuntimeException('El importe no coincide con el total del pedido en USD.');
            if($p['metodo_pago']!=='efectivo'&&empty($p['comprobante_path']))throw new RuntimeException('El cliente debe adjuntar su comprobante antes de verificar.');
            av_order_stock($db,$o,$user);av_order_invoice($db,$o,$user);
            $db->prepare("UPDATE pagos SET estado='verificado',fecha_verificacion=NOW(),verificado_por=? WHERE id=?")->execute([$user,$id]);
            $db->prepare("UPDATE pedidos SET estado='pagado_verificado',fecha_verificacion_pago=NOW(),verificado_por=? WHERE id=?")->execute([$user,$o['id']]);
            av_order_log($db,$o,$user,'pago_verificado','Pago verificado. El pedido pasará a preparación.');
        }else{
            if(trim($reason)==='')throw new RuntimeException('Especifique el motivo de rechazo.');
            $db->prepare("UPDATE pagos SET estado='rechazado',motivo_rechazo=?,fecha_verificacion=NOW(),verificado_por=? WHERE id=?")->execute([$reason,$user,$id]);$db->prepare("UPDATE pedidos SET estado='pendiente_pago' WHERE id=?")->execute([$o['id']]);av_order_log($db,$o,$user,'pago_rechazado','Pago rechazado: '.$reason);
        }
        $db->commit();
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}
