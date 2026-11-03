# josra_giftproduct — Producto de regalo automático

Módulo para PrestaShop **1.6, 1.7, 8 y 9** (PHP 5.6+, sin overrides).

Mete solo en el carrito un producto de regalo, a **precio cero** y con una etiqueta
(**REGALO**, texto y colores configurables), como hacían las antiguas reglas de carrito.

## Reglas
- **Por importe** del carrito (con o sin IVA, sumando o no el envío) con varios tramos:
  50 € → regalo A, 100 € → regalo B. Solo se da el del tramo más alto.
- **Por número de productos** con tramos.
- **Por producto concreto** en el carrito (p. ej. compra 2 y llévate 1).
- Combinación concreta del regalo y unidades a regalar por tramo.
- Fechas, unidades de regalo disponibles, prioridad, grupos de clientes y zonas.
- Si un regalo se agota se da el del tramo anterior y se avisa.
- Si el cliente quita el regalo, puede recuperarlo con un botón.

## Dónde se ve
Carrito, resumen del checkout, nombre de la línea del pedido (back-office, historial,
factura), ficha y listado de pedidos del back-office y estadísticas del módulo.

## Instalación
Módulos > Subir un módulo > zip. La configuración está en **Configurar** del propio módulo.
