/**
 * Ventana de firma de Mi Cuenta (ADR 0011 de Edusof; maqueta aprobada el 2026-10-05). Sin dependencias de terceros
 * salvo html2pdf.js (ADR 0004) y el generador de QR que ya usa el plugin (pendiente de reescribir, ADR 0011).
 *
 * 1. Consentimiento (mismo texto y versión v1) y «Empezar».
 * 2. Etiquetas «Firmar aquí» de quien mira (una por cada vez que la plantilla coloca su variable): «Siguiente» lleva a
 *    la próxima; la primera abre «Adoptar su firma» (Escribir, Dibujar o Subir imagen) y la firma adoptada se reutiliza
 *    en las demás; «Cambiar» permite rehacerla antes de finalizar.
 * 3. «Finalizar»: la misma petición de siempre (create_enrollment_document, nonce, solicitud, huella del contenido y
 *    consentimiento) con la firma en formato v2; el servidor lo revalida todo. Si con esta firma ya firmaron todos, el
 *    servidor devuelve el documento final con el certificado de firmas: el navegador genera el PDF y lo sube.
 * 4. Panel de éxito con el estado de cada firmante (datos del servidor), en lugar de alert() y recargar.
 *
 * Quien solo rellena (campos adicionales, sin firma) usa su propio script en la plantilla; los campos adicionales se
 * guardan en sessionStorage (abajo), como antes.
 */
let gradeSelected = null;

function signaturesText(key) {
    return (window.edusystemSignatures && edusystemSignatures.i18n && edusystemSignatures.i18n[key]) || key;
}

/** Texto traducido con %s, %d, %1$s, %2$d… */
function signaturesFormat(key, ...args) {
    let i = 0;
    return signaturesText(key).replace(/%(?:(\d+)\$)?[sd]/g, (match, position) => String(args[position ? Number(position) - 1 : i++] ?? ""));
}

document.addEventListener("DOMContentLoaded", () => {
    const root = document.getElementById("wpc-sign");

    if (document.getElementById("modal_open")) {
        document.body.classList.add("modal-open");
        if (root) {
            document.documentElement.classList.add("wpc-sign-open");
            document.body.classList.add("wpc-sign-open");
        }
    }

    // Cerrar sin firmar (vuelve a insistir en la siguiente visita). También el formulario de campos adicionales
    const closeModal = () => {
        ["modal-contraseña", "modal-content"].forEach((id) => {
            const element = document.getElementById(id);
            if (element) element.style.display = "none";
        });
        if (root) root.hidden = true;
        document.body.classList.remove("modal-open", "wpc-sign-open");
        document.documentElement.classList.remove("wpc-sign-open");
    };
    const closeButton = document.getElementById("close-modal-enrollment");
    if (closeButton) closeButton.addEventListener("click", () => {
        if (root && root.dataset.wpcSigned && !window.confirm(signaturesText("closeConfirm"))) return;
        closeModal();
    });

    if (root) initSigningWindow(root, closeModal);
});

function initSigningWindow(root, closeModal) {
    const $ = (selector, scope = root) => scope.querySelector(selector);
    const $$ = (selector, scope = root) => Array.from(scope.querySelectorAll(selector));
    const field = (name) => {
        const input = root.querySelector(`input[name="${name}"]`);
        return input ? input.value : "";
    };
    const reducedMotion = window.matchMedia && window.matchMedia("(prefers-reduced-motion: reduce)").matches;
    const ajaxUrl = (window.ajax_object && window.ajax_object.ajax_url) || "/wp-admin/admin-ajax.php";
    const nonce = window.edusystemSignatures ? edusystemSignatures.nonce : "";

    // Foco atrapado en la ventana (el <dialog> de adopción ya lo hace solo)
    const windowEl = $(".wpc-sign-window");
    root.addEventListener("keydown", (event) => {
        const dialog = $("#wpc-adopt");
        if (dialog && dialog.open) return;
        if (event.key === "Escape") {
            // Con una firma ya adoptada en el documento, se pregunta antes de cerrar (se perdería)
            if (!root.dataset.wpcSigned || window.confirm(signaturesText("closeConfirm"))) closeModal();
            return;
        }
        if (event.key !== "Tab" || !windowEl) return;
        const focusable = $$("a[href], button:not([disabled]), input:not([disabled]):not([type=hidden]), [tabindex]:not([tabindex='-1'])", windowEl)
            .filter((element) => element.offsetParent !== null);
        if (!focusable.length) return;
        const first = focusable[0];
        const last = focusable[focusable.length - 1];
        if (event.shiftKey && document.activeElement === first) {
            last.focus();
            event.preventDefault();
        } else if (!event.shiftKey && document.activeElement === last) {
            first.focus();
            event.preventDefault();
        }
    });
    const title = $("#wpc-sign-title");
    if (title) setTimeout(() => title.focus({ preventScroll: true }), 50);

    const tags = $$("[data-wpc-tag]");
    if (!tags.length) return; // solo rellena, o nada que firmar aquí

    const consentBox = $("[data-wpc-consent-box]");
    const consentBand = $("[data-wpc-consent]");
    const consentError = $("[data-wpc-consent-err]");
    const startButton = $("[data-wpc-start]");
    const guide = $("[data-wpc-guide]");
    const guideText = $("[data-wpc-guide-text]");
    const counter = $("[data-wpc-count]");
    const mobileCounter = $("[data-wpc-count-m]");
    const mainButton = $("[data-wpc-main]");
    const mobileButton = $("[data-wpc-main-m]");
    const alertBox = $("[data-wpc-alert]");
    const scrollBox = $("[data-wpc-scroll]");
    const total = tags.length;
    const state = { started: false, adoption: null, signed: new Set(), busy: false, done: false };

    const nextTag = () => tags.find((tag) => !state.signed.has(tag));
    const showAlert = (message, info = false) => {
        if (!alertBox) return;
        alertBox.textContent = message || "";
        alertBox.classList.toggle("is-info", info);
        alertBox.hidden = !message;
    };

    function refresh() {
        const done = state.signed.size;
        const next = nextTag();
        const count = signaturesFormat("counter", done, total);
        if (counter) counter.textContent = count;
        if (mobileCounter) mobileCounter.textContent = state.started ? count : "";
        if (guide) {
            guide.hidden = !state.started || state.done;
            guide.classList.toggle("is-done", !next);
        }
        if (guideText) guideText.textContent = signaturesText(next ? "guideNext" : "guideDone");
        const label = !state.started ? signaturesText("start") : signaturesText(next ? "next" : "finish");
        if (mainButton) {
            mainButton.hidden = !state.started || state.done;
            mainButton.textContent = label;
            mainButton.disabled = state.busy;
        }
        if (mobileButton) {
            mobileButton.textContent = label;
            mobileButton.disabled = state.busy;
            mobileButton.closest("[data-wpc-foot]").hidden = state.done;
        }
        tags.forEach((tag, index) => {
            const tagLabel = tag.dataset.label || "";
            tag.classList.toggle("is-target", state.started && tag === next);
            const flag = tag.querySelector(".wpc-sign-flag");
            if (flag) flag.textContent = signaturesText(done === 0 ? "start" : "next");
            tag.setAttribute("aria-label", signaturesFormat("tagLabel", tagLabel, index + 1, total));
            const doneBox = doneBoxOf(tag);
            if (doneBox) {
                const change = doneBox.querySelector("[data-wpc-change]");
                if (change) change.setAttribute("aria-label", signaturesFormat("tagSigned", tagLabel, index + 1, total));
            }
        });
    }

    function doneBoxOf(tag) {
        const area = tag.closest(".wpc-sign-area") || tag.parentElement;
        return area ? area.querySelector("[data-wpc-done]") : null;
    }

    function requireConsent() {
        if (consentBox && !consentBox.checked) {
            if (consentError) consentError.hidden = false;
            if (consentBand) consentBand.hidden = false;
            consentBox.focus();
            return false;
        }
        if (consentError) consentError.hidden = true;
        return true;
    }

    function goTo(tag) {
        if (!tag) return;
        tag.scrollIntoView({ block: "center", behavior: reducedMotion ? "auto" : "smooth" });
        tag.focus({ preventScroll: true });
    }

    function start() {
        if (!requireConsent()) return false;
        state.started = true;
        if (consentBand) consentBand.hidden = true;
        refresh();
        goTo(nextTag());
        return true;
    }

    function main() {
        if (state.busy || state.done) return;
        if (!state.started) {
            start();
            return;
        }
        const next = nextTag();
        if (next) goTo(next);
        else finish();
    }

    if (consentBox) consentBox.addEventListener("change", () => { if (consentBox.checked && consentError) consentError.hidden = true; });
    if (startButton) startButton.addEventListener("click", start);
    if (mainButton) mainButton.addEventListener("click", main);
    if (mobileButton) mobileButton.addEventListener("click", main);

    tags.forEach((tag) => tag.addEventListener("click", () => {
        if (state.busy || state.done) return;
        if (!state.started && !start()) return;
        if (!state.adoption) adopt.open(tag);
        else signTag(tag);
    }));
    $$("[data-wpc-change]").forEach((button) => button.addEventListener("click", () => {
        if (!state.busy && !state.done) adopt.open(null);
    }));

    // Firma adoptada en una etiqueta: la etiqueta se cambia por la firma (con «Cambiar», fuera del PDF)
    function signatureMarkup() {
        const adoption = state.adoption;
        if (!adoption) return "";
        if (adoption.method === "typed") {
            const span = document.createElement("span");
            span.className = `wpc-typed wpc-font-${adoption.style}`;
            span.textContent = adoption.text;
            return span.outerHTML;
        }
        const img = document.createElement("img");
        img.src = adoption.preview;
        img.alt = signaturesFormat("signatureOf", root.dataset.ownName || "");
        return img.outerHTML;
    }

    function paintTag(tag) {
        const doneBox = doneBoxOf(tag);
        if (!doneBox) return;
        doneBox.querySelector("[data-wpc-done-img]").innerHTML = signatureMarkup();
        doneBox.hidden = false;
        tag.hidden = true;
    }

    function signTag(tag) {
        state.signed.add(tag);
        root.dataset.wpcSigned = "1";
        paintTag(tag);
        refresh();
        const next = nextTag();
        if (next) goTo(next);
        else if (mainButton && !mainButton.hidden && mainButton.offsetParent) mainButton.focus();
        else if (mobileButton) mobileButton.focus();
    }

    const adopt = createAdoptDialog(root, tags, (adoption, target) => {
        state.adoption = adoption;
        state.signed.forEach(paintTag); // «Cambiar»: todas las firmas ya puestas pasan a la nueva
        if (target && !state.signed.has(target)) signTag(target);
        else refresh();
    });

    // Finalizar: misma petición, nonce, huella y consentimiento; firma en formato v2
    async function finish() {
        if (state.busy || state.done || nextTag() || !state.adoption) return;
        if (!requireConsent()) return;
        state.busy = true;
        refresh();
        showAlert(signaturesText("saving"), true);

        const adoption = state.adoption;
        const signature = { v: 2, method: adoption.method };
        // Escrita: solo el texto y el estilo; la imagen la dibuja siempre el servidor
        if (adoption.method === "typed") Object.assign(signature, { style: adoption.style, text: adoption.text });
        if (adoption.method === "drawn") signature.strokes = adoption.strokes;
        if (adoption.method === "image") Object.assign(signature, { png: adoption.png, own_signature_confirmed: true });

        const data = new FormData();
        data.append("action", "create_enrollment_document");
        data.append("_ajax_nonce", nonce);
        data.append("request_id", field("request_id"));
        data.append("content_sha256", field("content_sha256"));
        data.append("document_id", field("document_id") || "ENROLLMENT");
        data.append("student_user_id", field("student_user_id"));
        if (consentBox && consentBox.checked) data.append("consent_version", consentBox.value);
        const fieldsValues = field("document_fields_values");
        if (fieldsValues) data.append("document_fields", fieldsValues);
        if (gradeSelected) data.append("grade_selected", gradeSelected);
        data.append("signature_v2", JSON.stringify(signature));

        let result = null;
        try {
            const response = await fetch(`${ajaxUrl}?action=create_enrollment_document`, { method: "POST", body: data, credentials: "same-origin" });
            result = await response.json().catch(() => null);
        } catch (error) {
            result = null;
        }
        if (!result || !result.success) {
            state.busy = false;
            showAlert((result && typeof result.data === "string" && result.data) || signaturesText("saveFailed"));
            refresh();
            return;
        }

        clearDocumentFieldsStorage();
        state.busy = false;
        state.done = true;
        showAlert("");
        const payload = result.data || {};
        showSuccess(payload.signers || [], !!payload.final);
        if (payload.final) {
            const ok = await generateFinalPdf(payload.final);
            const status = $("[data-wpc-pdf-status]");
            if (status) {
                status.hidden = false;
                status.textContent = signaturesText(ok ? "pdfOk" : "pdfFail");
            }
        }
    }

    function showSuccess(signers, complete) {
        if (consentBand) consentBand.hidden = true;
        if (guide) guide.hidden = true;
        if (scrollBox) scrollBox.hidden = true;
        refresh();
        const panel = $("[data-wpc-success]");
        if (!panel) return;
        panel.hidden = false;
        $("[data-wpc-success-title]").textContent = signaturesText(complete ? "completeTitle" : "signedTitle");
        $("[data-wpc-success-text]").textContent = signaturesText(complete ? "completeText" : "signedText");
        const list = $("[data-wpc-steps]");
        list.innerHTML = "";
        signers.forEach((signer) => {
            const item = document.createElement("li");
            const chip = document.createElement("span");
            chip.className = `wpc-chip wpc-signer-c${Number(signer.color) || 1}`;
            chip.innerHTML = '<span class="wpc-chip-dot" aria-hidden="true"></span><b></b>';
            chip.querySelector("b").textContent = `F${Number(signer.n) || 1}`;
            const who = document.createElement("span");
            who.className = "wpc-steps-who";
            const name = document.createElement("b");
            name.textContent = signer.own ? `${signaturesText("you")} (${signer.name})` : `${signer.label} (${signer.name})`;
            const sub = document.createElement("span");
            sub.textContent = signer.state === "signed" ? signer.signed_at : signaturesText(signer.state === "turn" ? "turnHint" : "pendingHint");
            who.append(name, sub);
            const badge = document.createElement("span");
            badge.className = `wpc-badge wpc-badge--${signer.state === "signed" ? "ok" : signer.state === "turn" ? "info" : "neutral"}`;
            badge.textContent = signaturesText(signer.state === "signed" ? "stateSigned" : signer.state === "turn" ? "stateTurn" : "statePending");
            item.append(chip, who, badge);
            list.append(item);
        });
        // Chips del orden de firma con el estado nuevo
        signers.forEach((signer) => {
            const chip = root.querySelector(`[data-wpc-chip="${CSS.escape(signer.slot)}"]`);
            if (!chip || signer.state !== "signed") return;
            chip.removeAttribute("aria-current");
            const own = chip.querySelector("[data-wpc-own-state]");
            if (own) {
                own.className = "wpc-chip-st wpc-chip-st--ok";
                own.textContent = `· ${signaturesText("signedChip")}`;
            }
        });
        const heading = $("[data-wpc-success-title]");
        if (heading) heading.focus();
        const back = $("[data-wpc-return]");
        if (back) back.addEventListener("click", (event) => {
            event.preventDefault();
            window.location.replace(back.href.split("#")[0]);
        });
    }

    // PDF final con el HTML del servidor (contenido congelado, firmas y certificado de firmas): sin colores ni botones
    async function generateFinalPdf(final) {
        if (typeof window.html2pdf !== "function") return false;
        const wrapper = document.createElement("div");
        wrapper.style.cssText = `position:absolute;left:-10000px;top:0;width:${Number(final.width) || 794}px`;
        const source = document.createElement("div");
        source.style.cssText = "width:100%;box-sizing:border-box;background:#fff;padding:16px;font-family:Arial,sans-serif;color:#111";
        source.innerHTML = final.html;
        wrapper.append(source);
        document.body.append(wrapper);
        try {
            drawQrCodes(source);
            if (document.fonts && document.fonts.ready) await document.fonts.ready;
            await new Promise((resolve) => setTimeout(resolve, 150));
            const blob = await html2pdf().set({
                margin: final.margin, filename: final.filename, image: { type: "jpeg", quality: 0.98 },
                jsPDF: final.jspdf, html2canvas: { scrollX: 0, scrollY: 0, scale: 2 },
                pagebreak: { mode: ["avoid-all", "css", "legacy"], after: ".pagebreak" },
            }).from(source).outputPdf("blob");
            const data = new FormData();
            data.append("action", "create_enrollment_document");
            data.append("_ajax_nonce", nonce);
            data.append("request_id", field("request_id"));
            data.append("content_sha256", final.sha);
            if (final.token) data.append("pdf_token", final.token);
            data.append("document", blob, final.filename);
            const response = await fetch(`${ajaxUrl}?action=create_enrollment_document`, { method: "POST", body: data, credentials: "same-origin" });
            const result = await response.json().catch(() => null);
            return !!(result && result.success);
        } catch (error) {
            return false;
        } finally {
            wrapper.remove();
        }
    }

    refresh();
}

/** QR de verificación del certificado de firmas (generador del plugin; pendiente de reescribir, ADR 0011). */
function drawQrCodes(scope) {
    if (typeof window.QRCode !== "function") return;
    scope.querySelectorAll("[data-wpc-qr]").forEach((box) => {
        if (!box.dataset.wpcQr || box.childElementCount) return;
        new QRCode(box, { text: box.dataset.wpcQr, width: 120, height: 120, colorDark: "#000000", colorLight: "#ffffff", correctLevel: QRCode.CorrectLevel.M });
    });
}

/**
 * Ventana «Adoptar su firma»: pestañas accesibles (flechas, Inicio y Fin), Escribir (por defecto), Dibujar y Subir
 * imagen (si el sitio la permite), vista previa y «Se usará en…». onAdopt(adopción, etiqueta pulsada o null).
 */
function createAdoptDialog(root, tags, onAdopt) {
    const dialog = root.querySelector("#wpc-adopt");
    const $ = (selector) => dialog.querySelector(selector);
    const $$ = (selector) => Array.from(dialog.querySelectorAll(selector));
    const tabs = $$("[data-wpc-tab]");
    const panels = $$("[data-wpc-panel]");
    const typedInput = $("[data-wpc-typed]");
    const preview = $("[data-wpc-preview]");
    const errorBox = $("[data-wpc-adopt-err]");
    const canvas = $("[data-wpc-canvas]");
    const placeholder = $("[data-wpc-pad-ph]");
    const fileInput = $("[data-wpc-file]");
    const filePreview = $("[data-wpc-file-preview]");
    const ownConfirm = $("[data-wpc-own-confirm]");
    const drop = $("[data-wpc-drop]");
    const config = window.edusystemSignatures || {};
    const maxBytes = Number(config.uploadMaxBytes) || 2097152;
    let target = null;
    let current = tabs.length ? tabs[0].dataset.wpcTab : "drawn"; // «Escribir» si el servidor la puede dibujar
    let pad = null;
    let image = null; // data URI de la imagen elegida

    const style = () => Number((dialog.querySelector('input[name="wpc_style"]:checked') || {}).value || 1);
    const typedText = () => (typedInput ? typedInput.value || "" : "").trim();
    const showError = (message) => {
        errorBox.textContent = message || "";
        errorBox.hidden = !message;
    };

    function selectTab(name, focus = false) {
        current = name;
        tabs.forEach((tab) => {
            const selected = tab.dataset.wpcTab === name;
            tab.setAttribute("aria-selected", String(selected));
            tab.tabIndex = selected ? 0 : -1;
            if (selected && focus) tab.focus();
        });
        panels.forEach((panel) => { panel.hidden = panel.dataset.wpcPanel !== name; });
        if (name === "drawn") setupPad();
        showError("");
        updatePreview();
    }

    tabs.forEach((tab, index) => {
        tab.addEventListener("click", () => selectTab(tab.dataset.wpcTab));
        tab.addEventListener("keydown", (event) => {
            const moves = { ArrowRight: index + 1, ArrowLeft: index - 1, Home: 0, End: tabs.length - 1 };
            if (!(event.key in moves)) return;
            event.preventDefault();
            const next = (moves[event.key] + tabs.length) % tabs.length;
            selectTab(tabs[next].dataset.wpcTab, true);
        });
    });

    // Escribir: el texto se ve en los cuatro estilos
    if (typedInput) typedInput.addEventListener("input", () => {
        $$("[data-wpc-sample]").forEach((sample) => { sample.textContent = typedText() || root.dataset.ownName || ""; });
        updatePreview();
    });
    $$('input[name="wpc_style"]').forEach((radio) => radio.addEventListener("change", updatePreview));

    // Dibujar: lienzo blanco con línea base, táctil (EdusystemSignaturePad, propio)
    function setupPad() {
        if (!canvas || typeof window.EdusystemSignaturePad !== "function") return;
        requestAnimationFrame(() => {
            const ratio = Math.max(window.devicePixelRatio || 1, 1);
            const box = canvas.getBoundingClientRect();
            if (!box.width) return;
            canvas.width = Math.round(box.width * ratio);
            canvas.height = Math.round(box.height * ratio);
            if (!pad) {
                pad = new EdusystemSignaturePad(canvas, { lineWidth: 2.4, penColor: "#10182a" });
                pad.addEventListener("afterUpdateStroke", () => {
                    if (placeholder) placeholder.hidden = !pad.isEmpty();
                    updatePreview();
                });
            }
        });
    }
    const undo = $("[data-wpc-undo]");
    if (undo) undo.addEventListener("click", () => {
        if (!pad) return;
        const data = pad.toData();
        data.pop();
        pad.fromData(data);
        if (placeholder) placeholder.hidden = !pad.isEmpty();
        updatePreview();
    });
    const clear = $("[data-wpc-clear]");
    if (clear) clear.addEventListener("click", () => {
        if (!pad) return;
        pad.clear();
        if (placeholder) placeholder.hidden = false;
        updatePreview();
    });

    // Subir imagen: PNG o JPG de hasta 2 MB (el servidor la vuelve a comprobar y la recodifica)
    function takeFile(file) {
        image = null;
        if (filePreview) filePreview.hidden = true;
        if (!file) return updatePreview();
        if (!["image/png", "image/jpeg"].includes(file.type) || file.size > maxBytes) {
            showError(signaturesText("imageType"));
            return updatePreview();
        }
        const reader = new FileReader();
        reader.onload = () => {
            image = String(reader.result || "");
            if (filePreview) {
                filePreview.src = image;
                filePreview.hidden = false;
            }
            showError("");
            updatePreview();
        };
        reader.readAsDataURL(file);
    }
    if (fileInput) fileInput.addEventListener("change", () => takeFile(fileInput.files && fileInput.files[0]));
    if (drop) {
        ["dragenter", "dragover"].forEach((type) => drop.addEventListener(type, (event) => { event.preventDefault(); drop.classList.add("is-over"); }));
        ["dragleave", "drop"].forEach((type) => drop.addEventListener(type, (event) => { event.preventDefault(); drop.classList.remove("is-over"); }));
        drop.addEventListener("drop", (event) => takeFile(event.dataTransfer && event.dataTransfer.files && event.dataTransfer.files[0]));
    }

    function updatePreview() {
        preview.innerHTML = "";
        if (current === "typed" && typedText()) {
            const span = document.createElement("span");
            span.className = `wpc-typed wpc-font-${style()}`;
            span.textContent = typedText();
            preview.append(span);
        } else if (current === "drawn" && pad && !pad.isEmpty()) {
            const img = document.createElement("img");
            img.src = canvas.toDataURL("image/png");
            img.alt = "";
            preview.append(img);
        } else if (current === "image" && image) {
            const img = document.createElement("img");
            img.src = image;
            img.alt = "";
            preview.append(img);
        }
    }

    function fillUses() {
        const list = $("[data-wpc-uses]");
        list.innerHTML = "";
        tags.forEach((tag, index) => {
            const item = document.createElement("li");
            item.textContent = signaturesFormat("usePlace", index + 1, tag.dataset.label || "");
            list.append(item);
        });
        const description = $("[data-wpc-adopt-desc]");
        if (description) description.textContent = signaturesFormat(tags.length === 1 ? "adoptDescOne" : "adoptDesc", tags.length);
    }

    async function collect() {
        if (current === "typed") {
            const text = typedText();
            if (!text || text.length > (Number(config.typedMax) || 80)) throw new Error(signaturesText("typedEmpty"));
            return { method: "typed", style: style(), text };
        }
        if (current === "drawn") {
            const strokes = pad ? pad.toData() : [];
            const points = strokes.reduce((sum, stroke) => sum + (stroke.points ? stroke.points.length : 0), 0);
            if (points < 10) throw new Error(signaturesText("drawnEmpty"));
            return { method: "drawn", strokes, preview: canvas.toDataURL("image/png") };
        }
        if (!image) throw new Error(signaturesText("imageEmpty"));
        if (!ownConfirm || !ownConfirm.checked) {
            if (ownConfirm) ownConfirm.focus();
            throw new Error(signaturesText("imageConfirm"));
        }
        return { method: "image", png: image, preview: image };
    }

    const adoptButton = $("[data-wpc-adopt]");
    adoptButton.addEventListener("click", async () => {
        adoptButton.disabled = true;
        try {
            const adoption = await collect();
            dialog.close();
            onAdopt(adoption, target);
        } catch (error) {
            showError(error.message);
        } finally {
            adoptButton.disabled = false;
        }
    });
    $$("[data-wpc-adopt-close]").forEach((button) => button.addEventListener("click", () => dialog.close()));
    dialog.addEventListener("close", () => {
        if (target && !target.hidden) target.focus();
    });

    return {
        open(tag) {
            target = tag;
            showError("");
            fillUses();
            if (typeof dialog.showModal === "function") dialog.showModal();
            else dialog.setAttribute("open", "");
            selectTab(current);
            if (current === "typed" && typedInput) typedInput.focus();
        },
    };
}

function updateGrade(id) {
    const selectedSpan = document.getElementById(id);
    // Los documentos convertidos ya no tienen las opciones marcables (el grado es un campo adicional del documento)
    if (!selectedSpan) return;
    const gradeSpans = document.querySelectorAll('[id^="grade"]');
    gradeSpans.forEach((span) => { span.textContent = "( )"; });// reset all spans to blank space
    selectedSpan.textContent = "(✓)"; // set the selected span to "✓"
    gradeSelected = id;
    const notice = document.getElementById("please_select_grade");
    if (notice) notice.style.display = "none";
    const select = document.getElementById("select_grade");
    if (select) select.style.color = "#000";
}

// Campos adicionales del documento (public/templates/document-fields-form.php): las respuestas se copian
// en sessionStorage al enviar para no perderlas si se recarga antes de firmar; se borran al firmar.
// exceptKey: clave que se conserva (la del formulario actual).
function clearDocumentFieldsStorage(exceptKey = null) {
    try {
        Object.keys(sessionStorage)
            .filter((key) => key.indexOf("edusystem_document_fields_") === 0 && key !== exceptKey)
            .forEach((key) => sessionStorage.removeItem(key));
    } catch (e) {}
}

document.addEventListener("DOMContentLoaded", () => {
    const form = document.getElementById("form-document-fields");
    if (!form) return;

    const storageKey = form.dataset.storageKey;
    // En un navegador compartido no se dejan las respuestas de otro estudiante u otro documento
    clearDocumentFieldsStorage(storageKey);

    // Si el navegador restaura la página desde su caché (botón «Atrás»), el botón vuelve a estar activo
    window.addEventListener("pageshow", () => {
        form.querySelector('button[type="submit"]').disabled = false;
    });

    // Rellenar con lo guardado (salvo si el servidor devolvió errores: entonces manda lo que envió)
    if (!document.getElementById("document-fields-errors")) {
        let saved = null;
        try {
            saved = JSON.parse(sessionStorage.getItem(storageKey) || "null");
        } catch (e) {}

        if (Array.isArray(saved)) {
            form.querySelectorAll('[name^="document_fields["]').forEach((input) => {
                const values = saved.filter((pair) => pair[0] === input.name).map((pair) => pair[1]);
                if (input.type === "radio" || input.type === "checkbox") {
                    input.checked = values.includes(input.value);
                } else if (values.length) {
                    input.value = values[0];
                }
            });
        }
    }

    form.addEventListener("submit", () => {
        const pairs = [];
        new FormData(form).forEach((value, name) => {
            if (name.indexOf("document_fields[") === 0) pairs.push([name, value]);
        });
        try {
            sessionStorage.setItem(storageKey, JSON.stringify(pairs));
        } catch (e) {}
        form.querySelector('button[type="submit"]').disabled = true;
    });
});
