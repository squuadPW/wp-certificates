# WP Certificates

**Versión:** 2.0.0
**Autor:** EduSof
**Licencia:** GPL2

Certificados, documentos personalizados y firma electrónica de la institución. Funciona solo o junto con EduSystem
(versión 6.0.0 o posterior): con EduSystem trabaja además con sus estudiantes.

## Registro de Cambios

### 2.0.0
Se publica junto con **EduSystem 6.0.0** (ADR 0004 de EduSystem). Resumen:

- **Funciona sin EduSystem.** Lo que depende de estudiantes se oculta si EduSystem no está; con EduSystem, los
  estudiantes y sus variables llegan por un contrato (proveedores y eventos).
- **Firma electrónica propia** (antes en EduSystem):
  - Firma por roles: en Certificación > Roles que firman se marcan los roles que firman; cada usuario recibe y firma
    su propio documento desde su cuenta. Después firman los firmantes del sistema (directores, coordinadores…).
  - Documentos automáticos (se piden en Mi Cuenta hasta firmarlos, con prioridad y campos adicionales), enlazados a
    un requisito de Admisión y «Emitir para firma» desde la ficha del estudiante.
  - Evidencia: cada firma se sella con una huella encadenada; Integridad de firmas y `wp squuad-cert firmas verificar`.
  - Recuadro de firma propio y PDF final en el navegador (html2pdf.js por CDN, excepción aprobada).
- **Libro de registro:** descripción de la línea por documento y filtros para reservar y anular la línea.
- **Variables con métodos** registrados por plugins propios (ADR 0005 de EduSystem).
- **Permisos propios** de certificación y pantalla Certificación > Permisos (ya no se usa `manage_options`).
- **Seguridad:**
  - API pública de verificación con lista blanca (nunca datos personales), límite de intentos por IP y códigos nuevos
    de 128 bits; los códigos antiguos siguen validando (G3).
  - Sin borrado masivo de firmas ni firmas-imagen: nadie firma por otro (G2).
  - Nonces y SQL preparado en el carnet y las plantillas (G7).
- **Conexiones API:** claves para que otros sistemas verifiquen documentos por código
  (`/wp-json/squuad-cert/v1/documents/<código>`); ver `api-conexiones-wp-certificates.md` en los informes de EduSystem.
- **Traducciones propias** (dominio `wp-certificates`, español incluido).
- **Documento de identidad de quien firma** (ADR 0007 de Edusof; apagado por defecto: mientras esté apagado no cambia
  nada). En Certificación > Configuración se enciende «Pedir documento de identidad» y se gestionan los tipos de
  documento (prefijo, país, formato). Quien firma sin documento ve primero el formulario y el servidor rechaza la firma
  hasta que lo registre; el identificador (prefijo + número) es único entre cuentas, se corrige hasta la primera firma
  (después, solo con el permiso «Documentos de identidad») y queda sellado en cada firma nueva (evidencia `EDUSIG3`;
  las firmas anteriores siguen verificando igual). Variable `{{holder_id_document}}`. Esquemas v12 y v13: se sella
  también quién registró el documento (persona, secretaría o administración), máximo 5 intentos fallidos por hora,
  prefijos sin solapes ni reutilización, exportador y borrador de datos personales.
- **Firmante por variable** (ADR 0009 de Edusof): un documento automático puede pedir la firma de la persona cuya
  cuenta da una variable de un plugin propio (con EduSystem, `{{signature_var_parent_user_id}}`: el representante firma
  el MISMO documento del estudiante, con sus datos, desde su cuenta; lo ve en «Documentos por firmar»). Fila «Firmante
  por variable» en «Firmantes del documento», ordenable entre los firmantes del sistema; variable vacía o inválida, o
  cuenta que ya firma: ese puesto se omite y queda en el registro de actividad. Solo solicitudes nuevas.
- **PDF firmados protegidos:** nombre aleatorio, adjunto privado y fuera de la API REST de medios; los existentes se
  marcan privados sin moverlos, también los del sistema de firma anterior (esquema v14). Su ficha solo la ven quien
  tiene el permiso de certificación o quien lo subió (la URL directa del archivo sigue respondiendo: pendiente servirlos
  con permisos).
- **API de documentos para otros plugins** (dentro del contrato; las funciones se comprueban con `function_exists`):
  - **Contexto de un documento al generarlo:** filtro `squuad_cert_document_context` para que otro plugin añada datos a
    las variables (por ejemplo, la inscripción) y filtro `squuad_cert_document_required_context` para que declare que un
    documento solo se genera con un contexto suyo (por ejemplo, un acta de una promoción). Si falta, ninguna ruta lo
    genera (Generar, asignar, emitir para firma, documentos automáticos, Mi cuenta) y se muestra su aviso; en Mi cuenta
    no se ofrece. Función `squuad_cert_document_ctx()`.
  - **Inscripción en las variables:** al asignar un certificado con inscripción, las variables y la línea del libro la
    reciben (`enrollment_id` en el contexto).
  - **Plantillas inactivas desde otro plugin:** `squuad_cert_create_document($data, $slots, $requires)` crea un documento
    inactivo con su política de firmantes (o no lo crea si la política no queda completa).
  - **Variables desde otro plugin:** `squuad_cert_create_variable($key, $text, $method, $type)` crea la variable con su
    método registrado o enlaza la que ya existe sin método; nunca cambia una que ya tiene método.
    Una variable que el sitio creó sin método con la misma clave se enlaza (como «Vincular por clave») y queda en el
    registro de actividad: quien llama debe usar claves propias.
  - **Registro en nombre de:** con una sesión conmutada («Entrar como» de soporte u otro plugin), el log anota quién hizo
    la acción en nombre de la cuenta.
  - Quitado el valor fijo «ENROLLMENT» del envío de la firma (el servidor no lo usaba).
  - Seguridad: el contexto de la ruta gana (un filtro solo añade claves, nunca cambia el titular); consultar si un
    documento necesita contexto no escribe en el registro; reemitir y «volver a pedir firmas» comprueban el contexto antes
    de anular el tomo y folio o revocar firmas; la línea del libro al emitir para firma recibe el contexto (filtro
    `squuad_cert_issue_book_entry` con un quinto argumento); la vuelta atrás de `squuad_cert_create_document` no deja
    restos de la política; el modal de Mi cuenta no repite el aviso.
  - En curso antes de publicar: emisión, firma y libro para cualquier tipo de titular (hoy, estudiante o cuenta).

### 1.0.29
- Versión anterior (sin registro de cambios en este repositorio).
