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
- **PDF firmados protegidos:** nombre aleatorio, adjunto privado y fuera de la API REST de medios; los existentes se
  marcan privados sin moverlos (la URL directa del archivo sigue respondiendo: pendiente servirlos con permisos).

### 1.0.29
- Versión anterior (sin registro de cambios en este repositorio).
