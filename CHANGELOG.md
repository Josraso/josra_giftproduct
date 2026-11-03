# Changelog — josra_giftproduct

## 2.0.0 — 2026-11-03

Reescritura de la base. Al actualizar desde 1.x se migran reglas, tramos y los
regalos que ya haya en carritos abiertos (no se pasan a cobrar).

### Corregido
- En 1.7, 8 y 9 se usaban hooks del carrito que no existen (`actionCartUpdateQuantity`,
  `actionAfterDeleteProductInCart`): ahora `actionCartSave` y `actionObjectProductInCartDeleteAfter`.
- El regalo se guardaba en la cookie del cliente: con TPV que validan por notificación
  (Redsys, Bizum, PayPal) no se registraba el regalo, no había estadísticas ni límite de unidades.
  Ahora el estado va en BD por carrito (`josra_gift_cart`).
- Al quitar el regalo volvía a aparecer solo. Ahora queda rechazado hasta "Recuperar regalo".
- Log `josra_gift.log` público en la carpeta del módulo: eliminado; solo con modo depuración y en `var/logs`.
- Al desinstalar, los carritos abiertos seguían con el regalo a 0 €. Ahora se borran sus precios.
- Se borraban precios específicos de carrito que no eran del módulo (p. ej. precio manual en pedido del BO).
- La ficha del pedido del BO no mostraba el regalo en ninguna versión (hooks inexistentes).
- "Compra 2 y llévate 1" del mismo producto no contaba bien las unidades.
- Bajar la cantidad de un regalo de varias unidades lo duplicaba.
- JS escuchaba `updateCart` como evento del navegador: en 1.7+ se usa `prestashop.on()` y no se recarga la página.
- Admin solo usable por SuperAdmin (controlador sin tab): todo pasa por `getContent()`.
- Actualización: faltaba `upgrade-1.1.0.php`, `ADD COLUMN IF NOT EXISTS` fallaba en MySQL, `: bool` rompía PHP 5.6.
- Devoluciones restaban usos al *solicitarlas*; ahora cancelado/reembolsado/error devuelve unidades y volver a un estado válido las descuenta.
- Valor regalado no multiplicaba por unidades; stock comprobado con las unidades a regalar.
- Mensaje de reglas por cantidad decía "Te faltan 3,00 €"; importes de tramos se veían como `200,000000`.
- Ajustes globales de IVA/envío que no se usaban: eliminados (van en cada regla).

### Nuevo
- Columna **Estado** en la lista de reglas y avisos al editar: dice por qué una regla no daría regalo
  (stock insuficiente para las unidades a regalar, producto desactivado, caducada, sin unidades, combinación...).
- Con el modo depuración, el log explica regla a regla por qué no se aplica (grupo, zona, umbral, stock).
- Reglas antiguas con producto con combinaciones guardado sin combinación: se usa la predeterminada.
- Admin con componentes nativos: HelperList (reglas), HelperForm (regla y ajustes), switches, colores y textos multiidioma nativos.
- Grupos y zonas con casillas; duplicar regla; "guardar y seguir editando".
- Columna "Regalo" en el listado de pedidos y panel en la ficha del pedido.
- La etiqueta se añade al nombre de la línea del pedido (BO, historial, factura, FacturaScripts). Desactivable.
- Bloque del carrito con regalo actual, `{amount}` y `{gift}`, barra de progreso.
- Avisos: regalo conseguido, regalo mejorado y regalo agotado (se da el del tramo anterior).
- Umbrales en moneda por defecto con conversión para otras monedas.
- Precio a cero en porcentaje: no depende del IVA ni de la dirección del cliente.
- Estadísticas: pedidos, unidades, valor, regalo más entregado, últimos regalos con enlace y aviso de stock bajo.

## 1.1.0
- Reglas por producto en el carrito, unidades a regalar por tramo, unidades extra cobradas (Claude Code).

## 1.0.0
- Primera versión.
