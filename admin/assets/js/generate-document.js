/**
 * WP Certificates - «Generar» un documento desde la ficha del estudiante (Admisión de EduSystem).
 *
 * Movido desde edusystem/admin/assets/js/document.js (ADR 0004 de EduSystem: todo documento configurado en
 * Certificación > Documentos lo genera wp-certificates). EduSystem solo muestra el botón; este archivo pide el
 * documento rellenado (AJAX generate_document de wp-certificates), lo muestra y genera el PDF (html2pdf).
 */
document.addEventListener("DOMContentLoaded", function () {
  const buttons_certificate = document.querySelectorAll(
    ".download-document-certificate"
  );
  buttons_certificate.forEach((button) => {
    button.addEventListener("click", function () {
      restoreButtonsCertificates(true);

      document.querySelector("input[name=document_certificate_id]").value =
        this.dataset.documentcertificate;

      // Sin ventana para elegir firma-imagen (retirada, ADR 0004): un documento que exige firma se emite para firma y el
      // servidor lo rechaza aquí
      document.getElementById("documentcertificate-button").click();
    });
  });

  function restoreButtonsCertificates(disabled = false) {
    const buttons_certificate = document.querySelectorAll(
      ".download-document-certificate"
    );
    buttons_certificate.forEach((button) => {
      button.disabled = disabled;
    });
  }

  let marginHeaderDocument = 0;
  let marginFooterDocument = 0;
  let orientation = "portrait";
  let unit = "mm";
  let paper_format = "a4";
  let widthDocument = `${210}${unit}`;
  let heightDocument = `${297}${unit}`;
  let margin = [0, 0];
  let document_certificate_button = document.getElementById(
    "documentcertificate-button"
  );
  if (document_certificate_button) {
    document_certificate_button.addEventListener("click", function () {
      let document_certificate_id = document.querySelector(
        "input[name=document_certificate_id]"
      ).value;
      let student_id = document.querySelector(
        "input[name=student_document_certificate_id]"
      ).value;

      const XHR = new XMLHttpRequest();
      XHR.open("POST", squuadCertGenerate.url, true);
      XHR.setRequestHeader("Content-type", "application/x-www-form-urlencoded");
      XHR.responseType = "json";
      XHR.send(
        "action=" +
          squuadCertGenerate.action + "&_ajax_nonce=" + squuadCertGenerate.nonce +
          "&document_certificate_id=" +
          document_certificate_id +
          "&student_id=" +
          student_id
      );

      XHR.onload = function () {
        // Rechazado por el servidor (firma no válida, documento inexistente...): se muestra el motivo
        if (XHR.status !== 200) {
          const response = this.response;
          alert((response && typeof response.data === "string" && response.data) || squuadCertGenerate.i18n.failed);
          if (typeof restoreButtonsCertificates === "function") restoreButtonsCertificates(false);
          return;
        }
        if (this.readyState == 4 && XHR.status === 200) {
          (async () => {
            const modal_body = document.getElementById("content-pdf");

            // Eliminar elementos existentes antes de cualquier inserción
            const existingHeader = document.getElementById("header-document");
            if (existingHeader) {
              existingHeader.remove();
            }
            const existingFooter = document.getElementById("footer-document");
            if (existingFooter) {
              existingFooter.remove();
            }

            if (
              modal_body &&
              this.response.header &&
              this.response.header != ""
            ) {
              // Crear y agregar nuevo header
              const headerElement = document.createElement("div");
              headerElement.id = "header-document";
              headerElement.innerHTML = this.response.header;
              modal_body.parentNode.insertBefore(headerElement, modal_body);

              // Forzar reflow y luego calcular altura
              setTimeout(() => {
                const headerCalculated =
                  document.getElementById("header-document");
                void headerCalculated.offsetHeight; // Esto fuerza un reflow
                marginHeaderDocument = Math.round(
                  (headerCalculated.offsetHeight + 10) * 0.264583333
                );
              }, 1000);
            }

            // Función simplificada sin CORS
            const convertToBase64 = async (url) => {
              try {
                // Intenta con CORS
                const img = await new Promise((resolve, reject) => {
                  const img = new Image();
                  img.crossOrigin = "Anonymous";
                  img.onload = () => resolve(img);
                  img.onerror = reject;
                  img.src = url;
                });

                const canvas = document.createElement("canvas");
                canvas.width = img.width;
                canvas.height = img.height;
                const ctx = canvas.getContext("2d");
                ctx.drawImage(img, 0, 0);
                return canvas.toDataURL();
              } catch (error) {
                // Fallback a fetch si el servidor no permite CORS
                try {
                  const response = await fetch(url);
                  const blob = await response.blob();
                  return await new Promise((resolve, reject) => {
                    const reader = new FileReader();
                    reader.onloadend = () => resolve(reader.result);
                    reader.onerror = reject;
                    reader.readAsDataURL(blob);
                  });
                } catch (fetchError) {
                  return url; // Devuelve la URL original como último recurso
                }
              }
            };

            let processedHtml = this.response.html;
            const tempDiv = document.createElement("div");
            tempDiv.innerHTML = this.response.html;
            orientation = this.response.document.orientation.toLowerCase();
            unit = this.response.document.unit.toLowerCase();
            paper_format = Array.isArray(this.response.document.paper_format)
              ? this.response.document.paper_format
              : this.response.document.paper_format.toLowerCase();
            widthDocument = `${this.response.document.width_size}${this.response.document.unit}`;
            heightDocument = `${this.response.document.height_size}${this.response.document.unit}`;

            const imgElement = tempDiv.querySelector("img");
            if (imgElement) {
              try {
                imgElement.src = await convertToBase64(imgElement.src);
                processedHtml = tempDiv.innerHTML;
              } catch (error) {}
            }

            modal_body.innerHTML = processedHtml;

            if (
              modal_body &&
              this.response.footer &&
              this.response.footer != ""
            ) {
              // Crear y agregar nuevo footer
              const footerElement = document.createElement("div");
              footerElement.id = "footer-document";
              footerElement.innerHTML = this.response.footer;
              modal_body.after(footerElement);

              // Forzar reflow y luego calcular altura
              setTimeout(() => {
                const footerCalculated =
                  document.getElementById("footer-document");
                void footerCalculated.offsetHeight; // Esto fuerza un reflow
                marginFooterDocument = Math.round(
                  (footerCalculated.offsetHeight + 10) * 0.264583333
                );
              }, 1000);
            }

            setTimeout(() => {
              margin =
                this.response.document.margin_required == 1
                  ? [marginHeaderDocument, 0, marginFooterDocument, 0]
                  : margin;
            }, 1500);

            if (document.getElementById("qrcode") && this.response.url) {
              const qrCode = new QRCodeStyling({
                width: 100,
                height: 100,
                data: this.response.url,
                image: this.response.image_url,
                dotsOptions: { color: "#000000" },
                backgroundOptions: { color: "#ffffff" },
                imageOptions: {
                  crossOrigin: "anonymous",
                },
              });

              qrCode.append(document.getElementById("qrcode"));
            }

            document.querySelector(".modal-document-export").style.minWidth =
              widthDocument;
            document.querySelector(".modal-document-export").style.minHeight =
              heightDocument;

            document.getElementById("content-pdf").style.minWidth =
              widthDocument;
            document.getElementById("content-pdf").style.minHeight =
              heightDocument;

            document.querySelector(".modal-document-export").style.padding =
              "0";

            let modal = document.getElementById("modal-grades");
            modal.style.display = "block";
            document.body.classList.add("modal-open");

            setTimeout(() => window.scrollTo(0, 0), 100);
            document.getElementById("documentcertificate-modal").style.display =
              "none";
          })();
        }
      };
    });
  }

  let download_grades = document.getElementById("download-grades");
  if (download_grades) {
    download_grades.addEventListener("click", async (e) => {
      download_grades.disabled = true;
      var element = document.getElementById("content-pdf");
      var opt = {
        margin: margin,
        filename: "document.pdf",
        image: { type: "jpeg", quality: 1 },
        jsPDF: {
          unit: unit,
          format: paper_format,
          orientation: orientation,
          hotfixes: ["px_scaling"],
        },
        html2canvas: { scrollX: 0, scrollY: 0,
          scale: 3,
          useCORS: true,
        },
        // Sin "avoid-all": estos documentos son diseños de página fija de wp-certificates y avoid-all los desplaza
        // (páginas en blanco y contenido fuera de sitio, probado en Chrome)
        pagebreak: { after: ".pagebreak" },
      };

      // Generar el PDF
      const pdf = await html2pdf().set(opt).from(element).toPdf().get("pdf");

      if (orientation == "portrait") {
        const pageCount = pdf.internal.getNumberOfPages();

        // Capturar el contenido del header
        const headerElement = document.getElementById("header-document");
        let imgDataHeader = "";
        let canvasHeader = null;
        if (headerElement) {
          canvasHeader = await html2canvas(headerElement, { scrollX: 0, scrollY: 0, scale: 2 });
          imgDataHeader = canvasHeader.toDataURL("image/jpeg");
        }

        // Capturar el contenido del footer
        const footerElement = document.getElementById("footer-document");
        let imgData = "";
        let canvas = null;
        if (footerElement) {
          canvas = await html2canvas(footerElement, { scrollX: 0, scrollY: 0, scale: 2 });
          imgData = canvas.toDataURL("image/jpeg");
        }

        // Agregar el footer manualmente
        for (let i = 1; i <= pageCount; i++) {
          pdf.setPage(i);
          const imgWidth = pdf.internal.pageSize.width; // Ancho de la imagen igual al ancho de la página

          // Header
          if (headerElement) {
            const imgHeightHeader =
              (canvasHeader.height * imgWidth) / canvasHeader.width; // Mantener la proporción
            pdf.addImage(
              imgDataHeader,
              "JPEG",
              0,
              0,
              imgWidth,
              imgHeightHeader
            );
          }

          // Footer
          if (footerElement) {
            const imgHeight = (canvas.height * imgWidth) / canvas.width; // Mantener la proporción
            const y = pdf.internal.pageSize.height - imgHeight; // Posición Y para que esté en la parte inferior
            pdf.addImage(imgData, "JPEG", 0, y, imgWidth, imgHeight);
          }
        }
      }

      // Guardar el PDF una sola vez
      pdf.save("document.pdf");
      download_grades.disabled = false; // Habilitar el botón nuevamente
    });
  }

  let close_modal_grades = document.getElementById("close-modal-grades");
  if (close_modal_grades) {
    close_modal_grades.addEventListener("click", async (e) => {
      document.getElementById("modal-grades").style.display = "none";
      document.body.classList.remove("modal-open");

      const input = document.querySelector(
        "input[name='document_certificate_id']"
      );
      const qrcode = document.getElementById("qrcode");

      if (input) input.value = "";
      if (qrcode) qrcode.innerHTML = "";

      restoreButtonsCertificates(false);

      setTimeout(() => {
        window.scrollTo(0, 0);
      }, 100);
    });
  }
});
