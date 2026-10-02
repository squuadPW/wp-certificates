let signaturePadStudent;
let signaturePadParent;
let gradeSelected = null;
let downloading = false;
let first_time = false;
// Firma por rol (ADR 0001): quién firma aquí y qué firmas ya están guardadas
let myRole = "";
let twoSigners = false;
let otherSigned = false;
let myStoredSignature = false;
let save_signatures = null;

function signaturesText(key) {
    return (window.edusystemSignatures && edusystemSignatures.i18n && edusystemSignatures.i18n[key]) || key;
}

document.addEventListener("DOMContentLoaded", (event) => {

    if (document.getElementById("modal_open")) {
        document.body.classList.add("modal-open");
        setTimeout(() => {
            window.scrollTo(0, 0);
        }, 1000);
    }

    const closeModalEnrollment = document.getElementById("close-modal-enrollment");
    if (closeModalEnrollment) {
        closeModalEnrollment.addEventListener("click", () => {
            const modalContrasena = document.getElementById("modal-contraseña");
            const modalContent = document.getElementById("modal-content");

            if (modalContrasena) modalContrasena.style.display = "none";
            
            if (modalContent) modalContent.style.display = "none";
            
            document.body.classList.remove("modal-open");

        });
    }

    function resizeCanvas(canvasId, timmeout) {
        const canvas = document.getElementById(canvasId);
        if (canvas && !downloading) {
            const ratio = Math.max(window.devicePixelRatio || 1, 1);
            let width, height;

            setTimeout(() => {
                let multiply = canvas.parentNode
                ? canvas.parentNode.offsetWidth < 500
                    ? 0.6
                    : 0.8
                : 0.8;
                width = canvas.parentNode
                ? canvas.parentNode.offsetWidth * multiply
                : window.innerWidth;
                height = 120;

                canvas.width = width * ratio;
                canvas.height = height * ratio;
                canvas.style.width = `${width}px`;
                canvas.style.height = `${height}px`;
                canvas.getContext("2d").scale(ratio, ratio);

                loadSignatures();
            }, timmeout);
        }
    }

    // Función para detectar si es un dispositivo táctil
    function isTouchDevice() {
        return (
            "ontouchstart" in window ||
            navigator.maxTouchPoints > 0 ||
            navigator.msMaxTouchPoints > 0
        );
    }

    // Ejecutar solo en dispositivos NO táctiles
    if (!isTouchDevice()) {
        window.addEventListener("resize", function () {
            resizeCanvas("signature-student", 100);
            resizeCanvas("signature-parent", 100);
        });
    }

    // window.addEventListener("orientationchange", function () {
    //   resizeCanvas("signature-student", 0);
    //   resizeCanvas("signature-parent", 0);
    // });

    resizeCanvas("signature-student", 2500);
    resizeCanvas("signature-parent", 2500);

    // Create the SignaturePad objects after the canvas elements have been resized
    if (document.getElementById("signature-student")) {
        const studentElement = document.getElementById("signature-student");
        const parentElement = document.getElementById("signature-parent");

        // Recuadro de firma propio (signature-pad-edusystem.js), sin librerías de terceros
        if (studentElement) signaturePadStudent = new EdusystemSignaturePad(studentElement);

        if (parentElement) signaturePadParent = new EdusystemSignaturePad(parentElement);

        save_signatures = document.getElementById("saveSignatures");
        sign_here_parent = document.getElementById("sign-here-parent");
        sign_here_student = document.getElementById("sign-here-student");
        // Sin panel de firma del representante (el documento oculta su parte) se firma como si no hubiera representante
        let show_parent_info = signaturePadParent ? document.querySelector('input[name="show_parent_info"]').value : "0";

        // Cada usuario firma solo su parte, desde su propia cuenta (ADR 0001). Con dos firmantes, el recuadro del
        // otro se muestra (con su firma si ya firmó), pero no se puede usar.
        twoSigners = show_parent_info == 1 && !!signaturePadParent;
        const currentUserId = String((window.edusystemSignatures && edusystemSignatures.currentUserId) || "");
        const studentUserInput = document.querySelector('input[name="student_user_id"]');
        const parentUserInput = document.querySelector('input[name="parent_user_id"]');
        // Firmantes exigidos por el documento (ADR 0003): el recuadro de quien no firma se oculta
        const requiredInput = document.querySelector('input[name="required_roles"]');
        const requiredRoles = requiredInput ? requiredInput.value.split(",").filter(Boolean) : null;
        if (requiredRoles) {
            ["student", "parent"].forEach((role) => {
                if (!requiredRoles.includes(role)) {
                    const box = document.getElementById(`signature-pad-${role}`);
                    const square = box ? box.closest(".signature_square_field") : null;
                    if (square) square.style.display = "none";
                }
            });
            if (!(requiredRoles.includes("student") && requiredRoles.includes("parent"))) {
                twoSigners = false;
            }
        }
        if (!twoSigners) {
            const onlyParent = requiredRoles && requiredRoles.includes("parent") && !requiredRoles.includes("student");
            myRole = onlyParent && parentUserInput && currentUserId === String(parentUserInput.value) ? "parent" : "student";
        } else if (studentUserInput && currentUserId === String(studentUserInput.value)) {
            myRole = "student";
        } else if (parentUserInput && currentUserId === String(parentUserInput.value)) {
            myRole = "parent";
        }
        if (twoSigners) lockOtherSignature(myRole === "student" ? "parent" : "student");
        if (!myRole) save_signatures.disabled = true;

        document.getElementById("clear-student").addEventListener("click", () => {
            signaturePadStudent.clear();
            sign_here_student.style.display = "block";
            document.getElementById("signature-student").style.border = "1px solid gray";
            document.getElementById("signature-student").style.backgroundColor = "#ffff005c";
            refreshSaveLabel();
        });

        document.getElementById("clear-student-signature").addEventListener("click", () => {
            signaturePadStudent.clear();
            document.querySelector('input[name="auto_signature_student"]').value = 0;
            sign_here_student.style.display = "block";
            document.getElementById("signature-student").style.border = "1px solid gray";
            document.getElementById("signature-student").style.backgroundColor = "#ffff005c";

            document.getElementById("clear-student-signature").style.display = "none";
            document.getElementById("signature-text-student").style.display = "none";
            document.getElementById("signature-pad-student").style.display = "block";
            document.getElementById("clear-student").style.display = "block";
            document.getElementById("generate-signature-student").style.display = "block";
            refreshSaveLabel();
        });

        let clearParentElement = document.getElementById("clear-parent");
        if (clearParentElement) {
            clearParentElement.addEventListener("click", () => {

                signaturePadParent.clear();
                sign_here_parent.style.display = "block";
                document.getElementById("signature-parent").style.border = "1px solid gray";
                document.getElementById("signature-parent").style.backgroundColor = "#ffff005c";
                refreshSaveLabel();
            });

            document.getElementById("clear-parent-signature").addEventListener("click", () => {
                signaturePadParent.clear();
                document.querySelector('input[name="auto_signature_parent"]').value = 0;
                sign_here_parent.style.display = "block";
                document.getElementById("signature-parent").style.border = "1px solid gray";
                document.getElementById("signature-parent").style.backgroundColor = "#ffff005c";

                document.getElementById("clear-parent-signature").style.display = "none";
                document.getElementById("signature-text-parent").style.display = "none";
                document.getElementById("signature-pad-parent").style.display = "block";
                document.getElementById("clear-parent").style.display = "block";
                document.getElementById("generate-signature-parent").style.display = "block";
                refreshSaveLabel();
            });
        }

        if (signaturePadParent) {
            signaturePadParent.addEventListener("afterUpdateStroke", () => {
                refreshSaveLabel();

                if (signaturePadParent && !signaturePadParent.isEmpty()) {
                    sign_here_parent.style.display = "none";
                    document.getElementById("signature-parent").style.border = "none";
                    document.getElementById("signature-parent").style.borderBottom = "1px solid gray";
                    document.getElementById("signature-parent").style.backgroundColor = "#fff";
                } else {
                    sign_here_parent.style.display = "block";
                    document.getElementById("signature-parent").style.border = "1px solid gray";
                    document.getElementById("signature-parent").style.backgroundColor = "#ffff005c";
                }
            });
        }

        signaturePadStudent.addEventListener("afterUpdateStroke", () => {
            refreshSaveLabel();

            if (!signaturePadStudent.isEmpty()) {
                sign_here_student.style.display = "none";
                document.getElementById("signature-student").style.border = "none";
                document.getElementById("signature-student").style.borderBottom = "1px solid gray";
                document.getElementById("signature-student").style.backgroundColor = "#fff";
            } else {
                sign_here_student.style.display = "block";
                document.getElementById("signature-student").style.border = "1px solid gray";
                document.getElementById("signature-student").style.backgroundColor = "#ffff005c";
            }
        });

        save_signatures.addEventListener("click", function () {
            save_signatures.disabled = true;

            if (
                !gradeSelected &&
                document.getElementById("please_select_grade") &&
                document.getElementById("select_grade")
            ) {
                save_signatures.disabled = false;
                document.getElementById("please_select_grade").style.display = "block";
                document.getElementById("select_grade").style.color = "red";
                document.getElementById("select_grade").scrollIntoView({ behavior: "smooth" });
                alert("To proceed with your document, please select the last grade you completed");
                return;
            }

            // Ya firmó y falta el otro firmante: no hay nada más que hacer desde esta cuenta
            if (myStoredSignature && twoSigners && !otherSigned) {
                save_signatures.disabled = false;
                alert(signaturesText("waitingOther"));
                return;
            }

            if (!mySignatureReady()) {
                save_signatures.disabled = false;
                alert(signaturesText("signYourPart"));
                return;
            }

            // Consentimiento explícito (ADR 0002): obligatorio para firmar, también con la firma automática
            const consent = document.querySelector('input[name="consent_version"]');
            if (consent && !myStoredSignature && !consent.checked) {
                save_signatures.disabled = false;
                alert(signaturesText("consentRequired"));
                consent.focus();
                return;
            }

            // Con todas las firmas se genera el PDF; si falta la del otro (o de un firmante institucional), solo se
            // guarda la propia: el PDF lo generará el último firmante
            const institutionalInput = document.querySelector('input[name="institutional_pending"]');
            const institutionalPending = institutionalInput && institutionalInput.value === "1";
            if ((!twoSigners || otherSigned) && !institutionalPending) {
                generateDocEnrollment();
            } else {
                generateDocEnrollmentSend();
            }
        });
    }

    function generateDocEnrollment() {

        if (document.getElementById("please_select_grade")) {
            document.getElementById("please_select_grade").style.display = "none";
        }

        document.getElementById("clear-student").style.display = "none";
        document.getElementById("generate-signature-student").style.display = "none";
        document.getElementById("clear-student-signature").style.display = "none";

        let clearParentElement = document.getElementById("clear-parent");
        if (clearParentElement) {
            clearParentElement.style.display = "none";
            document.getElementById("clear-parent-signature").style.display = "none";
        }

        let generateSignatureParentElement = document.getElementById( "generate-signature-parent");
        if (generateSignatureParentElement) generateSignatureParentElement.style.display = "none";

        let document_id = "ENROLLMENT";
        if (document.querySelector("input[name=document_id]")) {
            document_id = document.querySelector("input[name=document_id]").value;
        }

        let document_name = null;
        if (document.querySelector("input[name=document_name]")) {
            document_name = document.querySelector("input[name=document_name]").value;
        }

        let filename = "Student Enrollment Agreement.pdf";
        if (document_name) {
            filename = `${document_name.toLowerCase()}.pdf`;
        } else if (document_id != "ENROLLMENT") {
            filename = "Student Missing Document Agreement.pdf";
        }

        downloading = true;
        var element = document.getElementById("content-pdf");
        var opt = {
            margin: [0.2, 0, 0, 0],
            filename: filename,
            image: { type: "jpeg", quality: 0.98 },
            jsPDF: { unit: "in", format: "a4", orientation: "portrait" },
            html2canvas: { scale: 3 },
            pagebreak: { mode: ["avoid-all", "css", "legacy"], after: ".pagebreak" }, // sin cortar texto entre páginas
        };

        html2pdf()
        .set(opt)
        .from(element)
        .outputPdf("blob", filename)
        .then((response) => {
            generateDocEnrollmentSend(response);
        });
    }

    function generateDocEnrollmentSend(doc = null) {
        sendSignatures(doc);
    }

    function sendSignatures(doc = null) {
        auto_signature_student = document.querySelector('input[name="auto_signature_student"]').value;
        auto_signature_parent = 0;
        if (document.querySelector('input[name="auto_signature_parent"]')) {
            auto_signature_parent = document.querySelector('input[name="auto_signature_parent"]').value;
        }

        let student_user_id = null;
        if (document.querySelector('input[name="student_user_id"]')) {
            student_user_id = document.querySelector('input[name="student_user_id"]').value;
        }

        let partner_user_id = null;
        if (document.querySelector('input[name="parent_user_id"]')) {
            partner_user_id = document.querySelector('input[name="parent_user_id"]').value;
        }

        const formData = new FormData();
        formData.append("action", "create_enrollment_document");
        formData.append("_ajax_nonce", window.edusystemSignatures ? edusystemSignatures.nonce : "");
        // Solo la firma propia (el servidor rechaza la del otro firmante), y solo si aún no está guardada
        if (!myStoredSignature) {
            if (myRole === "parent" && signaturePadParent) {
                formData.append("signature_parent", auto_signature_parent == 1 ? JSON.stringify(["automatic"]) : JSON.stringify(signaturePadParent.toData()));
            } else if (myRole === "student") {
                formData.append("signature_student", auto_signature_student == 1 ? JSON.stringify(["automatic"]) : JSON.stringify(signaturePadStudent.toData()));
            }
        }

        // Solicitud de firma (ADR 0002): el servidor deduce de ella quién firma; se envía la huella del contenido mostrado
        const requestInput = document.querySelector('input[name="request_id"]');
        const contentHashInput = document.querySelector('input[name="content_sha256"]');
        if (requestInput) formData.append("request_id", requestInput.value);
        const consentInput = document.querySelector('input[name="consent_version"]');
        if (consentInput && consentInput.checked) formData.append("consent_version", consentInput.value);
        if (contentHashInput) formData.append("content_sha256", contentHashInput.value);

        if (student_user_id) formData.append("student_user_id", student_user_id);
    
        if (partner_user_id) formData.append("partner_user_id", partner_user_id);
    
        if (gradeSelected) formData.append("grade_selected", gradeSelected);

        // Respuestas de los campos adicionales: el servidor las guarda si solo firma uno de los dos
        const documentFieldsValues = document.querySelector("input[name=document_fields_values]");
        if (documentFieldsValues) formData.append("document_fields", documentFieldsValues.value);

        let document_id = "ENROLLMENT";
        if (document.querySelector("input[name=document_id]")) {
            document_id = document.querySelector("input[name=document_id]").value;
        }

        formData.append("document_id", document_id);

        let document_name = null;
        if (document.querySelector("input[name=document_name]")) {
            document_name = document.querySelector("input[name=document_name]").value;
        }

        let filename = "Student Enrollment Agreement.pdf";
        if (document_name) {
            filename = `${document_name.toLowerCase()}.pdf`;
        } else if (document_id != "ENROLLMENT") {
            filename = "Student Missing Document Agreement.pdf";
        }

        if (doc) formData.append("document", doc, filename);

        const XHR = new XMLHttpRequest();
        XHR.open("POST", `${ajax_object.ajax_url}?action=create_enrollment_document`, true );
        XHR.send(formData); // Remove the Content-type header

        XHR.onload = function () {
            let response = null;
            try {
                response = JSON.parse(XHR.responseText);
            } catch (e) {}

            // Rechazada (sesión caducada, sin permiso, PDF o respuestas inválidas): se avisa y se puede reintentar
            if (XHR.status !== 200 || !response || !response.success) {
                const message = response && typeof response.data === "string" ? response.data : "";
                alert(message || "The document could not be saved. Please reload the page and try again.");
                const button = document.getElementById("saveSignatures");
                if (button) button.disabled = false;
                return;
            }

            if (XHR.status === 200) {
                // document.getElementById("modal-contraseña").style.display = "none";
                // document.getElementById("modal-content").style.display = "none";
                // document.body.classList.remove("modal-open");

                // Firmado: las respuestas de los campos adicionales ya están en el PDF o guardadas con la firma parcial
                clearDocumentFieldsStorage();
                // GET a la misma URL (la página pudo llegar por POST con los campos adicionales: reload() la reenviaría).
                // Sin el «#…»: con él, replace() solo movería la página al ancla sin recargarla.
                window.location.replace(window.location.href.split("#")[0]);
            }
        };
    }

    function loadSignatures() {
        const XHR = new XMLHttpRequest();
        XHR.open("POST", ajax_object.ajax_url, true);
        XHR.setRequestHeader("Content-type", "application/x-www-form-urlencoded");
        XHR.responseType = "text";
        let document_id = "ENROLLMENT";
        if (document.querySelector("input[name=document_id]")) {
            document_id = document.querySelector("input[name=document_id]").value;
        }
        const nonce = window.edusystemSignatures ? edusystemSignatures.nonce : "";
        const requestInput = document.querySelector('input[name="request_id"]');
        const requestParam = requestInput ? `&request_id=${encodeURIComponent(requestInput.value)}` : "";
        XHR.send(`action=load_signatures_data&document=${encodeURIComponent(document_id)}&_ajax_nonce=${encodeURIComponent(nonce)}${requestParam}`);
        XHR.onload = function () {
            if (XHR.status === 200) {
                let grade_selected = JSON.parse(XHR.responseText).grade_selected;

                let parent_signature = JSON.parse(XHR.responseText).parent_signature;
                if (parent_signature.length > 0 && signaturePadParent) {
                    markStoredSignature("parent");
                    if (parent_signature[0] == "automatic") {
                        document.querySelector('input[name="auto_signature_parent"]').value = 1;
                        document.getElementById("signature-text-parent").style.display = "block";
                        document.getElementById("signature-pad-parent").style.display = "none";
                        document.getElementById("clear-parent").style.display = "none";
                        document.getElementById("generate-signature-parent").style.display = "none";

                        sign_here_parent.style.display = "none";
                        document.getElementById("signature-parent").style.border = "none";
                        document.getElementById("signature-parent").style.borderBottom = "1px solid gray";
                        document.getElementById("signature-parent").style.backgroundColor = "#fff";
                    } else {
                        signaturePadParent.fromData(parent_signature);
                        signaturePadParent.off();
                        document.getElementById("clear-parent").style.display = "none";
                        document.getElementById("generate-signature-parent").style.display = "none";

                        sign_here_parent.style.display = "none";
                        document.getElementById("signature-parent").style.border = "none";
                        document.getElementById("signature-parent").style.borderBottom = "1px solid gray";
                        document.getElementById("signature-parent").style.backgroundColor = "#fff";
                    }
                }

                let student_signature = JSON.parse(XHR.responseText).student_signature;
                if (student_signature.length > 0) {
                    markStoredSignature("student");
                    if (student_signature[0] == "automatic") {
                        document.querySelector('input[name="auto_signature_student"]').value = 1;
                        document.getElementById("signature-text-student").style.display = "block";
                        document.getElementById("signature-pad-student").style.display = "none";
                        document.getElementById("clear-student").style.display = "none";
                        document.getElementById( "generate-signature-student" ).style.display = "none";

                        sign_here_student.style.display = "none";
                        document.getElementById("signature-student").style.border = "none";
                        document.getElementById("signature-student").style.borderBottom = "1px solid gray";
                        document.getElementById("signature-student").style.backgroundColor = "#fff";

                    } else {
                        signaturePadStudent.fromData(student_signature);
                        signaturePadStudent.off();
                        document.getElementById("clear-student").style.display = "none";
                        document.getElementById( "generate-signature-student" ).style.display = "none";

                        sign_here_student.style.display = "none";
                        document.getElementById("signature-student").style.border = "none";
                        document.getElementById("signature-student").style.borderBottom = "1px solid gray";
                        document.getElementById("signature-student").style.backgroundColor = "#fff";
                    }
                }

                if (grade_selected) updateGrade(grade_selected);
                refreshSaveLabel();
            }
        };
    }

    // Firma ya guardada de un firmante: la propia ya no se vuelve a enviar; la del otro permite completar el documento
    function markStoredSignature(role) {
        if (role === myRole) {
            myStoredSignature = true;
        } else {
            otherSigned = true;
            const pending = document.getElementById(`sign-here-${role}`);
            if (pending) pending.style.display = "none";
        }
    }

    // Recuadro del otro firmante: visible, sin poder dibujar ni generar la firma, con el aviso de que falta la suya
    function lockOtherSignature(role) {
        const pad = role === "parent" ? signaturePadParent : signaturePadStudent;
        if (pad) pad.off();
        [`clear-${role}`, `generate-signature-${role}`, `clear-${role}-signature`].forEach((id) => {
            const element = document.getElementById(id);
            if (element) element.style.display = "none";
        });
        const canvas = document.getElementById(`signature-${role}`);
        if (canvas) canvas.style.backgroundColor = "#f0f0f0";
        const pending = document.getElementById(`sign-here-${role}`);
        if (pending) {
            pending.style.fontSize = "14px";
            pending.style.textAlign = "center";
            pending.style.width = "90%";
            pending.textContent = signaturesText(role === "parent" ? "pendingParent" : "pendingStudent");
        }
    }

    function mySignatureReady() {
        if (myStoredSignature) return true;
        if (myRole === "parent") {
            return document.querySelector('input[name="auto_signature_parent"]').value == 1 || (signaturePadParent && !signaturePadParent.isEmpty());
        }
        if (myRole === "student") {
            return document.querySelector('input[name="auto_signature_student"]').value == 1 || !signaturePadStudent.isEmpty();
        }
        return false;
    }

    function refreshSaveLabel() {
        if (!save_signatures) return;
        save_signatures.innerHTML = mySignatureReady() && (!twoSigners || otherSigned) ? `Generate ${returnButtonTitle()}` : "Save";
    }

    function returnButtonTitle() {
        let document_id = "ENROLLMENT";
        let document_name = null;
        if (document.querySelector("input[name=document_id]")) {
            document_id = document.querySelector("input[name=document_id]").value;
        }

        if (document.querySelector("input[name=document_name]")) {
            document_name = document.querySelector("input[name=document_name]").value;
        }

        if (document_name) {
            return document_name.toLowerCase();
        } else if (document_id == "ENROLLMENT") {
            return "enrollment";
        } else {
            return "missing document";
        }
    }
});

function updateGrade(id) {
    const selectedSpan = document.getElementById(id);
    // Los documentos convertidos ya no tienen las opciones marcables (el grado es un campo adicional del documento)
    if (!selectedSpan) return;
    const gradeSpans = document.querySelectorAll('[id^="grade"]');
    gradeSpans.forEach((span) => { span.textContent = "( )"; });// reset all spans to blank space
    selectedSpan.textContent = "(✓)"; // set the selected span to "✓"
    gradeSelected = id;
    document.getElementById("please_select_grade").style.display = "none";
    document.getElementById("select_grade").style.color = "#000";
}

function autoSignature(hide, show, button_hide, clear_hide = null) {
    document.getElementById(hide).style.display = "none";
    document.getElementById(show).style.display = "block";
    document.getElementById(button_hide).style.display = "none";

    if (button_hide == "generate-signature-student") {
        document.querySelector('input[name="auto_signature_student"]').value = 1;
        document.getElementById("clear-student-signature").style.display = "block";
    } else {
        document.querySelector('input[name="auto_signature_parent"]').value = 1;
        document.getElementById("clear-parent-signature").style.display = "block";
    }

    if (clear_hide) document.getElementById(clear_hide).style.display = "none";

    const button = document.getElementById("saveSignatures");
    if (button && myRole && (!twoSigners || otherSigned)) {
        const title = document.querySelector("input[name=document_name]");
        button.innerHTML = "Generate " + (title ? title.value.toLowerCase() : (document.querySelector("input[name=document_id]") && document.querySelector("input[name=document_id]").value !== "ENROLLMENT" ? "missing document" : "enrollment"));
    }
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
