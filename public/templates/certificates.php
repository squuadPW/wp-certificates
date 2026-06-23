<div id="certificades-card" >
    <h2>
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
                                            size: 100mm 150mm;
                                            margin: 0mm; 
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
                                    <?= $cert->html ?>
                                </body>
                                </html>'>
                            </iframe>
                        </div>
                    </div>
                    
                    <div class="cert-modal-footer">
                        <button type="button" class="button button-primary cert-btn-download" onclick="downloadExactCertHTML('cert-doc-<?= $cert->simple_uuid ?>', '<?= esc_attr(sanitize_file_name($cert->name_document)) ?>')">
                            <i class="dashicons dashicons-download"></i> <?= __('Download HTML (PDF View)', 'wp-certificates') ?>
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
</script>