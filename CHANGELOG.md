# Changelog — josra_giftproduct

## 2.2.0 — 2026-11-26
- Nuevo ajuste «Cómo sale el regalo» en Ajustes generales:
  - «A precio 0 (como siempre)»: viene así por defecto y no cambia nada.
  - «A su precio, con un vale que descuenta su importe»: el regalo va en el carrito, el pedido y la factura a su precio real, y debajo sale un descuento nativo de PrestaShop «REGALO: producto» por el mismo importe y con el IVA (y el recargo) del producto. El total del pedido es el mismo. Para que en la factura no salga ninguna línea a 0 €.
- El vale es un vale normal (Catálogo > Descuentos), con código secreto y solo para ese carrito. El pedido se importa a FacturaScripts como cualquier pedido con descuento.
- Con el vale, el regalo sigue sin contar para llegar al tramo (como cuando va a 0 €), también con «restar cupones y descuentos».
- Si el cliente quita el vale en el carrito, vuelve a salir solo. Si quita el regalo, se va también su vale.
- Sirve para los regalos automáticos y para los que elige el cliente. Al cambiar el ajuste, los carritos abiertos se pasan solos al modo nuevo.
- Los vales de carritos abandonados se borran solos. Los que ya están en un pedido se quedan, porque son parte de ese pedido.

## 2.1.0 — 2026-11-24
- Nuevo tipo de regla «Importe del carrito: el cliente elige el regalo». Los tramos se hacen poniendo el mismo «Desde» en varias filas: cada fila es un regalo que se puede elegir en ese tramo.
- En cada regalo con combinaciones marcas cuáles puede elegir el cliente (ninguna = todas). Solo salen las que tienen stock.
- Opciones de la regla:
  - un regalo por pedido o uno por cada tramo alcanzado;
  - si puede elegir también los regalos de los tramos de abajo;
  - restar o no los cupones y descuentos del importe (también para las reglas por importe de siempre).
- En el carrito (y en el resumen de josracheckout 1.7.0) sale el bloque «Elige tu regalo», pensado primero para el móvil:
  - tarjetas pequeñas que se deslizan con el dedo;
  - la elegida marcada, con un desplegable para la combinación;
  - el siguiente tramo en gris con candado y barra de progreso: «Añade X € más y podrás elegir uno de estos».
- Si el carrito baja de tramo o el regalo se agota, se quita solo y se avisa. Si el cliente borra la línea del regalo, puede volver a elegir.
- Los regalos elegidos llevan la etiqueta REGALO en el carrito y en el pedido, cuentan en las estadísticas y en «Regalos disponibles» (por pedidos), y la ficha del pedido muestra todos los regalos del pedido.
- Restricciones por grupos, zonas, fechas y prioridad, como en el resto de reglas.

## 2.0.3
- Licencia: el cartel con el botón «Ya tengo la licencia» sale desde la primera vez que se abre la configuración, y el aviso del back-office explica dónde pulsar.

## 2.0.2
- La licencia la decide el panel del servidor de actualizaciones: si se exige y la tienda no la tiene, el módulo se desactiva solo; al tenerla, o al dejar de exigirse, vuelve a activarse.

## 2.0.1

- Conectado al servidor de actualizaciones de josra: las versiones nuevas se instalan desde la configuración del módulo (y en PS 8.1+ desde el gestor de módulos).

## 2.0.0 — 2026-11-03

Reescritura de la base. Al actualizar desde 1.x se migran reglas, tramos y los
regalos que ya haya en carritos abiertos (no se pasan a cobrar).

### Corregido
- "Regalos disponibles" se vuelve a contar por pedidos, como en 1.x. La 2.0 lo contaba por unidades y
  las reglas actualizadas (que guardaban pedidos) se daban por agotadas antes de tiempo.
- Regalo de un producto con combinaciones guardado sin combinación: el precio a cero se aplicaba a todas
  las combinaciones, también a las que compraba el cliente. Ahora se usa la combinación por defecto y nunca
  se crea un precio a cero sin combinación en esos productos.
- Casilla de unidades de los tramos demasiado estrecha (no se veía el número).
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
- Devoluciones restaban usos al *solicitarlas*; ahora cancelado/reembolsado/error devuelve el regalo a la regla y volver a un estado válido lo descuenta.
- Valor regalado no multiplicaba por unidades; stock comprobado con las unidades a regalar.
- Mensaje de reglas por cantidad decía "Te faltan 3,00 €"; importes de tramos se veían como `200,000000`.
- Ajustes globales de IVA/envío que no se usaban: eliminados (van en cada regla).

### Nuevo
- Compatible con checkouts de módulos (josracheckout 1.2.1+): etiqueta por el hook nativo de la línea,
  repintado tras su AJAX y avisos que ya no se pierden en sus peticiones.
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
