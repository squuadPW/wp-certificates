<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"
    integrity="sha512-GsLlZN/3F2ErC5ifS5QtgpiJtWd43JWSuIgh7mbzZ8zBps+dvLusV+eNQATqgA/HdeKFVgA5v3S/cIrLF7QnIg=="
    crossorigin="anonymous" referrerpolicy="no-referrer"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>

<div id="certificades-card" >
    <h2 >
        <?= esc_html__('Certificates', 'wp-certificates'); ?>
    </h2>

    <?php if ( !empty($certificates) ): ?>
        <div class="cert-grid-container">
            <?php foreach ( $certificates as $cert ): ?>
                
                <?php 
                    // Limpieza previa del contenido para la miniatura
                    $html_stripped = str_replace('{{page_break}}', ' ', $cert->html);
                    $text_only     = strip_tags($html_stripped);
                    $text_clean    = preg_replace('/\s+/', ' ', $text_only);
                    $excerpt       = wp_html_excerpt($text_clean, 140, '...');

                    //demas opciones de configuracion del docuemnto
                    $option_document = json_decode($cert->option_document);
                ?>

                <div class="cert-card">

                    <!-- Miniatura Dinámica con Extracto de Texto Real -->
                    <div class="cert-card-preview" onclick="openCertModal('cert-modal-<?= $cert->simple_uuid ?>')">
                        <div class="cert-mini-doc">
                            <i class="dashicons dashicons-pdf cert-mini-icon"></i>
                            <div class="cert-mini-content">
                                <?= $cert->html ?>
                            </div>
                        </div>
                        <div class="cert-overlay">
                            <span><i class="dashicons dashicons-visibility"></i> <?= __('Preview', 'wp-certificates') ?></span>
                        </div>
                    </div>

                    <!-- Información del Documento -->
                    <div class="cert-card-body">
                        <h3 class="cert-title"><?= esc_html($cert->name_document) ?></h3>
                        
                        <div class="cert-meta">
                            <p><strong><?= __('Issued:', 'wp-certificates') ?></strong> <?= date(get_option('date_format'), strtotime($cert->emission_date)) ?></p>
                            <p><strong><?= __('Expires:', 'wp-certificates') ?></strong> 
                                <?php if ( !empty($cert->expiration_date) ): ?>
                                    <span class="cert-status expires"><?= date(get_option('date_format'), strtotime($cert->expiration_date)) ?></span>
                                <?php else: ?>
                                    <span class="cert-status no-expiry"><?= __('Does not expire', 'wp-certificates') ?></span>
                                <?php endif; ?>
                            </p>
                        </div>
                    </div>

                    <!-- Acciones -->
                    <div class="cert-card-footer">
                        <button type="button" class="button button-primary" onclick="openCertModal('cert-modal-<?= $cert->simple_uuid ?>')">
                            <?= __('View Fullscreen', 'wp-certificates') ?>
                        </button>
                    </div>
                </div>

                <!-- Ventana Modal Estilo Lector PDF Aislado -->
                <dialog id="cert-modal-<?= $cert->simple_uuid ?>" class="cert-fullscreen-modal">
                    <div class="cert-modal-header">
                        <h3><?= esc_html($cert->name_document) ?></h3>
                        <button type="button" class="cert-close-btn" onclick="closeCertModal('cert-modal-<?= $cert->simple_uuid ?>')">&times;</button>
                    </div>
                    
                    <div class="cert-modal-content">
                        <!-- El contenedor se comporta transparente para respetar los z-index y posiciones fijas -->
                        <div class="cert-raw-wrapper">
                            <iframe id="cert-doc-<?= $cert->simple_uuid ?>"  srcdoc='
                                <!DOCTYPE html>
                                <html>
                                    <head>
                                        <meta charset="utf-8">
                                        <title><?= $cert->name_document ?></title>
                                        
                                        

                                        <style>

                                            @page {
                                                size: <?= !empty($option_document->width_style) ? $option_document->width_style . ' ' . $option_document->height_style : 'A4' ?> <?= $option_document->paper_format != 'custom' && !empty($option_document->orientation) ? $option_document->orientation : '' ?>;
                                                margin: 0mm; 
                                            }

                                            html {
                                                all: initial;
                                                box-sizing: border-box;
                                            }

                                            html, body {
                                                margin: 0;
                                                padding: 0;
                                            }

                                            body {
                                                display: flex;
                                                justify-content: center;
                                            }

                                        </style>
                                    </head>
                                    <body>
                                        <?= htmlspecialchars( $cert->html, ENT_QUOTES, 'UTF-8') ?>

                                        <script type="text/javascript" src="https://unpkg.com/qr-code-styling@1.5.0/lib/qr-code-styling.js"></script>
                                        <?php if( $option_document->qr ): ?>
                                            <script>
                                                if ( document.getElementById("qrcode") ) {
                                                    const qrCode = new QRCodeStyling({
                                                        width: 100,
                                                        height: 100,
                                                        data: "<?= $option_document->qr->url ?>",
                                                        image: "<?= $option_document->qr->image_url ?>",
                                                        dotsOptions: { color: "#000000" },
                                                        backgroundOptions: { color: "#ffffff" },
                                                        imageOptions: {
                                                            crossOrigin: "anonymous",
                                                        },
                                                    });

                                                    qrCode.append(document.getElementById("qrcode"));
                                                }
                                            </script>
                                        <?php endif ?>

                                    </body>
                                </html>'>
                            </iframe>
                        </div>
                    </div>
                    
                    <div class="cert-modal-footer">
                        <!-- <button type="button" class="button button-primary cert-btn-download" onclick="downloadExactCertHTML('cert-doc-<?= $cert->simple_uuid ?>', '<?= esc_attr(sanitize_file_name($cert->name_document)) ?>')">
                            <i class="dashicons dashicons-download"></i> <?= __('Download HTML (PDF View)', 'wp-certificates') ?>
                        </button> -->

                        <?php 
                            // Construimos el array con la estructura exacta que espera la función JS
                            $js_config = [
                                'document_id'  => 'cert-doc-'.$cert->simple_uuid,
                                'filename'     => sanitize_file_name($cert->name_document),
                                'unit'         => $option_document->unit,
                                'width_size'   => floatval($option_document->width_size),
                                'height_size'  => floatval($option_document->height_size),
                                'paper_format' => $option_document->paper_format,
                                'orientation'  => $option_document->orientation,
                            ];
                        ?>

                        <button type="button" class="button button-primary cert-btn-download" onclick='download_document(<?= json_encode($js_config, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>
                            <i class="dashicons dashicons-download"></i> <?= __('Download PDF', 'wp-certificates') ?>
                        </button>
                    </div>
                </dialog>

            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <p class="woocommerce-info"><?= __("You don't have certificates", 'wp-certificates'); ?></p>
    <?php endif; ?>
</div>


<!-- ================= JAVASCRIPT REAJUSTADO (PRESERVACIÓN DE FORMATO) ================= -->
<script>
function openCertModal(modalId) {
    const modal = document.getElementById(modalId);
    if (modal) {
        modal.showModal();
        document.body.style.overflow = 'hidden'; 
    }
}

function closeCertModal(modalId) {
    const modal = document.getElementById(modalId);
    if (modal) {
        modal.close();
        document.body.style.overflow = '';
    }
}

document.querySelectorAll('.cert-fullscreen-modal').forEach(modal => {
    modal.addEventListener('click', function(e) {
        if ( e.target === modal ) {
            modal.close();
            document.body.style.overflow = '';
        }
    });
});

/**
 * Descarga y empaqueta el HTML adaptándose fielmente a formatos fluidos o absolutos fijados
 */
function downloadExactCertHTML(containerId, filename) {
    const element = document.getElementById(containerId);
    if (!element) return;

    /* let htmlContent = element.innerHTML;

    const blob = new Blob([htmlContent], { type: 'text/html;charset=utf-8;' });
    const link = document.createElement("a");
    const url = URL.createObjectURL(blob);
    
    link.setAttribute("href", url);
    link.setAttribute("download", `${filename}.html`);
    link.style.visibility = 'hidden'; */

    element.contentWindow.focus(); // Enfoca el iframe (requerido por algunos navegadores)
    element.contentWindow.print(); // Abre la ventana de impresión del navegador

}

async function download_document( document_config ) {

    if (!document_config || typeof document_config !== 'object') {
        console.error('download_document: El parámetro document_config no es válido.');
        return;
    }

    // Aplicar valores por defecto seguros (Fallback) para evitar que rompa por datos faltantes
    const config = Object.assign({
        document_id: null,
        filename: 'documento-descargado',
        unit: 'cm',
        width_size: 0.0,
        height_size: 0.0,
        paper_format: 'a4',
        orientation: 'Portrait'
    }, document_config);

    if (!config.document_id) {
        console.error('download_document: Falta el "document_id" para localizar el iframe.');
        return;
    }

    let format;
    if (config.paper_format && config.paper_format !== 'custom' && config.paper_format !== '') {
        // Si es una string estándar (letter, a4, etc.), jsPDF lo entiende directamente
        format = config.paper_format;
    } else {
        // Si es personalizado, nos aseguramos de castear a números flotantes limpios
        const width = parseFloat(config.width_size) || 0.0;
        const height = parseFloat(config.height_size) || 0.0;
        format = [width, height];
    }

    const parches = [];
    if (config.unit === 'px') parches.push('px_scaling');

    const iframe = document.getElementById(config.document_id);
    if (!iframe) {
        console.error(`download_document: No se encontró ningún elemento con el ID "${config.document_id}".`);
        return;
    }

    let element;
    try {
        if (iframe.contentWindow && iframe.contentWindow.document) {
            //element = iframe.contentWindow.document.body;
            element = iframe.contentWindow.document.documentElement;
        }
    } catch (e) {
        console.error('download_document: Error de CORS o estructura al intentar leer el contenido del iframe.', e);
        return;
    }
    
    console.log(element);
    /* return; */

    if (!element || !element.innerHTML.trim()) {
        console.warn('download_document: El iframe está vacío o no se ha cargado su body aún.');
        return;
    }

    filename = config.filename;
    if( !filename.endsWith('.pdf') ) filename = `${filename}.pdf`
    
    const opt = {
        margin: 0,
        filename: filename,
        image: { type: 'jpeg', quality: 1 },
        html2canvas: { 
            scale: 3,
            useCORS: true,
        },
        jsPDF: { 
            unit: config.unit, 
            format: format, 
            orientation: config.orientation,
            hotfixes: parches 
        },
        pagebreak: { after: ".pagebreak" }
    };

    // Generar el PDF
    /* const pdf = await html2pdf().set(opt).from(element).toPdf().get("pdf");

    // Guardar el PDF una sola vez
    pdf.save( filename ).catch(err => {
        console.error('html2pdf: Ocurrió un error durante la generación del PDF:', err);
    });  */

    html2pdf()
        .set(opt)
        .from(element)
        .save()
        .catch(err => {
            console.error('html2pdf: Ocurrió un error durante la generación del PDF:', err);
        });
}

</script>