let modal_close = document.querySelectorAll(".modal-close");
if (modal_close) {
  modal_close.forEach((close) => {
    close.addEventListener("click", function () {
      document.getElementById("modal-card").style.display = "none";
    });
  });
}

let qrcode = document.getElementById("qrcode");
if (qrcode) {
  let id = document.querySelector("input[name=id]").value;
  let validation_url = document.querySelector(
    "input[name=validation_url]"
  ).value;
  let image_qr_url = document.querySelector("input[name=image_qr_url]").value;
  const qrCode = new QRCodeStyling({
    width: 300,
    height: 300,
    type: "canvas",
    data: `${validation_url}verificate-certificate/${id}`,
    image: image_qr_url,
    dotsOptions: {
      color: "#000000",
      type: "rounded",
    },
    backgroundOptions: {
      color: "#f2f2f2",
    },
    imageOptions: {
      crossOrigin: "anonymous",
      margin: 0,
    },
  });
  qrCode.append(document.getElementById("qrcode"));
}

let send_request = document.getElementById("send-request");
if (send_request) {
  send_request.addEventListener("click", function () {
    document.getElementById("send-request").disabled = true;
    document.getElementById("send-request").innerText = "Loading...";
    let nacionality = document.querySelector(
      'select[name="nacionality"]'
    ).value;

    const XHR = new XMLHttpRequest();
    XHR.open("POST", `${ajax_object.ajax_url}?action=set_nacionality`, true);
    XHR.setRequestHeader("Content-type", "application/x-www-form-urlencoded");
    XHR.responseType = "text";
    let params = `action=set_nacionality`;
    if (nacionality) {
      params += `&nacionality=${nacionality}`;
    }

    XHR.send(params);
    XHR.onload = function () {
      if (XHR.status === 200) {
        location.reload();
      } else {
        document.getElementById("send-request").disabled = false;
        document.getElementById("send-request").innerText = "Save";
      }
    };
  });
}

let send_request_card = document.getElementById("send-request-card");
if (send_request_card) {
  send_request_card.addEventListener("click", function () {
    document.getElementById("send-request-card").disabled = true;
    document.getElementById("send-request-card").innerText = "Loading...";

    const XHR = new XMLHttpRequest();
    XHR.open("POST", `${ajax_object.ajax_url}?action=request_card`, true);
    XHR.setRequestHeader("Content-type", "application/x-www-form-urlencoded");
    XHR.responseType = "text";
    let params = `action=request_card`;

    XHR.send(params);
    XHR.onload = function () {
      if (XHR.status === 200) {
        location.reload();
      } else {
        document.getElementById("send-request-card").disabled = false;
        document.getElementById("send-request-card").innerText =
          "Request ID Card";
      }
    };
  });
}

let download_card = document.getElementById("download-card");
if (download_card) {
  download_card.addEventListener("click", async function () {
    const pdf = new jspdf.jsPDF({
      unit: "mm",
      format: [53.98, 85.6],
      hotfixes: ["px_scaling"],
    });

    // Capturar frente
    const frontElement = document.getElementById("main-side");
    const frontCanvas = await html2canvas(frontElement, {
      scale: 5, // Mayor resolución
      useCORS: true, // Para imágenes externas
      logging: false,
      backgroundColor: null,
    });

    // Añadir frente
    pdf.addImage(frontCanvas, "PNG", 0, 0, 53.98, 85.6);

    // Capturar reverso
    const backElement = document.getElementById("rear-side");
    const backCanvas = await html2canvas(backElement, {
      scale: 5,
      useCORS: true,
      logging: false,
      backgroundColor: null,
    });

    // Añadir reverso como nueva página
    pdf.addPage([53.98, 85.6], "portrait");
    pdf.addImage(backCanvas, "PNG", 0, 0, 53.98, 85.6);

    pdf.save("student-card.pdf");
  });
}

let share_card = document.getElementById("share-card");
if (share_card) {
  share_card.addEventListener("click", async function () {
    try {
      // Capturar el elemento
      const element = document.getElementById("main-side");
      const canvas = await html2canvas(element, {
        scale: 5,
        useCORS: true,
        logging: false,
        backgroundColor: null,
      });

      // Convertir a Blob
      canvas.toBlob(async (blob) => {
        const file = new File([blob], "student-card.png", {
          type: "image/png",
        });

        // Verificar si el navegador soporta compartir
        if (navigator.share) {
          // Mobile: Compartir usando Web Share API
          await navigator.share({
            title: "Student Card",
            files: [file],
          });
        } else {
          // Desktop: Descarga directa
          const link = document.createElement("a");
          link.href = URL.createObjectURL(blob);
          link.download = `student-card_${new Date().getTime()}.png`;
          document.body.appendChild(link);
          link.click();
          document.body.removeChild(link);
          URL.revokeObjectURL(link.href);
        }
      }, "image/png");
    } catch (error) {
      console.error("Error sharing:", error);
      // Fallback para descarga si falla el share
      if (confirm("Error al compartir. ¿Quieres descargar la imagen?")) {
        // Código de descarga aquí
      }
    }
  });
}
