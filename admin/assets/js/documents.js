document.addEventListener("DOMContentLoaded", function () {
  const variableSelect = document.getElementById("variables-select");

  variableSelect.addEventListener("change", function () {
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
