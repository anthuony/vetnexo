<?php
require_once __DIR__ . '/../config/bootstrap.php';
require_once '../config/database.php';
require_once '../config/bcv.php';
$db = getDBConnection();
$rows = $db->query("SELECT p.id,p.nombre,p.precio_venta,p.stock_actual,c.nombre categoria FROM productos p LEFT JOIN categorias_productos c ON c.id=p.categoria_id WHERE p.activo=1 ORDER BY p.fecha_creacion DESC,p.id DESC LIMIT 30")->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html><html lang="es"><head>
<?php if(function_exists('av_head')) av_head(); ?><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Nuevos productos</title><style>body{font-family:Arial;background:#f6f8f6;color:#25352d}.w{max-width:1050px;margin:34px auto;padding:18px}.g{display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:15px}.c{background:#fff;padding:19px;border-radius:14px;box-shadow:0 5px 20px #0001}.p{font-size:22px;font-weight:bold;color:#0f6c33}.bs{font-size:14px;color:#64748b;margin-top:2px}.c a,.top{color:#0f6c33;text-decoration:none;font-weight:bold}</style><link rel="stylesheet" href="../css/app-optimized.css?v=20260812c"></head><body><div class="w"><a class="top" href="index.php">← Tienda</a><h1>Productos recientes</h1><div class="g"><?php foreach($rows as $r):?><article class="c"><small><?=htmlspecialchars($r['categoria']??'Producto')?></small><h2><?=htmlspecialchars($r['nombre'])?></h2><div class="p">$<?=number_format((float)$r['precio_venta'],2)?> USD</div><div class="bs"><?=htmlspecialchars(bcv_format_bs($r['precio_venta']))?></div><p>Stock: <?=max(0,(int)$r['stock_actual'])?></p><a href="producto.php?id=<?=$r['id']?>">Ver producto →</a></article><?php endforeach;?></div></div><?php echo bcv_badge_html(); ?>
</body></html>