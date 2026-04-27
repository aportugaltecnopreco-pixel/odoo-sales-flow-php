# Odoo Sales Flow - PHP CLI

Versión en **PHP nativo** del flujo de ventas automatizado en Odoo usando **cURL** y buenas prácticas SOLID pragmáticas.

---

## Requisitos

- **PHP 8.0+** (con cURL habilitado)
- Instancia de Odoo accesible en red
- Usuario con permisos sobre `sale.order`, `stock.picking` y `account.move`

---

## Instalación

```bash
# Navegar a la carpeta
cd PRUEBA_PHP

# Listo - No necesita dependencias externas (PHP nativo + cURL)
```

---

## Configuración

### 1. Archivo `.env`

Crea un archivo `.env` en la raíz del proyecto con tus credenciales:

```env
ODOO_URL=https://tu-instancia.odoo.com
ODOO_DB=tu-base-de-datos
ODOO_USER=tu-usuario@email.com
ODOO_PASS=tu-contraseña
ODOO_PORT=         # Opcional - déjalo vacío si no necesitas puerto
```

### 2. Archivo `params.json`

Configura los parámetros de la venta en `params.json`. Los valores usan **XML IDs** de Odoo:

```json
{
    "order": {
        "partner_id": "base.res_partner_12",
        "date_order": "2026-04-18",
        "order_line": [
            {
                "product_id": "product.desk_organizer",
                "product_uom_qty": 2,
                "price_unit": 50.0,
                "tax_id": [
                    "account.1_sale_tax_template"
                ]
            }
        ]
    }
}
```

**Cómo encontrar XML IDs en Odoo:**
- Modo desarrollador: Ajustes → Técnico → Secuencias e identificadores → Identificadores externos

---

## Uso

```bash
php index.php
```

Se abrirá un menú interactivo en la terminal:

```
? ¿Qué querés ejecutar?
── Pasos individuales ──────────────
1. Autenticar
2. Crear venta
3. Crear y confirmar venta
4. Crear, confirmar y validar salida de productos
5. Crear, confirmar y crear factura
6. Flujo completo (Crear, confirmar, facturar)
───────────────────────────────────
7. Salir
```

Ingresa el número de la opción y presiona Enter.

---

## Arquitectura - Buenas Prácticas SOLID

### Estructura de clases:

**`Auth.php`** - Autenticación
- Factory pattern para inicialización
- Responsabilidad única: conectar y autenticar
- cURL encapsulado

**`OdooHelper.php`** - Funciones auxiliares
- Inyección de dependencia (recibe Auth)
- Métodos reutilizables: `callMethod()`, `xmlIdToResID()`
- Separación de concerns

**`Operations.php`** - Operaciones de venta
- Orquesta Auth + OdooHelper
- Métodos específicos del flujo de ventas
- Métodos privados para preparación de datos

**`index.php`** - Punto de entrada
- Carga .env y params.json
- Menú CLI interactivo
- Manejo de excepciones centralizado

---

## Características de Seguridad

✅ **cURL SSL verificado** (SSL_VERIFYPEER + SSL_VERIFYHOST)  
✅ **Manejo robusto de errores** con excepciones  
✅ **Variables de entorno** para credenciales (no en el código)  
✅ **Type hints** en todas las funciones  
✅ **JSON encoding/decoding** validado  

---

## Manejo de Errores

```php
try {
    // Operación Odoo
} catch (Exception $e) {
    Logger::error("Error: " . $e->getMessage());
}
```

Los errores de Odoo, cURL y JSON se propagan con contexto útil.

---

## Equivalencia con Node.js

| Función | Node.js | PHP |
|---------|---------|-----|
| Autenticación | `auth.js` | `Auth.php` |
| Auxiliares | `helps.js` | `OdooHelper.php` |
| Operaciones | `operations.js` | `Operations.php` |
| Menú CLI | `app.js` | `index.php` |

---

## Notas Técnicas

- **Curl:** Sin librerías externas, cURL nativo
- **JSON-RPC 2.0:** Implementado según estándar Odoo
- **Async:** PHP es sincrónico, las llamadas se hacen secuencialmente (igual que Odoo espera)
- **SOLID:** Aplicado pragmáticamente sin sobre-ingeniería

---

## Troubleshooting

### "Variables de entorno faltantes"
→ Verifica que .env exista y tenga ODOO_URL, ODOO_DB, ODOO_USER, ODOO_PASS

### "HTTP Error 401"
→ Usuario o contraseña incorrectos

### "No pickings found"
→ La venta no generó un picking (problema en Odoo, no en el código)

### "cURL Error: SSL certificate problem"
→ Si confías en el certificado, temporalmente puedes desactivar SSL en Auth.php (NO para producción)

---
