document.addEventListener("DOMContentLoaded", function () {
  const variableSelect = document.getElementById("variables-select");

  // El selector de variables no existe en todas las vistas (lanzaba un TypeError en la lista)
  if (variableSelect) variableSelect.addEventListener("change", function () {
    const content = this.value;
    if (!content) return;

    // Insertar en el editor
    if (typeof tinymce !== "undefined" && tinymce.get("content")) {
      tinymce.get("content").execCommand("mceInsertContent", false, content);
    } else {
      const textarea = document.getElementById("content");
      const startPos = textarea.selectionStart;
      const endPos = textarea.selectionEnd;

      textarea.value =
        textarea.value.substring(0, startPos) +
        content +
        textarea.value.substring(endPos);

      textarea.selectionStart = textarea.selectionEnd =
        startPos + content.length;
      textarea.dispatchEvent(new Event("input"));
    }

    // Resetear el selector después de la inserción
    this.value = "";
  });
});

// Campos adicionales del documento (sección «Avanzado» de document-detail.php)
document.addEventListener("DOMContentLoaded", function () {
  const table = document.getElementById("wpc-document-fields");
  const template = document.getElementById("wpc-document-field-template");
  const addButton = document.getElementById("wpc-add-document-field");
  if (!table || !template || !addButton) return;

  const optionTypes = ["radio", "checkbox", "select"];

  // Las opciones solo aplican a radio, casillas y lista
  function toggleOptions(row) {
    const type = row.querySelector(".wpc-document-field-type").value;
    row.querySelector(".wpc-document-field-options").disabled = !optionTypes.includes(type);
  }

  addButton.addEventListener("click", function () {
    // Índice único para que las filas nuevas no pisen a las existentes en fields[]
    const index = "n" + Date.now();
    const html = template.innerHTML.replace(/__INDEX__/g, index);
    table.querySelector("tbody").insertAdjacentHTML("beforeend", html);
    const row = table.querySelector("tbody tr:last-child");
    toggleOptions(row);
    row.querySelector("input").focus();
  });

  table.addEventListener("change", function (event) {
    if (event.target.classList.contains("wpc-document-field-type")) {
      toggleOptions(event.target.closest("tr"));
    }
  });

  table.addEventListener("click", function (event) {
    if (event.target.classList.contains("wpc-remove-document-field")) {
      event.target.closest("tr").remove();
    }
  });
});
