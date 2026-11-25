# Changelog — JosraResetShop

## 1.2.0 — 2026-11-25
- Nuevo modo «Dejar como recién instalada» (PrestaShop 1.7.8 o superior): borra todas las secciones y vuelve a poner lo que trae una instalación nueva de esa misma versión, con el propio instalador de PrestaShop.
  - Vuelven de fábrica: la configuración de la tienda (los datos de dirección y teléfono quedan vacíos), transportistas, estados de pedido (se recrean los que falten, como cheque, transferencia o contra reembolso), grupos de clientes, páginas CMS, contactos, títulos y URLs de las páginas, tratamientos, estados de devolución, accesos rápidos y motivos de stock, ya traducidos al idioma de la tienda.
  - Se vacían: tiendas físicas, mensajes predefinidos, claves del webservice, consultas SQL, alias de búsqueda y traducciones propias.
  - Se conservan: tu empleado, perfiles y permisos, dominio, SSL, URL amigables, nombre y logos de la tienda, idiomas, países, impuestos, monedas, el tema y los módulos con su configuración (también sus transportistas y estados de pedido).
  - Con un interruptor, los productos de demostración de la instalación (19 productos con sus imágenes, categorías, marcas, un cliente y pedidos de ejemplo).
  - Los datos de instalación se bajan solos de GitHub la primera vez (unos 50 MB) mientras la pantalla va informando; si el servidor no sale a internet, se puede subir el zip de PrestaShop por FTP a var/josraresetshop/. Si no se pueden preparar, no se borra nada.
- Pantalla nueva con componentes de PrestaShop: elección de modo, tarjetas por sección con la cifra real e icono (se marcan al tocarlas), lista de lo que se borra y lo que se conserva, tablas de módulos con «marcar todas» por módulo, y progreso por secciones con su registro al lado.
- Al desinstalar el módulo se borran también los datos de instalación descargados.

## 1.1.0 — 2026-11-25
- Las combinaciones ya no fallan al crear productos nuevos tras vaciar el catálogo en PrestaShop 8 y 9 (se vacía también `product_attribute_lang`).
- «Carritos» borra solo los carritos sin pedido, como decía; los de los pedidos que se quedan no se tocan.
- «Mensajes» borra solo los hilos sin pedido; los de los pedidos se van con «Pedidos».
- Categorías: se conservan la Raíz y el Inicio de todas las tiendas (antes, con multitienda, se borraban las de las demás).
- Las secciones que dependen de otras las marcan solas, con el motivo a la vista: clientes → pedidos y carritos; catálogo → carritos; categorías y atributos → catálogo. Así la tienda no se queda con productos sin categoría, pedidos sin cliente o combinaciones rotas.
- Borrado por tandas: cada llamada trabaja unos segundos y sigue en la siguiente, sin cortes del servidor (el 503 de Plesk) en tiendas grandes ni dos borrados a la vez.
- Cada sección se lleva sus imágenes y archivos: catálogo (img/p, descargables y adjuntos), categorías, marcas y proveedores, texturas de color. Desaparece «Borrar todas las imágenes» suelto, que dejaba productos apuntando a imágenes que ya no existían.
- «Miniaturas» borra también las .webp y .avif, conserva las originales, las imágenes «sin foto» y las miniaturas de menú subidas a mano, y vacía img/tmp.
- Se limpian los restos que quedaban: accesorios, personalizaciones, etiquetas, palabras del buscador, índice del buscador por facetas, sesiones de clientes y empleados, cupones y precios personales, direcciones sueltas, envíos (PS 9), clientes B2B (PS 9.2), movimientos de stock de los pedidos.
- Nuevo: tablas de otros módulos con datos de pedidos, clientes, carritos o productos (y suscriptores del boletín), agrupadas por módulo, cada una con su casilla y desmarcadas, con aviso para migraciones.
- Nuevo: desinstalar los módulos conectados a PrestaShop / Addons (ps_accounts, ps_mbo, ps_eventbus, ps_checkout, ps_metrics, ps_facebook, autoupgrade…).
- Nuevo: cupones incluye las reglas de precios de catálogo; pedidos deja el siguiente albarán en el número 1.
- Seguridad: solo un SuperAdmin, y hay que escribir BORRAR para confirmar (se comprueba también en el servidor).
- Interfaz nativa de PrestaShop, cifras reales de cada sección, progreso paso a paso, resumen final y registro descargable.
- Conectado al servidor de actualizaciones de josra.

## 1.0.1
- Versión inicial: vaciado por secciones con registro descargable.
